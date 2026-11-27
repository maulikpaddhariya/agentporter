<?php
/**
 * Hourly retention for the security tables.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Purges expired rate-limit counters (SECURITY-MODEL: "purged hourly") and audit rows older than the
 * retention period (30 days by default) in bounded chunks. Scheduled from admin, activation and CLI
 * requests only, never from a front-end page view.
 */
final class Maintenance {

	public const HOOK           = 'agentporter_hourly_maintenance';
	public const RETENTION_DAYS = 30;
	private const RATE_CHUNKS   = 20;
	private const CHUNK         = 5000;

	/**
	 * Constructor.
	 *
	 * @param AuditLog               $audit      Audit log.
	 * @param DatabaseRateStore|null $rate_store Database counters, or null when the object cache holds them (they expire on their own).
	 */
	public function __construct(
		private readonly AuditLog $audit,
		private readonly ?DatabaseRateStore $rate_store
	) {
	}

	/**
	 * Schedules the hourly event if it is missing.
	 */
	public static function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Removes the event (deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs one maintenance pass. Each part is bounded; a failure in one does not skip the other.
	 *
	 * @return array{rate: int, audit: int, errors: int} Rows deleted, and parts that failed (retried next run).
	 */
	public function run(): array {
		$errors = 0;
		$rate   = 0;
		if ( null !== $this->rate_store ) {
			try {
				for ( $i = 0; $i < self::RATE_CHUNKS; $i++ ) {
					$deleted = $this->rate_store->purge( time() - HOUR_IN_SECONDS, self::CHUNK );
					$rate   += $deleted;
					if ( $deleted < self::CHUNK ) {
						break;
					}
				}
			} catch ( \Throwable ) {
				++$errors; // Counters are bounded per window; the next run retries.
			}
		}
		$audit = 0;
		try {
			$audit = $this->audit->purge( self::retention_days(), self::CHUNK );
		} catch ( \Throwable ) {
			++$errors;
		}
		return array(
			'rate'   => $rate,
			'audit'  => $audit,
			'errors' => $errors,
		);
	}

	/**
	 * Audit retention in days (1–3650).
	 */
	public static function retention_days(): int {
		/**
		 * Days to keep agent audit entries.
		 *
		 * @param int $days Default 30.
		 */
		$days = apply_filters( 'agentporter_audit_retention_days', self::RETENTION_DAYS );
		return is_numeric( $days ) ? max( 1, min( 3650, (int) $days ) ) : self::RETENTION_DAYS;
	}
}
