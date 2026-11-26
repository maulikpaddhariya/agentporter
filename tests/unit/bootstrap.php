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
