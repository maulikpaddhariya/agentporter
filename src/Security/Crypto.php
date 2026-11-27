<?php
/**
 * Small, safe crypto helpers.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256, constant-time comparison and random tokens. Nothing else in the plugin should call
 * hash_hmac(), random_bytes() or compare secrets with ===.
 */
final class Crypto {

	/**
	 * Raw HMAC-SHA256.
	 *
	 * @param string $key  Key (at least 32 bytes).
	 * @param string $data Data.
	 * @throws \InvalidArgumentException When the key is too short.
	 */
	public static function hmac( #[\SensitiveParameter] string $key, string $data ): string {
		if ( strlen( $key ) < 32 ) {
			throw new \InvalidArgumentException( 'HMAC key too short.' );
		}
		return hash_hmac( 'sha256', $data, $key, true );
	}

	/**
	 * Constant-time string comparison.
	 *
	 * @param string $known Known value.
	 * @param string $user  User-supplied value.
	 */
	public static function equals( #[\SensitiveParameter] string $known, #[\SensitiveParameter] string $user ): bool {
		return hash_equals( $known, $user );
	}

	/**
	 * Cryptographically random bytes.
	 *
	 * @param int $length Number of bytes (16–1024).
	 * @throws \InvalidArgumentException For an out-of-range length.
	 */
	public static function random_bytes( int $length = 32 ): string {
		if ( $length < 16 || $length > 1024 ) {
			throw new \InvalidArgumentException( 'Invalid random length.' );
		}
		return random_bytes( $length );
	}
}
