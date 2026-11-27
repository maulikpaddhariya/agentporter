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
 * Key: AGENTPORTER_SECRET_KEY from wp-config.php if defined (32+ bytes with real variety), otherwise
 * derived with BLAKE2b from AUTH_KEY + SECURE_AUTH_SALT and a fixed context. Like WordPress core, it
 * refuses salts that are missing, short, the (English or translated) sample phrase, low-entropy, or
 * repeated across salt constants: the store then refuses to work instead of using a guessable key.
 *
 * Reads never throw for missing, tampered or undecryptable values: they return null. get_or_create()
 * only creates a secret that does not exist yet; it never overwrites one it cannot decrypt (salt
 * rotation, another site's copy), because that would silently invalidate everything signed with it.
 * Secret values and the key never appear in logs, exceptions or debug dumps.
 */
final class SecretStore {

	private const PREFIX  = 'agentporter_secret_';
	private const VERSION = 'v1:';
	private const CONTEXT = 'agentporter-secretstore-v1';

	/**
	 * Encryption keys by instance. Kept outside the object's properties so var_export(), (array)
	 * casts, serialize() and debuggers that walk properties never see key material.
	 *
	 * @var \WeakMap<SecretStore, string>|null
	 */
	private static ?\WeakMap $keys = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_material Key material (tests); null reads wp-config constants.
	 */
	public function __construct( #[\SensitiveParameter] ?string $key_material = null ) {
		$key = self::derive_key( $key_material ?? self::key_material_from_config() );
		if ( null !== $key ) {
			self::keys()[ $this ] = $key;
		}
	}

	/**
	 * Key registry.
	 *
	 * @return \WeakMap<SecretStore, string>
	 */
	private static function keys(): \WeakMap {
		self::$keys ??= new \WeakMap();
		return self::$keys;
	}

	/**
	 * This instance's key, or null.
	 */
	private function key(): ?string {
		return self::keys()[ $this ] ?? null;
	}

	/**
	 * Refuses serialization: a serialized store would carry nothing useful and must never carry a key.
	 *
	 * @throws \LogicException Always.
	 */
	public function __serialize(): array {
		throw new \LogicException( 'SecretStore cannot be serialized.' );
	}

	/**
	 * Refuses unserialization.
	 *
	 * @param array<mixed> $data Data.
	 * @throws \LogicException Always.
	 */
	public function __unserialize( array $data ): void {
		throw new \LogicException( 'SecretStore cannot be unserialized.' );
	}

	/**
	 * Whether a safe key is available.
	 */
	public function is_available(): bool {
		return null !== $this->key();
	}

	/**
	 * Encrypts and stores a secret.
	 *
	 * @param string $name      Secret name: lowercase letters, digits, underscores.
	 * @param string $plaintext Secret value.
	 * @throws \RuntimeException When no safe key is available.
	 */
	public function put( string $name, #[\SensitiveParameter] string $plaintext ): void {
		$option = $this->option( $name ); // Validates the name first.
		$key    = $this->require_key();
		$nonce  = Crypto::random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box    = sodium_crypto_secretbox( $plaintext, $nonce, $this->bind( $key, $name ) );
		$value  = self::VERSION . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext encoding, not obfuscation.
		if ( ! update_option( $option, $value, false ) ) {
			throw new \RuntimeException( 'The secret could not be saved.' );
		}
	}

	/**
	 * Returns a decrypted secret, or null if missing, tampered or encrypted with another key.
	 *
	 * @param string $name Secret name.
	 */
	public function get( string $name ): ?string {
		$key = $this->key();
		if ( null === $key ) {
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
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $this->bind( $key, $name ) );
		} catch ( \SodiumException ) {
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
		$option = $this->option( $name );
		$key    = $this->require_key();
		if ( null !== get_option( $option, null ) ) {
			// A stored value we cannot read (rotated salts, copied database): never overwrite it silently.
			throw new \RuntimeException( 'A stored secret cannot be decrypted with the current key.' );
		}
		$secret = Crypto::random_bytes( $bytes );
		$nonce  = Crypto::random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$value  = self::VERSION . base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, $this->bind( $key, $name ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext encoding.
		// add_option() inserts only when absent: if another request created it first, use theirs.
		add_option( $option, $value, '', false );
		wp_cache_delete( $option, 'options' );
		$stored = $this->get( $name );
		if ( null === $stored || strlen( $stored ) !== $bytes ) {
			throw new \RuntimeException( 'The secret could not be created.' );
		}
		return $stored;
	}

	/**
	 * Never expose the key in var_dump(), print_r() or debugging tools.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'key' => null === $this->key() ? 'unavailable' : '[hidden]' );
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
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}\z/', $name ) ) {
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
	private function bind( #[\SensitiveParameter] string $key, string $name ): string {
		return sodium_crypto_generichash( 'name:' . $name, $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Master key or exception.
	 *
	 * @throws \RuntimeException When no safe key is available.
	 */
	private function require_key(): string {
		$key = $this->key();
		if ( null === $key ) {
			throw new \RuntimeException( 'No encryption key: define AGENTPORTER_SECRET_KEY or real AUTH_KEY/SECURE_AUTH_SALT in wp-config.php.' );
		}
		return $key;
	}

	/**
	 * Key material from wp-config.php.
	 */
	private static function key_material_from_config(): string {
		if ( defined( 'AGENTPORTER_SECRET_KEY' ) && is_string( constant( 'AGENTPORTER_SECRET_KEY' ) ) ) {
			$own = (string) constant( 'AGENTPORTER_SECRET_KEY' );
			return self::is_strong( $own ) ? 'own:' . $own : '';
		}
		$names  = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
		$values = array();
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$values[ $name ] = (string) constant( $name );
			}
		}
		$counts = array_count_values( $values );
		$parts  = array();
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_SALT' ) as $name ) {
			$value = $values[ $name ] ?? '';
			// WordPress core treats a salt reused across constants as insecure (pluggable.php, wp_salt()).
			if ( ! self::is_strong( $value ) || ( $counts[ $value ] ?? 0 ) > 1 ) {
				return '';
			}
			$parts[] = $value;
		}
		return 'wp:' . implode( "\0", $parts );
	}

	/**
	 * Whether a key or salt is usable: 32+ bytes, not the sample phrase (English or translated), and
	 * not low-entropy (fewer than 10 distinct bytes).
	 *
	 * @param string $value Candidate.
	 */
	public static function is_strong( #[\SensitiveParameter] string $value ): bool {
		if ( strlen( $value ) < 32 || count( count_chars( $value, 1 ) ) < 10 ) {
			return false;
		}
		$placeholders = array( 'put your unique phrase here' );
		if ( function_exists( '__' ) ) {
			$placeholders[] = __( 'put your unique phrase here' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string, as wp_salt() checks it.
		}
		foreach ( $placeholders as $placeholder ) {
			if ( '' !== $placeholder && str_contains( $value, $placeholder ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Derives a 32-byte key, or null when the material is too weak.
	 *
	 * @param string $material Key material.
	 */
	private static function derive_key( #[\SensitiveParameter] string $material ): ?string {
		$secret = (string) preg_replace( '/^(own|wp):/', '', $material );
		if ( strlen( $secret ) < 32 ) {
			return null;
		}
		return sodium_crypto_generichash( self::CONTEXT . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
