<?php
/**
 * Unit test bootstrap: no WordPress, functions mocked with Brain Monkey.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

$agentporter_root = dirname( __DIR__, 2 );

require_once $agentporter_root . '/vendor/autoload.php';
require_once $agentporter_root . '/tests/Support/DeprecationGate.php';

\AgentPorter\Tests\Support\DeprecationGate::install( $agentporter_root );

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant required by plugin files.
	define( 'ABSPATH', sys_get_temp_dir() . '/agentporter-unit-wp/' );
}
// Time constants from wp-includes/default-constants.php.
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
) as $agentporter_name => $agentporter_value ) {
	if ( ! defined( $agentporter_name ) ) {
		define( $agentporter_name, $agentporter_value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- WordPress core constants.
	}
}
unset( $agentporter_name, $agentporter_value );
