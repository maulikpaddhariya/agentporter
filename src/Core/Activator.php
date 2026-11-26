<?php
/**
 * Plugin activation.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

use AgentPorter\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or upgrades the schema on activation. Idempotent: re-activation does nothing harmful.
 *
 * Network activation does not loop over every site (large networks would time out); each site
 * migrates lazily on its next admin, cron or CLI request, and new sites migrate on creation.
 */
final class Activator {

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( is_multisite() && true === $network_wide ) {
			return;
		}

		$plugin = Plugin::instance();
		try {
			$plugin->container()->get( Migrator::class )->migrate();
		} catch ( \Throwable $error ) {
			// Activation still succeeds; the next admin request retries and the fault notice explains.
			$plugin->faults()->disable( 'migrations', $error );
		}
	}
}
