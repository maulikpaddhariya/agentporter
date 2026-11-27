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
 * history. Idempotent. Removes only what AgentPorter created.
 */
final class Uninstaller {

	public const OPT_IN_OPTION = 'agentporter_delete_data_on_uninstall';

	private const SITES_PER_BATCH = 100;

	/**
	 * Uninstalls on every site (multisite) or the single site.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}
		$offset = 0;
		do {
			$ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => self::SITES_PER_BATCH,
					'offset'                 => $offset,
					'update_site_meta_cache' => false,
				)
			);
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try {
					self::uninstall_site();
				} finally {
					restore_current_blog();
				}
			}
			$offset += self::SITES_PER_BATCH;
			$batch   = count( $ids );
		} while ( self::SITES_PER_BATCH === $batch );

		if ( '1' === get_site_option( self::OPT_IN_OPTION ) ) {
			delete_site_option( self::OPT_IN_OPTION );
		}
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

		// Every option AgentPorter creates starts with "agentporter_" (settings, secrets, locks, versions).
		$like = $wpdb->esc_like( 'agentporter_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of our own options.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT 1000', $wpdb->options, $like ) );
		foreach ( $names as $name ) {
			delete_option( (string) $name );
		}
		return true;
	}
}
