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
 * Release deletes the row only if it still holds our token. Long-running holders call renew() between
 * steps; if the lock was taken over (the holder outlived its TTL), renew() fails and the holder must
 * stop. A lock stamped with an expiry further in the future than one TTL (clock skew, a node with a
 * wrong clock) counts as stale, so a bad clock can never block migrations forever.
 *
 * Works on MySQL, MariaDB and the WordPress SQLite integration. Lock rows are never autoloaded.
 */
final class Lock {

	/**
	 * Option name holding the lock.
	 *
	 * @var string
	 */
	private readonly string $option;

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
	public function __construct( private readonly \wpdb $wpdb, string $name, private readonly int $ttl = 600 ) {
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
	 * @throws \RuntimeException When the database refuses the write (not mere contention).
	 */
	public function acquire( ?int $now = null ): bool {
		$wpdb = $this->wpdb;
		if ( null !== $this->token ) {
			return $this->renew( $now );
		}
		$now ??= time();
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
		if ( false === $inserted ) {
			// A database error (read-only, missing privilege) must surface as a fault, not as "locked".
			throw new \RuntimeException( 'The lock could not be written.' );
		}
		if ( 1 === $inserted ) {
			return $this->won( $token );
		}

		// Someone holds it. Take over only if their lock is stale, and only if nobody else did first.
		$current = $this->read();
		if ( null === $current || ! $this->is_stale( $current, $now ) ) {
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
		return 1 === $swapped && $this->won( $token );
	}

	/**
	 * Extends a held lock by one TTL. Returns false (and forgets the token) if the lock was taken over.
	 *
	 * @param int|null $now Current Unix time (tests).
	 */
	public function renew( ?int $now = null ): bool {
		if ( null === $this->token ) {
			return false;
		}
		$wpdb  = $this->wpdb;
		$token = $this->make_token( $now ?? time() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on our own token.
		$swapped = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$token,
				$this->option,
				$this->token
			)
		);
		if ( 1 !== $swapped ) {
			$this->token = null;
			return false;
		}
		$this->token = $token;
		$this->flush_option_cache();
		return true;
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
	 * Whether a stored lock value is stale: expired, malformed, or stamped implausibly far ahead
	 * (more than one TTL plus a grace period of max(300 s, TTL), so ordinary clock drift between
	 * web nodes never lets one node steal another's live lock).
	 *
	 * @param string $value Stored value.
	 * @param int    $now   Current Unix time.
	 */
	private function is_stale( string $value, int $now ): bool {
		$expires = $this->expires_at( $value );
		$grace   = max( 300, $this->ttl );
		return $expires <= $now || $expires > $now + $this->ttl + $grace;
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
		// Only our own key; never the shared notoptions/alloptions entries (the lock is never read via get_option()).
		wp_cache_delete( $this->option, 'options' );
	}
}
