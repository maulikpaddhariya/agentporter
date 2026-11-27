<?php
/**
 * Append-only audit log of agent calls.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

use AgentPorter\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Records who called which ability, with redacted input (≤ 4 KB), the result, a truncated IP
 * (/24 IPv4, /48 IPv6) and the duration. There is no API to edit or delete single entries; only
 * retention purges (chunked) remove rows.
 */
final class AuditLog {

	public const RESULT_ALLOWED = 1;
	public const RESULT_DENIED  = 2;
	public const RESULT_ERROR   = 3;

	private const ACTOR_TYPES = array( 'app_password', 'agent_key', 'webmcp', 'cli', 'user' );
	private const MAX_INPUT   = 4096;

	/**
	 * Constructor.
	 *
	 * @param \wpdb    $wpdb     Database.
	 * @param Schema   $schema   Schema (table names).
	 * @param Redactor $redactor Redactor.
	 */
	public function __construct( private readonly \wpdb $wpdb, private readonly Schema $schema, private readonly Redactor $redactor ) {
	}

	/**
	 * Appends an entry.
	 *
	 * @param string $actor_type  app_password, agent_key, webmcp, cli or user.
	 * @param int    $actor_id    User id or key id.
	 * @param string $ability     Ability name.
	 * @param mixed  $input       Call input (redacted here).
	 * @param int    $result      One of the RESULT_* constants.
	 * @param string $error_code  Stable error code ('' when allowed).
	 * @param string $ip          Caller IP (truncated here).
	 * @param int    $duration_ms Duration in milliseconds.
	 * @throws \RuntimeException When the row could not be written.
	 */
	public function record( string $actor_type, int $actor_id, string $ability, $input, int $result, string $error_code, string $ip, int $duration_ms ): void {
		$row = array(
			'ts'             => gmdate( 'Y-m-d H:i:s' ),
			'actor_type'     => in_array( $actor_type, self::ACTOR_TYPES, true ) ? $actor_type : 'unknown',
			'actor_id'       => max( 0, $actor_id ),
			'ability'        => substr( (string) preg_replace( '#[^a-z0-9/_-]#', '', strtolower( $ability ) ), 0, 100 ),
			'input_redacted' => $this->redactor->to_json( $input, self::MAX_INPUT ),
			'result'         => in_array( $result, array( self::RESULT_ALLOWED, self::RESULT_DENIED, self::RESULT_ERROR ), true ) ? $result : self::RESULT_ERROR,
			'error_code'     => substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $error_code ) ), 0, 64 ),
			'ip_trunc'       => self::truncate_ip( $ip ),
			'duration_ms'    => max( 0, min( $duration_ms, 4294967295 ) ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into our own table.
		$ok = $this->wpdb->insert(
			$this->schema->table( Schema::AUDIT ),
			$row,
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Audit entry could not be written.' );
		}
	}

	/**
	 * Deletes entries older than $days in chunks.
	 *
	 * @param int $days       Retention in days (≥ 1).
	 * @param int $chunk      Rows per DELETE (≤ 5000).
	 * @param int $max_chunks Upper bound on DELETE statements per run.
	 * @return int Rows deleted.
	 */
	public function purge( int $days, int $chunk = 5000, int $max_chunks = 20 ): int {
		$wpdb   = $this->wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$chunk  = max( 1, min( 5000, $chunk ) );
		$total  = 0;
		for ( $i = 0; $i < $max_chunks; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chunked retention on our own table.
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ts < %s LIMIT %d', $this->schema->table( Schema::AUDIT ), $cutoff, $chunk ) );
			$deleted = is_int( $deleted ) ? $deleted : 0;
			$total  += $deleted;
			if ( $deleted < $chunk ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Truncates an IP to /24 (IPv4) or /48 (IPv6) in packed binary form; null when invalid.
	 *
	 * @param string $ip IP address.
	 */
	public static function truncate_ip( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		$keep = 4 === strlen( $packed ) ? 3 : 6;
		return substr( $packed, 0, $keep ) . str_repeat( "\0", strlen( $packed ) - $keep );
	}
}
