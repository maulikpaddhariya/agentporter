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

// A second copy of AgentPorter is already loaded (for example a duplicate folder): load nothing,
// tell plugin managers which copies clash, and refuse to activate this one.
if ( defined( 'AGENTPORTER_VERSION' ) ) {
	if ( defined( 'AGENTPORTER_FILE' ) && AGENTPORTER_FILE !== __FILE__ ) {
		// Build the message lazily: translations must not load before `init`.
		$agentporter_duplicate_message = static function () {
			return sprintf(
				/* translators: 1: folder of the copy that is running, 2: folder of the duplicate copy. */
				__( 'Two copies of AgentPorter are installed. "%1$s" is running and "%2$s" was not loaded. Delete the copy you do not use so the right version runs.', 'agentporter' ),
				basename( dirname( AGENTPORTER_FILE ) ),
				basename( __DIR__ )
			);
		};
		$agentporter_duplicate_notice  = static function () use ( $agentporter_duplicate_message ) {
			agentporter_print_plugins_screen_notice( $agentporter_duplicate_message() );
		};
		add_action( 'admin_notices', $agentporter_duplicate_notice );
		add_action( 'network_admin_notices', $agentporter_duplicate_notice );
		register_activation_hook(
			__FILE__,
			static function () use ( $agentporter_duplicate_message ) {
				wp_die( esc_html( $agentporter_duplicate_message() ), '', array( 'back_link' => true ) );
			}
		);
		unset( $agentporter_duplicate_message, $agentporter_duplicate_notice );
	}
	return;
}

if ( ! function_exists( 'agentporter_print_plugins_screen_notice' ) ) {
	/**
	 * Prints an error notice on the Plugins and Dashboard screens for users who can manage plugins.
	 * Kept PHP 7.2-compatible because it runs before the version check.
	 *
	 * @param string $message Plain-text message (escaped here).
	 * @return void
	 */
	function agentporter_print_plugins_screen_notice( $message ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = null !== $screen ? (string) $screen->id : '';
		if ( ! in_array( $id, array( 'plugins', 'plugins-network', 'dashboard', 'dashboard-network' ), true ) ) {
			return;
		}
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
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
	$agentporter_incomplete = static function () {
		agentporter_print_plugins_screen_notice(
			__( 'AgentPorter is incomplete: its autoloader is missing. Reinstall the plugin from WordPress.org (developers: run "composer install").', 'agentporter' )
		);
	};
	add_action( 'admin_notices', $agentporter_incomplete );
	add_action( 'network_admin_notices', $agentporter_incomplete );
	unset( $agentporter_incomplete );
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

\AgentPorter\Plugin::instance()->boot();

register_activation_hook( __FILE__, array( \AgentPorter\Core\Activator::class, 'activate' ) );
