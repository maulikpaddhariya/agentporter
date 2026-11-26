<?php
/**
 * Loads agentporter.php in a clean PHP process with minimal WordPress stubs and prints what happened.
 * Used by BootstrapTest. Usage: php load-plugin.php <wp_version>
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- Standalone test harness with WordPress function stubs.

define( 'ABSPATH', sys_get_temp_dir() . '/agentporter-fake-wp/' );

$GLOBALS['wp_version']        = $argv[1] ?? '0';
$GLOBALS['agentporter_hooks'] = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['agentporter_hooks'][] = $hook;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['agentporter_hooks'][] = $hook;
	return true;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['agentporter_hooks'][] = 'did:' . $hook;
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['agentporter_hooks'][] = 'activation';
}
function __( $text, $domain = 'default' ) {
	return $text;
}

require dirname( __DIR__, 3 ) . '/agentporter.php';

$declared = array_values(
	array_filter(
		get_declared_classes(),
		static fn( $c ) => str_starts_with( $c, 'AgentPorter\\' )
	)
);

echo json_encode(
	array(
		'classes' => $declared,
		'hooks'   => $GLOBALS['agentporter_hooks'],
		'booted'  => class_exists( 'AgentPorter\\Plugin', false ) && \AgentPorter\Plugin::instance()->is_booted(),
	)
);
