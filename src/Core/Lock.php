<?php
/**
 * Cross-process mutex stored in the options table.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Non-blocking lock built on the options table's unique option_name key.
 *
 * WordPress' add_option() is deliberately not used: it writes with "INSERT … ON DUPLICATE KEY UPDATE", so two processes
 * racing past its existence check would both "acquire" the lock. Here acquisition is a single
 * INSERT IGNORE whose affected-row count says who won; a stale lock (past its expiry) is taken over
 * with a compare-and-swap UPDATE on the old value, so only one process can win that race too.
 * Release deletes the row only if it still holds our token.
 *
 * Works on MySQL, MariaDB and the WordPress SQLite integration. Lock rows are never autoloaded.
 */
final class Lock {

	/**
	 * Option name holding the lock.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Token of the lock we hold, or null.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb Database.
	 * @param string $name Lock name: lowercase letters, digits and underscores.
	 * @param int    $ttl  Seconds after which a held lock is considered stale.
	 * @throws \InvalidArgumentException For an invalid name or TTL.
	 */
	public function __construct( private \wpdb $wpdb, string $name, private int $ttl = 600 ) {
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $name ) || $ttl < 1 ) {
			throw new \InvalidArgumentException( 'Invalid lock name or TTL.' );
		}
		$this->option = 'agentporter_lock_' . $name;
	}

	/**
	 * Tries to acquire the lock without waiting.
	 *
	 * @param int|null $now Current Unix time (tests).
	 * @return bool True if this instance now holds the lock.
	 */
	public function acquire( ?int $now = null ): bool {
		$wpdb = $this->wpdb;
		if ( null !== $this->token ) {
			return true;
		}
		$now   = $now ?? time();
		$token = $this->make_token( $now );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic mutex: must hit the database, never a cache.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$wpdb->options,
				$this->option,
				$token
			)
		);
		if ( 1 === $inserted ) {
			return $this->won( $token );
		}

		// Someone holds it. Take over only if their lock has expired, and only if nobody else did first.
		$current = $this->read();
		if ( null === $current || $this->expires_at( $current ) > $now ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the stale value.
		$swapped = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$token,
				$this->option,
				$current
			)
		);
		return 1 === $swapped ? $this->won( $token ) : false;
	}

	/**
	 * Releases the lock if this instance holds it.
	 */
	public function release(): void {
		$wpdb = $this->wpdb;
		if ( null === $this->token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only our own token.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$this->option,
				$this->token
			)
		);
		$this->token = null;
		$this->flush_option_cache();
	}

	/**
	 * Whether this instance holds the lock.
	 */
	public function held(): bool {
		return null !== $this->token;
	}

	/**
	 * Option name used for the lock (tests and diagnostics).
	 */
	public function option_name(): string {
		return $this->option;
	}

	/**
	 * Records a successful acquisition.
	 *
	 * @param string $token Our token.
	 */
	private function won( string $token ): bool {
		$this->token = $token;
		$this->flush_option_cache();
		return true;
	}

	/**
	 * Reads the current lock value straight from the database.
	 */
	private function read(): ?string {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass the options cache.
		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $this->option )
		);
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Builds a token "expires_at:random".
	 *
	 * @param int $now Current Unix time.
	 */
	private function make_token( int $now ): string {
		return ( $now + $this->ttl ) . ':' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Expiry encoded in a token; malformed values count as expired.
	 *
	 * @param string $token Token.
	 */
	private function expires_at( string $token ): int {
		$parts = explode( ':', $token, 2 );
		return ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
	}

	/**
	 * Keeps the options cache consistent after direct writes.
	 */
	private function flush_option_cache(): void {
		wp_cache_delete( $this->option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
