<?php
/**
 * Plugin Name:       AgentPorter
 * Plugin URI:        https://github.com/maulikpaddhariya/agentporter
 * Description:       See, control, improve and serve AI traffic and AI agents on your WordPress and WooCommerce site.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Maulik Paddhariya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentporter
 *
 * @package AgentPorter
 */

/*
 * This file must parse on PHP 7.2 (the oldest PHP WordPress runs on) so that an outdated host sees
 * a clear notice instead of a fatal error. Keep PHP 8 syntax out of it; CI lints it with PHP 7.2.
 */

defined( 'ABSPATH' ) || exit;

// A second copy of AgentPorter is already loaded (for example a duplicate folder): do nothing.
if ( defined( 'AGENTPORTER_VERSION' ) ) {
	return;
}

define( 'AGENTPORTER_VERSION', '0.1.0' );
define( 'AGENTPORTER_FILE', __FILE__ );
define( 'AGENTPORTER_DIR', __DIR__ . '/' );

require_once __DIR__ . '/src/Core/Requirements.php';

$agentporter_requirements = \AgentPorter\Core\Requirements::from_environment();
if ( ! $agentporter_requirements->met() ) {
	// Show a notice and block activation; load nothing else.
	$agentporter_requirements->hook_failure( __FILE__ );
	unset( $agentporter_requirements );
	return;
}
unset( $agentporter_requirements );

// Release builds ship Composer's class-map autoloader built with --no-dev --classmap-authoritative.
if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'AgentPorter is incomplete: its autoloader is missing. Reinstall the plugin from WordPress.org (developers: run "composer install").', 'agentporter' )
				);
			}
		}
	);
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

\AgentPorter\Plugin::instance()->boot();
