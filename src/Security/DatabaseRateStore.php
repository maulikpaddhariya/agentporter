<?php
/**
 * Rate counters in the agentporter_rate table (no persistent object cache).
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

use AgentPorter\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One row per bucket. The increment is a single UPDATE that resets the count when a new window
 * starts, so concurrent requests can't both see "new window" and lose hits. Works on MySQL,
 * MariaDB and SQLite (CASE, not IF()).
 */
final class DatabaseRateStore implements RateStore {

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb   Database.
	 * @param Schema $schema Schema (table names).
	 */
	public function __construct( private readonly \wpdb $wpdb, private readonly Schema $schema ) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bucket       Bucket key.
	 * @param int    $window_start Window start.
	 * @param int    $window       Window length.
	 * @return int
	 * @throws \RuntimeException When the table is missing or the query fails.
	 */
	public function increment( string $bucket, int $window_start, int $window ): int {
		$wpdb  = $this->wpdb;
		$table = $this->schema->table( Schema::RATE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter on our own table.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (bucket, window_start, count) VALUES (%s, %d, 0)', $table, $bucket, $window_start ) );
		// count is assigned first so its CASE sees the old window_start on every engine.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET count = CASE WHEN window_start = %d THEN count + 1 ELSE 1 END, window_start = %d WHERE bucket = %s',
				$table,
				$window_start,
				$window_start,
				$bucket
			)
		);
		$count   = $wpdb->get_var( $wpdb->prepare( 'SELECT count FROM %i WHERE bucket = %s', $table, $bucket ) );
		// phpcs:enable

		if ( false === $updated || null === $count ) {
			throw new \RuntimeException( 'Rate counter unavailable.' );
		}
		return (int) $count;
	}

	/**
	 * Deletes stale buckets in chunks (hourly job).
	 *
	 * @param int $older_than Unix time; buckets whose window started before this are removed.
	 * @param int $chunk      Rows per DELETE.
	 * @return int Rows deleted.
	 */
	public function purge( int $older_than, int $chunk = 5000 ): int {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chunked retention on our own table.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE window_start < %d LIMIT %d', $this->schema->table( Schema::RATE ), $older_than, max( 1, min( 5000, $chunk ) ) ) );
		return is_int( $deleted ) ? $deleted : 0;
	}
}
