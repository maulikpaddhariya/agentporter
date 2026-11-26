<?php
/**
 * Integration test bootstrap: loads the WordPress test suite, optionally WooCommerce, then AgentPorter.
 *
 * Environment:
 * - WP_TESTS_DIR      Path to the WordPress PHPUnit includes (default /tmp/wordpress-tests-lib).
 * - AGENTPORTER_WITH_WOO=1  Load WooCommerce before AgentPorter.
 * - WP_MULTISITE=1    Run as multisite (read by the WordPress test bootstrap).
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

$agentporter_root = dirname( __DIR__, 2 );

require_once $agentporter_root . '/vendor/autoload.php';
require_once $agentporter_root . '/tests/Support/DeprecationGate.php';

$agentporter_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $agentporter_tests_dir || '' === $agentporter_tests_dir ) {
	$agentporter_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $agentporter_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in {$agentporter_tests_dir}. Run bin/install-wp-tests.sh first.\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI.
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by the WordPress test suite contract.
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $agentporter_root . '/vendor/yoast/phpunit-polyfills' );
}

require_once $agentporter_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $agentporter_root ): void {
		if ( '1' === getenv( 'AGENTPORTER_WITH_WOO' ) ) {
			$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			if ( ! file_exists( $woo ) ) {
				fwrite( STDERR, "AGENTPORTER_WITH_WOO=1 but WooCommerce is not installed at {$woo}\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI.
				exit( 1 );
			}
			require_once $woo;
		}
		require_once $agentporter_root . '/agentporter.php';
	}
);

// Install the gate after WordPress' own handlers are set up by the test bootstrap,
// so deprecations from core keep their normal handling and only ours fail the run.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $agentporter_root ): void {
		\AgentPorter\Tests\Support\DeprecationGate::install( $agentporter_root );
	},
	0
);

require $agentporter_tests_dir . '/includes/bootstrap.php';
