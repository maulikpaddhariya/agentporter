<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Security\Redactor;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * @covers \AgentPorter\Security\Redactor
 */
final class RedactorTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
	}

	public function test_sensitive_field_names_are_masked_at_any_depth(): void {
		$out = ( new Redactor() )->redact(
			array(
				'query'    => 'shoes',
				'password' => 'hunter2',
				'nested'   => array(
					'api_key'       => 'abc',
					'user_password' => 'x',
					'Authorization' => 'Basic xyz',
				),
			)
		);
		$this->assertSame( 'shoes', $out['query'] );
		$this->assertSame( Redactor::MASK, $out['password'] );
		$this->assertSame( Redactor::MASK, $out['nested']['api_key'] );
		$this->assertSame( Redactor::MASK, $out['nested']['user_password'] );
		$this->assertSame( Redactor::MASK, $out['nested']['Authorization'] );
	}

	public function test_sensitive_values_are_masked_inside_free_text(): void {
		$text = ( new Redactor() )->redact_string(
			'mail jane.doe@example.com call +91 98765 43210 from 203.0.113.9 or 2001:db8::1 key ap_live_ab12cd34_' . str_repeat( 'z', 40 ) . ' auth Bearer eyJhbGciOi.payload.sig hex ' . str_repeat( 'a1', 20 )
		);
		foreach ( array( 'jane.doe', 'example.com', '98765', '203.0.113.9', '2001:db8', 'ap_live_ab12', 'eyJhbGciOi', str_repeat( 'a1', 20 ) ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $text, $leak );
		}
		foreach ( array( '[email]', '[number]', '[ip]', '[agent-key]', 'Bearer [token]', '[token]' ) as $mask ) {
			$this->assertStringContainsString( $mask, $text, $mask );
		}
	}

	public function test_output_is_valid_json_within_the_byte_limit(): void {
		$huge = array_fill( 0, 500, str_repeat( 'é', 400 ) );
		$json = ( new Redactor() )->to_json( $huge, 4096 );
		$this->assertLessThanOrEqual( 4096, strlen( $json ) );
		$this->assertIsArray( json_decode( $json, true ) );
		$this->assertTrue( json_decode( $json, true )['_truncated'] );
	}

	public function test_depth_items_and_string_length_are_capped(): void {
		$deep = 'x';
		for ( $i = 0; $i < 20; $i++ ) {
			$deep = array( 'a' => $deep );
		}
		$out = ( new Redactor() )->redact( $deep );
		$this->assertStringContainsString( '[depth limit]', (string) json_encode( $out ) );

		$many = ( new Redactor() )->redact( range( 1, 150 ) );
		$this->assertSame( 50, $many['_more'] );

		$long = ( new Redactor() )->redact_string( str_repeat( 'b', 5000 ) );
		$this->assertLessThanOrEqual( 504, strlen( $long ) );
	}

	public function test_filter_can_add_fields_but_cannot_remove_defaults(): void {
		Filters\expectApplied( 'agentporter_redact_fields' )->andReturn( array( 'order_note' ) );
		$out = ( new Redactor() )->redact(
			array(
				'order_note' => 'leave at door',
				'password'   => 'p',
			)
		);
		$this->assertSame( Redactor::MASK, $out['order_note'] );
		$this->assertSame( Redactor::MASK, $out['password'] );
	}

	public function test_invalid_utf8_and_objects_are_handled(): void {
		$json    = ( new Redactor() )->to_json(
			array(
				'bad' => "\xB1\x31",
				'obj' => (object) array( 'token' => 't' ),
				'res' => fopen( 'php://memory', 'r' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test resource.
			)
		);
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( Redactor::MASK, $decoded['obj']['token'] );
		$this->assertSame( '[unsupported]', $decoded['res'] );
	}

	/**
	 * Secrets and personal data that must never survive redaction.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function leak_corpus(): array {
		// Fake credentials are assembled at runtime so no key-shaped literal is committed (secret scanning).
		$aws_id = 'AKIA' . strtoupper( substr( str_repeat( 'q7zx', 4 ), 0, 16 ) );
		$stripe = implode( '_', array( 'sk', 'live', str_repeat( 'Ab1Cd2Ef3', 3 ) ) );
		$github = implode( '_', array( 'ghp', str_repeat( 'A1b2C3d4E5', 4 ) ) );
		$aws_sk = str_repeat( 'w7Jx/K9q+Z', 4 );
		return array(
			'phone at sentence end'   => array( 'Call me at 9876543210.', '9876543210' ),
			'card at sentence end'    => array( 'card 4111111111111111.', '4111111111111111' ),
			'glued phone'             => array( 'ph9876543210 please', '9876543210' ),
			'indian mobile spaced'    => array( 'mobile +91-98765-43210 ok', '98765-43210' ),
			'aws access key'          => array( 'key ' . $aws_id . ' here', $aws_id ),
			'aws secret key'          => array( 'secret ' . $aws_sk . ' end', substr( $aws_sk, 0, 13 ) ),
			'basic auth plus slash'   => array( 'Authorization: Basic dXNlcjpwYXNz+/w== x', 'dXNlcjpwYXNz' ),
			'password in text'        => array( 'login password=hunter2 now', 'hunter2' ),
			'token in query string'   => array( 'GET /cb?access_token=abcDEF123ghiJKL456&x=1', 'abcDEF123ghiJKL456' ),
			'url userinfo'            => array( 'https://admin:s3cr3t@example.org/x', 's3cr3t' ),
			'short jwt'               => array( 'tok eyJhbGciOiJIUzI1NiJ9.eyJlIjoiYUBiLmMifQ.sig ok', 'eyJlIjoiYUBiLmMifQ' ),
			'wp application password' => array( 'use abcd EFGH 1234 ijkl MNOP 5678 to sign in', 'abcd EFGH 1234' ),
			'stripe key'              => array( $stripe . ' ok', substr( $stripe, 0, 22 ) ),
			'github token'            => array( $github, substr( $github, 0, 12 ) ),
			'idn email'               => array( 'mail müller@bücher.de now', 'müller' ),
			'prefixed db password'    => array( 'db_password=hunter2 ok', 'hunter2' ),
			'wp user_pass'            => array( 'user_pass=hunter2', 'hunter2' ),
			'colon wp_password'       => array( 'wp_password: hunter2', 'hunter2' ),
			'env upper secret'        => array( 'MY_SECRET=hunter2xyz', 'hunter2xyz' ),
			'json style pair'         => array( 'got "pwd": "hunter2" back', 'hunter2' ),
			'quoted value with space' => array( 'password="correct horse battery"', 'horse' ),
			'header api key'          => array( 'X-Api-Key: abcdef0123', 'abcdef0123' ),
			'oauth code in query'     => array( '/cb?code=Zx81kQ&state=1', 'Zx81kQ' ),
			'url password no user'    => array( 'postgres://:hunter2@db:5432/x', 'hunter2' ),
			'url token as user'       => array( 'https://' . $github . '@github.com/o/r', substr( $github, 4, 10 ) ),
			'long local part email'   => array( str_repeat( 'a', 80 ) . '@example.com', str_repeat( 'a', 70 ) ),
			'letters-only github key' => array( implode( '_', array( 'ghp', str_repeat( 'abcdEFGH', 5 ) ) ), 'abcdEFGHabcd' ),
			'letters-only openai key' => array( implode( '-', array( 'sk', 'proj', str_repeat( 'abcdEFGH', 3 ) ) ), 'abcdEFGHabcd' ),
			'token auth scheme'       => array( 'Authorization: Token abcdefgh', 'abcdefgh' ),
			'digest auth scheme'      => array( 'Authorization: Digest username="a", response="xyz"', 'xyz' ),
			'second cookie'           => array( 'Cookie: a=1; wordpress_logged_in_x=admin%7Cabc', 'admin%7Cabc' ),
			'after error prefix'      => array( 'Error: password=hunter2', 'hunter2' ),
			'after glued prefix'      => array( 'ERROR:password=hunter2', 'hunter2' ),
			'after msg prefix'        => array( 'msg:password=hunter2', 'hunter2' ),
			'prefixed after error'    => array( 'error:db_password=hunter2', 'hunter2' ),
			'token in url'            => array( 'see https://x.test/?token=abc123', 'abc123' ),
			'token in nested url'     => array( 'url=https://x.test/?access_token=SECRETvalue', 'SECRETvalue' ),
			'oauth code in redirect'  => array( 'redirect=https://x/cb?code=abcXYZ', 'abcXYZ' ),
			'single quoted pair'      => array( "'pwd': 'hunter2'", 'hunter2' ),
			'single quoted spaces'    => array( "password='hunter 2 with spaces'", 'with spaces' ),
			'unterminated double'     => array( 'password="hunter2', 'hunter2' ),
			'cut json value'          => array( '"password":"hunter2 and more', 'hunter2' ),
			'print_r dump'            => array( "Array\n(\n    [user_pass] => hunter2\n)", 'hunter2' ),
			'var_export dump'         => array( "array (\n  'user_pass' => 'hunter2',\n)", 'hunter2' ),
			'arrow separator'         => array( 'password -> hunter2', 'hunter2' ),
			'fat arrow capitalised'   => array( 'Password => hunter2', 'hunter2' ),
			'upi vpa'                 => array( 'pay bob.smith@okicici now', 'bob.smith' ),
			'us phone'                => array( 'call (555) 123-4567 today', '123-4567' ),
		);
	}

	/**
	 * @dataProvider leak_corpus
	 *
	 * @param string $input Input.
	 * @param string $leak  Fragment that must disappear.
	 */
	public function test_corpus_never_leaks( string $input, string $leak ): void {
		$this->assertStringNotContainsString( $leak, ( new Redactor() )->redact_string( $input ) );
	}

	public function test_camel_case_and_extended_keys_are_masked(): void {
		$out = ( new Redactor() )->redact(
			array(
				'accessToken'  => 'a',
				'clientSecret' => 'b',
				'newPassword'  => 'c',
				'privateKey'   => 'd',
				'cardNumber'   => 'e',
				'mobile'       => 'f',
				'otp'          => 'g',
				'credentials'  => 'h',
				'shipping'     => 'keep',
				'query'        => 'keep',
			)
		);
		foreach ( array( 'accessToken', 'clientSecret', 'newPassword', 'privateKey', 'cardNumber', 'mobile', 'otp', 'credentials' ) as $key ) {
			$this->assertSame( Redactor::MASK, $out[ $key ], $key );
		}
		$this->assertSame( 'keep', $out['shipping'] );
		$this->assertSame( 'keep', $out['query'] );
	}

	public function test_array_keys_integers_and_nested_json_are_redacted(): void {
		$json = ( new Redactor() )->to_json(
			array(
				'jane@example.com' => 'x',
				'n'                => 9876543210,
				'small'            => 42,
				'payload'          => '{"password":"hunter2","note":"call 9876543210"}',
			)
		);
		$this->assertStringNotContainsString( 'jane@example.com', $json );
		$this->assertStringNotContainsString( '9876543210', $json );
		$this->assertStringNotContainsString( 'hunter2', $json );
		$this->assertStringContainsString( '42', $json );
	}

	public function test_ordinary_text_is_left_alone(): void {
		$text = 'Note: shoes size=42 colour: blue, see https://example.org/path?q=red at 10:30:45, mac 0a:1b:2c:3d:4e:5f';
		$this->assertSame( $text, ( new Redactor() )->redact_string( $text ) );
	}

	public function test_quoted_value_split_by_truncation_does_not_leak(): void {
		$out = ( new Redactor() )->redact_string( 'password="' . str_repeat( 'S3cr', 600 ) . '"' );
		$this->assertStringNotContainsString( 'S3crS3cr', $out );
	}

	public function test_filter_fields_apply_to_free_text_pairs(): void {
		Filters\expectApplied( 'agentporter_redact_fields' )->andReturn( array( 'license' ) );
		$json = ( new Redactor() )->to_json( array( 'note' => 'license=ABC-123 ok' ) );
		$this->assertStringNotContainsString( 'ABC-123', $json );
	}

	public function test_wpnonce_csrf_refresh_and_oauth_code_keys_are_masked(): void {
		$keys = array( '_wpnonce', 'wpnonce', 'csrf_token', 'xsrf', 'refresh', 'verification_code', 'auth_code' );
		$out  = ( new Redactor() )->redact( array_fill_keys( $keys, 'v4lue' ) );
		foreach ( $keys as $key ) {
			$this->assertSame( Redactor::MASK, $out[ $key ], $key );
		}
	}

	public function test_many_long_keys_redact_within_50ms(): void {
		$wide  = array();
		$chunk = str_repeat( 'k', 2000 );
		for ( $i = 0; $i < 49; $i++ ) {
			$row = array();
			for ( $j = 0; $j < 100; $j++ ) {
				$row[ $chunk . $i . '_' . $j ] = 'v';
			}
			$wide[] = $row;
		}
		$huge_keys = array();
		for ( $j = 0; $j < 100; $j++ ) {
			$huge_keys[ str_repeat( 'q', 100000 ) . $j ] = 'v';
		}
		foreach ( array( $wide, $huge_keys ) as $i => $input ) {
			$start = microtime( true );
			( new Redactor() )->to_json( $input );
			$this->assertLessThan( 0.05, microtime( true ) - $start, "key-heavy set $i" );
		}
	}

	public function test_json_string_is_charged_its_full_length_against_the_text_budget(): void {
		$big  = (string) json_encode( array( 'x' => str_repeat( 'abcdefghij', 4000 ) ) ); // ~40 KB of JSON text.
		$json = ( new Redactor() )->to_json(
			array(
				'a' => $big,
				'b' => $big,
			) 
		);
		$this->assertStringContainsString( '"b":"[limit]"', $json, 'the first 40 KB string spent most of the 64 KB budget' );
		$huge = ( new Redactor() )->to_json( array( 'a' => (string) json_encode( array( 'x' => str_repeat( 'abcdefghij', 7000 ) ) ) ) );
		$this->assertStringNotContainsString( 'abcdefghij', $huge );
	}

	public function test_malformed_and_over_depth_json_strings_are_dropped(): void {
		$deep = '"x"';
		for ( $i = 0; $i < 12; $i++ ) {
			$deep = '{"a":' . $deep . '}';
		}
		$inputs = array(
			'unterminated' => '{"password":"hunter2"',
			'over depth'   => '{"password":"hunter2","x":' . $deep . '}',
			'array form'   => '[{"token":"hunter2"',
		);
		foreach ( $inputs as $label => $input ) {
			foreach ( array( $input, array( 'payload' => $input ), array( 'a' => array( 'b' => $input ) ) ) as $data ) {
				$json = ( new Redactor() )->to_json( $data );
				$this->assertStringNotContainsString( 'hunter2', $json, $label );
				$this->assertStringContainsString( '[unparsed-json]', $json, $label );
			}
		}
		// Log lines that merely start with a bracket are still redacted as text, not dropped.
		$this->assertSame( '[INFO] password=[redacted]', ( new Redactor() )->redact_string( '[INFO] password=hunter2' ) );
		$this->assertSame( '[INFO] password=[redacted]', ( new Redactor() )->redact( '[INFO] password=hunter2' ) );
	}

	public function test_integer_keys_and_floats_with_long_digit_runs_are_masked(): void {
		$json = ( new Redactor() )->to_json(
			array(
				'4111111111111111' => 'x',
				'5500000000000004' => 'y',
				'7'                => 'keep',
				'f'                => 919876543210.0,
				'price'            => 19.99,
				'inf'              => INF,
			)
		);
		$this->assertStringNotContainsString( '4111111111111111', $json );
		$this->assertStringNotContainsString( '5500000000000004', $json );
		$this->assertStringNotContainsString( '9198765', $json );
		$this->assertStringContainsString( '19.99', $json );
		$this->assertStringContainsString( '"7":"keep"', $json );
	}

	public function test_short_sensitive_field_names_are_masked(): void {
		$keys = array( 'pw', 'passcode', 'userpass', 'dbpass', 'sessionid', 'cardnumber', 'phonenumber', 'PHPSESSID' );
		$out  = ( new Redactor() )->redact( array_fill_keys( $keys, 'hunter2' ) );
		foreach ( $keys as $key ) {
			$this->assertSame( Redactor::MASK, $out[ $key ], $key );
		}
	}

	public function test_large_inputs_redact_within_50ms(): void {
		$letters = array_fill( 0, 100, str_repeat( 'abcdefghij', 200 ) );
		$tokens  = array_fill( 0, 100, str_repeat( 'Ab1/', 500 ) );
		$emails  = array_fill( 0, 100, str_repeat( 'a', 1000 ) . '@' . str_repeat( 'a-', 499 ) );
		$pairs   = array_fill( 0, 100, str_repeat( 'a_', 1000 ) );
		$huge    = array_fill( 0, 5000, str_repeat( 'abcdefghij', 200 ) );
		foreach ( array( $letters, $tokens, $emails, $pairs, $huge ) as $i => $input ) {
			$start = microtime( true );
			( new Redactor() )->to_json( $input );
			$this->assertLessThan( 0.05, microtime( true ) - $start, "input set $i (SECURITY-MODEL §8: < 50 ms)" );
		}
	}

	public function test_adversarial_inputs_finish_quickly(): void {
		$inputs = array(
			str_repeat( '1 ', 1000 ),
			str_repeat( 'a@', 1000 ),
			str_repeat( ':', 2000 ),
			str_repeat( 'aaaa ', 400 ),
			str_repeat( 'eyJ.', 500 ),
			str_repeat( '+1(', 666 ),
			'https://' . str_repeat( 'a:', 990 ),
			str_repeat( 'password=', 220 ),
			str_repeat( 'abcdefghij', 200 ),
			str_repeat( 'a', 1999 ) . '@',
			str_repeat( 'a_', 1000 ),
			str_repeat( 'k:"', 666 ),
			str_repeat( 'x="\\', 500 ),
		);
		foreach ( $inputs as $input ) {
			$start = microtime( true );
			( new Redactor() )->redact_string( $input );
			$this->assertLessThan( 0.05, microtime( true ) - $start, 'ReDoS budget (SECURITY-MODEL §8: < 50 ms).' );
		}
	}
}
