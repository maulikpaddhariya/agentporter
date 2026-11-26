<?php
/**
 * Runs ordered, idempotent schema migrations once, under a lock.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Brings the per-site schema up to {@see Schema::VERSION}.
 *
 * Concurrency: a non-blocking {@see Lock} ensures only one process migrates; others return
 * RESULT_LOCKED immediately and try again on a later request. The installed version is re-read
 * after the lock is taken (double-checked), so a process that waited on a stale read does no work.
 * The version option is advanced after each successful step, so a failed step is retried later.
 */
final class Migrator {

	public const OPTION = 'agentporter_db_version';

	public const RESULT_UP_TO_DATE = 'up_to_date';
	public const RESULT_MIGRATED   = 'migrated';
	public const RESULT_LOCKED     = 'locked';

	/**
	 * Migration steps keyed by the schema version they produce.
	 *
	 * @var array<int, callable(): void>
	 */
	private array $steps;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                             $wpdb   Database.
	 * @param Schema                            $schema Schema.
	 * @param Lock                              $lock   Migration lock.
	 * @param array<int, callable(): void>|null $steps  Override steps (tests); null uses the shipped steps.
	 */
	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema,
		private Lock $lock,
		?array $steps = null
	) {
		$this->steps = $steps ?? $this->default_steps();
		ksort( $this->steps );
	}

	/**
	 * Shipped steps. Step N must leave the schema at version N and be safe to run twice.
	 *
	 * @return array<int, callable(): void>
	 */
	public function default_steps(): array {
		return array(
			1 => array( $this, 'create_tables' ),
		);
	}

	/**
	 * Version the steps lead to.
	 */
	public function target_version(): int {
		return array() === $this->steps ? 0 : (int) max( array_keys( $this->steps ) );
	}

	/**
	 * Installed schema version, read past the object cache.
	 */
	public function installed_version(): int {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$value = get_option( self::OPTION, 0 );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Whether migrations are pending.
	 */
	public function needs_migration(): bool {
		return $this->installed_version() < $this->target_version();
	}

	/**
	 * Runs pending migrations.
	 *
	 * @return string One of the RESULT_* constants.
	 * @throws \Throwable When a step fails; the lock is released and the version is not advanced.
	 */
	public function migrate(): string {
		if ( ! $this->needs_migration() ) {
			return self::RESULT_UP_TO_DATE;
		}
		if ( ! $this->lock->acquire() ) {
			return self::RESULT_LOCKED;
		}
		try {
			$installed = $this->installed_version();
			$ran       = false;
			foreach ( $this->steps as $version => $step ) {
				if ( $version <= $installed ) {
					continue;
				}
				$step();
				update_option( self::OPTION, (string) $version, true );
				$ran = true;
			}
			return $ran ? self::RESULT_MIGRATED : self::RESULT_UP_TO_DATE;
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Step 1: create every table with dbDelta() (idempotent) and verify they exist.
	 *
	 * @throws \RuntimeException When a table could not be created.
	 */
	public function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$statements = $this->schema->create_statements();
		dbDelta( array_values( $statements ) );

		foreach ( array_keys( $statements ) as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				throw new \RuntimeException( 'AgentPorter could not create one of its database tables.' );
			}
		}
	}

	/**
	 * Whether a table exists (DESCRIBE also sees temporary tables used by the WordPress test suite).
	 *
	 * @param string $table Full table name from Schema.
	 */
	public function table_exists( string $table ): bool {
		$wpdb     = $this->wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema inspection of our own table.
		$columns = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ) );
		$wpdb->suppress_errors( $suppress );
		return is_array( $columns ) && array() !== $columns;
	}
}
