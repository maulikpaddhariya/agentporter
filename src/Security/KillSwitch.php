<?php
/**
 * Global kill switch for agent access.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * One toggle (option) plus a wp-config constant. Read fresh on every call so turning it on takes
 * effect within the same request. Fail closed: any value other than an explicit "0" counts as ON.
 * A missing option (never toggled) is OFF; refresh() treats a database error as ON.
 */
final class KillSwitch {

	public const OPTION   = 'agentporter_kill_switch';
	public const CONSTANT = 'AGENTPORTER_DISABLE_AGENTS';

	/**
	 * Constructor.
	 *
	 * @param bool|null $forced Override for the constant (tests); null reads AGENTPORTER_DISABLE_AGENTS.
	 */
	public function __construct(
		/**
		 * Whether the constant forces the switch on (null = read the constant).
		 */
		private readonly ?bool $forced = null
	) {
	}

	/**
	 * Whether agent access is switched off.
	 */
	public function is_on(): bool {
		if ( $this->is_forced_by_constant() ) {
			return true;
		}
		return self::interpret( get_option( self::OPTION, null ) );
	}

	/**
	 * Maps a stored value to the switch state. '1', true, or anything unexpected: ON.
	 *
	 * @param mixed $value Stored option value (null/false = missing).
	 */
	private static function interpret( mixed $value ): bool {
		return ! ( null === $value || false === $value || '0' === $value || 0 === $value );
	}

	/**
	 * Whether wp-config.php forces the switch on (cannot be turned off from the admin).
	 */
	public function is_forced_by_constant(): bool {
		if ( null !== $this->forced ) {
			return $this->forced;
		}
		return defined( self::CONSTANT ) && (bool) constant( self::CONSTANT );
	}

	/**
	 * Turns the switch on or off. Callers must check capability + nonce and write an audit entry.
	 *
	 * @param bool $on New state.
	 */
	public function set( bool $on ): void {
		// Not autoloaded: kept out of the shared alloptions entry (and its known write-back race).
		update_option( self::OPTION, $on ? '1' : '0', false );
	}

	/**
	 * Re-reads the switch from the database. Long-running processes (WP-CLI, background jobs) call
	 * this before each batch so turning the switch on stops work already in progress.
	 */
	public function refresh(): bool {
		if ( $this->is_forced_by_constant() ) {
			return true;
		}
		self::forget_cached();

		// Read the row directly: get_option() trusts the 'notoptions' cache, so a process that first
		// saw the option missing would never notice the switch being turned on elsewhere.
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the options cache.
		$rows  = $wpdb->query( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::OPTION ) );
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		// Not connected, query blanked by a filter, or a DB error: the switch cannot be read, so fail closed.
		if ( false === $rows || '' !== $error ) {
			return true;
		}
		$value = ( $rows > 0 && isset( $wpdb->last_result[0]->option_value ) ) ? $wpdb->last_result[0]->option_value : null;
		$value = null === $value ? null : maybe_unserialize( $value );
		return self::interpret( $value );
	}

	/**
	 * Drops the option and its "missing" marker from the object cache so the next read hits the DB.
	 */
	private static function forget_cached(): void {
		wp_cache_delete( self::OPTION, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ self::OPTION ] ) ) {
			unset( $missing[ self::OPTION ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}
}
