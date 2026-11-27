<?php
/**
 * Encrypted storage for AgentPorter's own secrets.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Stores secrets (HMAC keys, webhook secrets, API tokens) encrypted with libsodium crypto_secretbox
 * (XSalsa20-Poly1305) in non-autoloaded options. WordPress ships sodium_compat, so this works on
 * every host.
 *
 * Key: AGENTPORTER_SECRET_KEY from wp-config.php if defined (any string of 32+ bytes), otherwise
 * derived with BLAKE2b from AUTH_KEY + SECURE_AUTH_SALT and a fixed context. If neither gives a real
 * key (missing or WordPress' placeholder phrase), the store refuses to work instead of using a weak key.
 *
 * Reads never throw for missing, tampered or undecryptable values: they return null, and callers that
 * own a generated secret (HMAC keys) simply create a new one. Secret values are never logged.
 */
final class SecretStore {

	private const PREFIX  = 'agentporter_secret_';
	private const VERSION = 'v1:';
	private const CONTEXT = 'agentporter-secretstore-v1';

	/**
	 * Encryption key (32 bytes) or null when no safe key is available.
	 *
	 * @var string|null
	 */
	private ?string $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_material Key material (tests); null reads wp-config constants.
	 */
	public function __construct( ?string $key_material = null ) {
		$this->key = self::derive_key( $key_material ?? self::key_material_from_config() );
	}

	/**
	 * Whether a safe key is available.
	 */
	public function is_available(): bool {
		return null !== $this->key;
	}

	/**
	 * Encrypts and stores a secret.
	 *
	 * @param string $name      Secret name: lowercase letters, digits, underscores.
	 * @param string $plaintext Secret value.
	 * @throws \RuntimeException When no safe key is available.
	 */
	public function put( string $name, string $plaintext ): void {
		$option = $this->option( $name ); // Validates the name first.
		$key    = $this->require_key();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box    = sodium_crypto_secretbox( $plaintext, $nonce, $this->bind( $key, $name ) );
		update_option( $option, self::VERSION . base64_encode( $nonce . $box ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext encoding, not obfuscation.
	}

	/**
	 * Returns a decrypted secret, or null if missing, tampered or encrypted with another key.
	 *
	 * @param string $name Secret name.
	 */
	public function get( string $name ): ?string {
		if ( null === $this->key ) {
			return null;
		}
		$stored = get_option( $this->option( $name ), null );
		if ( ! is_string( $stored ) || ! str_starts_with( $stored, self::VERSION ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::VERSION ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext decoding.
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $this->bind( $this->key, $name ) );
		} catch ( \SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * Returns a random secret of $bytes length, creating and storing it on first use.
	 *
	 * @param string $name  Secret name.
	 * @param int    $bytes Length in bytes.
	 * @throws \RuntimeException When no safe key is available.
	 */
	public function get_or_create( string $name, int $bytes = 32 ): string {
		$existing = $this->get( $name );
		if ( null !== $existing && strlen( $existing ) === $bytes ) {
			return $existing;
		}
		$secret = Crypto::random_bytes( $bytes );
		$this->put( $name, $secret );
		return $secret;
	}

	/**
	 * Deletes a secret.
	 *
	 * @param string $name Secret name.
	 */
	public function delete( string $name ): void {
		delete_option( $this->option( $name ) );
	}

	/**
	 * Option name for a secret.
	 *
	 * @param string $name Secret name.
	 * @throws \InvalidArgumentException For an invalid name.
	 */
	private function option( string $name ): string {
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid secret name.' );
		}
		return self::PREFIX . $name;
	}

	/**
	 * Per-secret key so a ciphertext copied to another secret's option fails to decrypt.
	 *
	 * @param string $key  Master key.
	 * @param string $name Secret name.
	 */
	private function bind( string $key, string $name ): string {
		return sodium_crypto_generichash( 'name:' . $name, $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Master key or exception.
	 *
	 * @throws \RuntimeException When no safe key is available.
	 */
	private function require_key(): string {
		if ( null === $this->key ) {
			throw new \RuntimeException( 'No encryption key: define AGENTPORTER_SECRET_KEY or real AUTH_KEY/SECURE_AUTH_SALT in wp-config.php.' );
		}
		return $this->key;
	}

	/**
	 * Key material from wp-config.php.
	 */
	private static function key_material_from_config(): string {
		if ( defined( 'AGENTPORTER_SECRET_KEY' ) && is_string( constant( 'AGENTPORTER_SECRET_KEY' ) ) ) {
			return 'own:' . (string) constant( 'AGENTPORTER_SECRET_KEY' );
		}
		$auth = defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '';
		$salt = defined( 'SECURE_AUTH_SALT' ) ? (string) constant( 'SECURE_AUTH_SALT' ) : '';
		foreach ( array( $auth, $salt ) as $part ) {
			if ( strlen( $part ) < 32 || str_contains( $part, 'put your unique phrase here' ) ) {
				return '';
			}
		}
		return 'wp:' . $auth . "\0" . $salt;
	}

	/**
	 * Derives a 32-byte key, or null when the material is too weak.
	 *
	 * @param string $material Key material.
	 */
	private static function derive_key( string $material ): ?string {
		$secret = (string) preg_replace( '/^(own|wp):/', '', $material );
		if ( strlen( $secret ) < 32 ) {
			return null;
		}
		return sodium_crypto_generichash( self::CONTEXT . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
