<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Security\SecretStore;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Separate-process tests define the WordPress/AgentPorter key constants under test.

/**
 * @covers \AgentPorter\Security\SecretStore
 * @covers \AgentPorter\Security\Crypto
 */
final class SecretStoreTest extends TestCase {

	/**
	 * In-memory options: name => [value, autoload].
	 *
	 * @var array<string, array{0: mixed, 1: mixed}>
	 */
	private array $options = array();

	protected function set_up(): void {
		parent::set_up();
		$this->options = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = array( $value, $autoload );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ][0] ?? $default );
		Functions\when( 'add_option' )->alias(
			function ( $name, $value, $deprecated = '', $autoload = null ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = array( $value, $autoload );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	private function store( string $material = 'own:0123456789abcdef0123456789abcdef-test-key' ): SecretStore {
		return new SecretStore( $material );
	}

	public function test_weak_or_missing_key_material_refuses_to_work(): void {
		foreach ( array( '', 'own:short', 'wp:' ) as $material ) {
			$store = $this->store( $material );
			$this->assertFalse( $store->is_available() );
			$this->assertNull( $store->get( 'x' ) );
			try {
				$store->put( 'x', 'secret' );
				$this->fail( 'Expected refusal for ' . $material );
			} catch ( \RuntimeException $e ) {
				$this->assertStringNotContainsString( 'secret', $e->getMessage() );
			}
		}
	}

	public function test_tampered_ciphertext_reads_as_missing(): void {
		$store = $this->store();
		$store->put( 'webhook', 'top-secret' );
		$value                     = $this->options['agentporter_secret_webhook'][0];
		$raw                       = base64_decode( substr( $value, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->options['agentporter_secret_webhook'][0] = 'v1:' . base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertNull( $store->get( 'webhook' ) );
	}

	public function test_garbage_values_read_as_missing(): void {
		$store = $this->store();
		foreach ( array( 'plain', 'v1:', 'v1:!!!', 'v1:' . base64_encode( 'short' ), array( 'x' ) ) as $garbage ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$this->options['agentporter_secret_x'] = array( $garbage, false );
			$this->assertNull( $store->get( 'x' ) );
		}
	}

	public function test_other_key_cannot_decrypt(): void {
		$this->store()->put( 'hmac', 'value' );
		$this->assertNull( $this->store( 'own:a-completely-different-key-material-123456' )->get( 'hmac' ) );
	}

	public function test_ciphertext_copied_to_another_name_fails(): void {
		$store = $this->store();
		$store->put( 'cart', 'value' );
		$this->options['agentporter_secret_webhook'] = $this->options['agentporter_secret_cart'];
		$this->assertNull( $store->get( 'webhook' ) );
	}

	public function test_invalid_names_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->store()->put( '../../evil', 'x' );
	}

	public function test_round_trip_is_encrypted_random_and_not_autoloaded(): void {
		$store = $this->store();
		$store->put( 'webhook', 'top-secret' );
		[ $first, $autoload ] = $this->options['agentporter_secret_webhook'];
		$this->assertFalse( $autoload );
		$this->assertStringNotContainsString( 'top-secret', $first );
		$this->assertSame( 'top-secret', $store->get( 'webhook' ) );

		$store->put( 'webhook', 'top-secret' );
		$this->assertNotSame( $first, $this->options['agentporter_secret_webhook'][0], 'Fresh nonce per write.' );
	}

	public function test_get_or_create_generates_once(): void {
		$store  = $this->store();
		$secret = $store->get_or_create( 'cart_hmac', 32 );
		$this->assertSame( 32, strlen( $secret ) );
		$this->assertSame( $secret, $store->get_or_create( 'cart_hmac', 32 ) );
		$store->delete( 'cart_hmac' );
		$this->assertNotSame( $secret, $store->get_or_create( 'cart_hmac', 32 ) );
	}

	public function test_no_key_constants_yields_no_key(): void {
		$this->assertFalse( ( new SecretStore() )->is_available() );
	}

	/**
	 * Distinct, strong values for every WordPress key and salt constant.
	 *
	 * @return array<string, string>
	 */
	private static function salts(): array {
		$out = array();
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $i => $name ) {
			$out[ $name ] = hash( 'sha256', 'salt-' . $name ) . '#' . $i;
		}
		return $out;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_is_derived_from_auth_key_and_secure_auth_salt(): void {
		$salts = self::salts();
		foreach ( $salts as $name => $value ) {
			define( $name, $value );
		}
		$store = new SecretStore();
		$this->assertTrue( $store->is_available() );
		$store->put( 'probe', 'value' );
		$this->assertSame( 'value', $this->store( 'wp:' . $salts['AUTH_KEY'] . "\0" . $salts['SECURE_AUTH_SALT'] )->get( 'probe' ) );
		$this->assertNull( $this->store( 'wp:' . $salts['AUTH_KEY'] . "\0" . $salts['NONCE_SALT'] )->get( 'probe' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_duplicated_wp_salts_are_refused(): void {
		$salts              = self::salts();
		$salts['NONCE_KEY'] = $salts['AUTH_KEY']; // Reused anywhere, as wp_salt() treats it: insecure.
		foreach ( $salts as $name => $value ) {
			define( $name, $value );
		}
		$this->assertFalse( ( new SecretStore() )->is_available() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_placeholder_wp_salts_are_refused(): void {
		Functions\when( '__' )->returnArg();
		define( 'AUTH_KEY', 'put your unique phrase here' );
		define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
		$this->assertFalse( ( new SecretStore() )->is_available() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_weak_agentporter_secret_key_does_not_fall_back_to_salts(): void {
		foreach ( self::salts() as $name => $value ) {
			define( $name, $value );
		}
		define( 'AGENTPORTER_SECRET_KEY', 'too-short' );
		$this->assertFalse( ( new SecretStore() )->is_available() );
	}

	public function test_var_export_cast_and_serialize_never_expose_the_key(): void {
		$material = 'own:0123456789abcdef0123456789abcdef-test-key';
		$store    = $this->store( $material );
		$key      = sodium_crypto_generichash( "agentporter-secretstore-v1\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$this->assertTrue( $store->is_available() );
		$this->assertSame( array(), (array) $store );
		$this->assertStringNotContainsString( $key, var_export( $store, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Asserting what debug output would leak.
		$this->expectException( \LogicException::class );
		serialize( $store ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Asserting serialization is refused.
	}

	public function test_clone_carries_no_key_and_fails_closed(): void {
		$clone = clone $this->store();
		$this->assertFalse( $clone->is_available() );
	}

	public function test_get_or_create_never_overwrites_an_undecryptable_secret(): void {
		$this->store()->get_or_create( 'cart_hmac' );
		$before = $this->options['agentporter_secret_cart_hmac'][0];
		try {
			$this->store( 'own:another-key-after-rotation-0123456789abcdefXYZ' )->get_or_create( 'cart_hmac' );
			$this->fail( 'Expected refusal.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( $before, $this->options['agentporter_secret_cart_hmac'][0] );
		}
	}

	public function test_concurrent_first_use_converges_on_one_secret(): void {
		$store = $this->store();
		// Another request created the secret between our read and our insert: add_option() keeps theirs.
		$theirs = $this->store()->get_or_create( 'race' );
		$this->assertSame( $theirs, $store->get_or_create( 'race' ) );
	}

	public function test_put_throws_when_the_option_cannot_be_written(): void {
		Functions\when( 'update_option' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->store()->put( 'x', 'v' );
	}

	public function test_weak_values_are_not_strong_keys(): void {
		$this->assertFalse( SecretStore::is_strong( str_repeat( '0', 64 ) ), 'Low entropy.' );
		$this->assertFalse( SecretStore::is_strong( 'short' ) );
		$this->assertFalse( SecretStore::is_strong( 'xx put your unique phrase here xx 0123456789' ) );
		$this->assertTrue( SecretStore::is_strong( 'F7#kq9!Lm2@Zp4$Wx8^Rt6&Yb1*Nc3(Vd5)' ) );
	}

	public function test_translated_placeholder_is_rejected(): void {
		Functions\when( '__' )->alias( static fn( $text ) => 'mettez votre phrase unique ici' === $text ? $text : ( 'put your unique phrase here' === $text ? 'mettez votre phrase unique ici' : $text ) );
		$this->assertFalse( SecretStore::is_strong( 'mettez votre phrase unique ici 0123456789ABCDEF' ) );
	}

	public function test_debug_output_never_contains_the_key(): void {
		$dump = print_r( $this->store(), true );
		$this->assertStringContainsString( '[hidden]', $dump );
		$this->assertStringNotContainsString( 'test-key', $dump );
	}
}
