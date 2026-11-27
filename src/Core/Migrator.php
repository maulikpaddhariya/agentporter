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
 * RESULT_LOCKED immediately and try again on a later request. The cheap "anything to do?" check uses
 * the normal cached option (it never evicts the shared options cache); after the lock is taken the
 * version is read straight from the database, so a process that saw a stale value does no work.
 * The lease is renewed before every step and before the version is advanced; a holder that lost its
 * lease stops without advancing the version. A failed step is retried later, after a back-off.
 */
final class Migrator {

	public const OPTION = 'agentporter_db_version';

	public const RESULT_UP_TO_DATE  = 'up_to_date';
	public const RESULT_MIGRATED    = 'migrated';
	public const RESULT_LOCKED      = 'locked';
	public const RESULT_BACKING_OFF = 'backing_off';

	public const RETRY_OPTION = 'agentporter_migration_retry_at';
	public const BACKOFF      = 900;

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
	 * Installed schema version as WordPress currently sees it (cached; cheap on every request).
	 */
	public function installed_version(): int {
		$value = get_option( self::OPTION, 0 );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Installed schema version read straight from the database (used only while holding the lock).
	 */
	private function stored_version(): int {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authoritative read under the lock; caches may be stale.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
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
	 * @param bool $force Ignore the back-off after a recent failure (activation, network upgrade, CLI).
	 * @return string One of the RESULT_* constants.
	 * @throws \Throwable When a step fails or the lease is lost; the lock is released, the version is
	 *                    not advanced and a back-off is recorded.
	 */
	public function migrate( bool $force = false ): string {
		if ( ! $this->needs_migration() ) {
			return self::RESULT_UP_TO_DATE;
		}
		if ( ! $force && (int) get_option( self::RETRY_OPTION, 0 ) > time() ) {
			return self::RESULT_BACKING_OFF;
		}
		if ( ! $this->lock->acquire() ) {
			return self::RESULT_LOCKED;
		}
		try {
			$installed = $this->stored_version();
			$ran       = false;
			foreach ( $this->steps as $version => $step ) {
				if ( $version <= $installed ) {
					continue;
				}
				$this->keep_lease();
				$step();
				$this->keep_lease();
				update_option( self::OPTION, (string) $version, true );
				$ran = true;
			}
			if ( $ran ) {
				delete_option( self::RETRY_OPTION );
			}
			return $ran ? self::RESULT_MIGRATED : self::RESULT_UP_TO_DATE;
		} catch ( \Throwable $error ) {
			update_option( self::RETRY_OPTION, (string) ( time() + self::BACKOFF ), false );
			throw $error;
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Renews the lease or stops: another process took over after our TTL ran out.
	 *
	 * @throws \RuntimeException When the lease was lost.
	 */
	private function keep_lease(): void {
		if ( ! $this->lock->renew() ) {
			throw new \RuntimeException( 'Migration lock lost; another process is migrating.' );
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

		// Never print SQL errors (and paths) to a visitor, even with WP_DEBUG_DISPLAY on.
		$suppress = $this->wpdb->suppress_errors( true );
		try {
			dbDelta( array_values( $statements ) );
		} finally {
			$this->wpdb->suppress_errors( $suppress );
		}

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
