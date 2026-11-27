<?php
/**
 * Removes AgentPorter's data when the owner opted in.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Called only from uninstall.php (behind the WP_UNINSTALL_PLUGIN guard; WordPress requires the
 * delete_plugins capability to get there). Without the opt-in, data is kept so a reinstall keeps
 * history. Idempotent.
 *
 * Removes only what the free plugin owns: its tables and the option names/prefixes listed in
 * OWNED_PREFIXES. The Pro add-on's "agentporter_pro_" options are left for Pro's own uninstall.
 *
 * On multisite it covers the current network only, and reads each site's opt-in with one direct
 * query instead of switching to (and loading the options of) every site; it switches only to sites
 * that opted in, and frees the runtime cache between batches.
 */
final class Uninstaller {

	public const OPT_IN_OPTION = 'agentporter_delete_data_on_uninstall';

	/**
	 * Option name prefixes owned by the free plugin. Every option it creates must start with one.
	 */
	public const OWNED_PREFIXES = array(
		'agentporter_settings',
		'agentporter_db_version',
		'agentporter_migration_retry_at',
		'agentporter_kill_switch',
		'agentporter_abilities_enabled',
		'agentporter_business_facts',
		'agentporter_bot_rules',
		'agentporter_registry_version',
		'agentporter_ip_ranges',
		'agentporter_onboarding',
		'agentporter_lock_',
		'agentporter_secret_',
		'agentporter_delete_data_on_uninstall',
	);

	private const SITES_PER_BATCH  = 100;
	private const OPTIONS_PER_PASS = 500;

	/**
	 * Uninstalls on every site of the current network (multisite) or the single site.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}
		global $wpdb;
		$network_opt_in = '1' === get_site_option( self::OPT_IN_OPTION );
		$offset         = 0;
		do {
			$ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => self::SITES_PER_BATCH,
					'offset'                 => $offset,
					'network_id'             => get_current_network_id(),
					'update_site_meta_cache' => false,
				)
			);
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( ! $network_opt_in && ! self::site_opted_in( $wpdb, $id ) ) {
					continue;
				}
				switch_to_blog( $id );
				try {
					self::uninstall_site();
				} finally {
					restore_current_blog();
				}
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			$offset += self::SITES_PER_BATCH;
			$batch   = count( $ids );
		} while ( self::SITES_PER_BATCH === $batch );

		if ( $network_opt_in ) {
			delete_site_option( self::OPT_IN_OPTION );
		}
	}

	/**
	 * Reads one site's opt-in without switching to it.
	 *
	 * @param \wpdb $wpdb    Database.
	 * @param int   $blog_id Site id.
	 */
	private static function site_opted_in( \wpdb $wpdb, int $blog_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off read during uninstall; avoids loading every site's options.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->get_blog_prefix( $blog_id ) . 'options', self::OPT_IN_OPTION ) );
		return '1' === $value;
	}

	/**
	 * Uninstalls on the current site if the site (or the network) opted in.
	 *
	 * @return bool Whether data was deleted.
	 */
	public static function uninstall_site(): bool {
		$opted_in = '1' === get_option( self::OPT_IN_OPTION ) || ( is_multisite() && '1' === get_site_option( self::OPT_IN_OPTION ) );
		if ( ! $opted_in ) {
			return false;
		}

		global $wpdb;
		foreach ( Schema::suffixes() as $suffix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping our own tables on uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $suffix ) );
		}

		foreach ( self::OWNED_PREFIXES as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of our own options, in bounded passes.
				$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT %d', $wpdb->options, $like, self::OPTIONS_PER_PASS ) );
				foreach ( $names as $name ) {
					delete_option( (string) $name );
				}
				$pass = count( $names );
			} while ( self::OPTIONS_PER_PASS === $pass );
		}
		return true;
	}
}
