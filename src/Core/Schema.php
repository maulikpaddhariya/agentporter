<?php
/**
 * Database schema (ARCHITECTURE.md §7.1).
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned definitions of AgentPorter's own tables, in dbDelta() format.
 *
 * Rules: own tables only; additive changes within a major version (downgrade-safe); SQL limited to
 * what MySQL 5.7+, MariaDB 10.4+ and the WordPress SQLite integration accept; table names come from
 * code constants only. Bump VERSION and add a Migrator step for every change.
 */
final class Schema {

	/**
	 * Current schema version.
	 */
	public const VERSION = 1;

	/**
	 * Table suffixes (without $wpdb->prefix).
	 */
	public const DAILY       = 'agentporter_daily';
	public const PAGE_DAILY  = 'agentporter_page_daily';
	public const EVENTS      = 'agentporter_events';
	public const IP_VERDICTS = 'agentporter_ip_verdicts';
	public const AUDIT       = 'agentporter_audit';
	public const RATE        = 'agentporter_rate';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {
	}

	/**
	 * Full table name for a suffix constant.
	 *
	 * @param string $suffix One of the class constants.
	 * @throws \InvalidArgumentException For an unknown suffix.
	 */
	public function table( string $suffix ): string {
		if ( ! in_array( $suffix, self::suffixes(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown AgentPorter table.' );
		}
		return $this->wpdb->prefix . $suffix;
	}

	/**
	 * All table suffixes.
	 *
	 * @return list<string>
	 */
	public static function suffixes(): array {
		return array( self::DAILY, self::PAGE_DAILY, self::EVENTS, self::IP_VERDICTS, self::AUDIT, self::RATE );
	}

	/**
	 * Character set for our own tables: utf8mb4 whenever the server supports it, regardless of the
	 * site's DB_CHARSET, so an agent's 4-byte input can never make an audit or rate write fail.
	 * We never join our text columns with core tables, so a differing collation is harmless.
	 */
	public function charset_collate(): string {
		if ( $this->wpdb->has_cap( 'utf8mb4' ) ) {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * CREATE TABLE statements for dbDelta(), keyed by full table name.
	 *
	 * @return array<string, string>
	 */
	public function create_statements(): array {
		$collate = $this->charset_collate();
		$engine  = 'ENGINE=InnoDB';

		$sql = array();

		// Aggregate counters per day (kind: bot_hit, blocked, referral, agent_call).
		$sql[ $this->table( self::DAILY ) ] = "CREATE TABLE {$this->table( self::DAILY )} (
  day date NOT NULL,
  kind tinyint(3) unsigned NOT NULL,
  source_id smallint(5) unsigned NOT NULL,
  verified tinyint(3) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,kind,source_id,verified)
) {$engine} {$collate};";

		// Top pages per source per day.
		$sql[ $this->table( self::PAGE_DAILY ) ] = "CREATE TABLE {$this->table( self::PAGE_DAILY )} (
  day date NOT NULL,
  kind tinyint(3) unsigned NOT NULL,
  source_id smallint(5) unsigned NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  path_hash binary(16) NOT NULL,
  path varchar(255) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,kind,source_id,path_hash),
  KEY object_id (object_id)
) {$engine} {$collate};";

		// Recent raw events (7 days, capped, sampled). ip only for bots; ip_hash (daily salted HMAC) for humans.
		$sql[ $this->table( self::EVENTS ) ] = "CREATE TABLE {$this->table( self::EVENTS )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL,
  kind tinyint(3) unsigned NOT NULL,
  source_id smallint(5) unsigned NOT NULL DEFAULT 0,
  ip varbinary(16) NULL DEFAULT NULL,
  ip_hash binary(16) NULL DEFAULT NULL,
  path varchar(255) NOT NULL DEFAULT '',
  status smallint(5) unsigned NOT NULL DEFAULT 0,
  verdict tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY kind_ts (kind,ts)
) {$engine} {$collate};";

		// Bot IP verification cache (7 days).
		$sql[ $this->table( self::IP_VERDICTS ) ] = "CREATE TABLE {$this->table( self::IP_VERDICTS )} (
  ip varbinary(16) NOT NULL,
  bot_id smallint(5) unsigned NOT NULL DEFAULT 0,
  verdict tinyint(3) unsigned NOT NULL DEFAULT 0,
  checked_at datetime NOT NULL,
  PRIMARY KEY  (ip),
  KEY checked_at (checked_at)
) {$engine} {$collate};";

		// Every agent/ability call. input_redacted is JSON with PII masked, at most 4 KB.
		$sql[ $this->table( self::AUDIT ) ] = "CREATE TABLE {$this->table( self::AUDIT )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL,
  actor_type varchar(20) NOT NULL DEFAULT '',
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ability varchar(100) NOT NULL DEFAULT '',
  input_redacted longtext NULL,
  result tinyint(3) unsigned NOT NULL DEFAULT 0,
  error_code varchar(64) NOT NULL DEFAULT '',
  ip_trunc varbinary(16) NULL DEFAULT NULL,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY ability_ts (ability,ts),
  KEY actor (actor_type,actor_id)
) {$engine} {$collate};";

		// Rate-limit counters when no persistent object cache is available.
		$sql[ $this->table( self::RATE ) ] = "CREATE TABLE {$this->table( self::RATE )} (
  bucket varchar(80) NOT NULL,
  window_start int(10) unsigned NOT NULL DEFAULT 0,
  count int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket)
) {$engine} {$collate};";

		return $sql;
	}
}
