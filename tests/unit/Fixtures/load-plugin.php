<?php
/**
 * Loads agentporter.php in a clean PHP process with minimal WordPress stubs and prints what happened.
 * Used by BootstrapTest. Usage: php load-plugin.php <wp_version> [normal|duplicate|no-autoloader]
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- Standalone test harness with WordPress function stubs.

define( 'ABSPATH', sys_get_temp_dir() . '/agentporter-fake-wp/' );

$GLOBALS['wp_version']        = $argv[1] ?? '0';
$GLOBALS['agentporter_hooks'] = array();
$GLOBALS['agentporter_cbs']   = array();
$mode                         = $argv[2] ?? 'normal';
$root                         = dirname( __DIR__, 3 );

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['agentporter_hooks'][]        = $hook;
	$GLOBALS['agentporter_cbs'][ $hook ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}
function do_action( $hook, ...$args ) {
	$GLOBALS['agentporter_hooks'][] = 'did:' . $hook;
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['agentporter_hooks'][] = 'activation';
}
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['agentporter_hooks'][] = 'deactivation';
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function current_user_can( $cap ) {
	return true;
}
function get_current_screen() {
	return (object) array( 'id' => 'plugins' );
}

/** Copies the pre-boot files into a temporary plugin folder and returns its main file. */
function agentporter_copy_plugin( string $root, string $folder ): string {
	$dir = sys_get_temp_dir() . '/' . $folder . '-' . getmypid();
	@mkdir( $dir . '/src/Core', 0700, true );
	copy( $root . '/agentporter.php', $dir . '/agentporter.php' );
	copy( $root . '/src/Core/Requirements.php', $dir . '/src/Core/Requirements.php' );
	return $dir . '/agentporter.php';
}

$hooks_before_second = 0;
if ( 'no-autoloader' === $mode ) {
	require agentporter_copy_plugin( $root, 'agentporter-noauto' );
} else {
	require $root . '/agentporter.php';
	if ( 'duplicate' === $mode ) {
		$hooks_before_second = count( $GLOBALS['agentporter_hooks'] );
		require agentporter_copy_plugin( $root, 'agentporter-copy' );
	}
}

ob_start();
foreach ( $GLOBALS['agentporter_cbs']['admin_notices'] ?? array() as $callback ) {
	$callback();
}
$notices = (string) ob_get_clean();

$declared = array_values(
	array_filter(
		get_declared_classes(),
		static fn( $c ) => str_starts_with( $c, 'AgentPorter\\' )
	)
);

echo json_encode(
	array(
		'classes'      => $declared,
		'hooks'        => $GLOBALS['agentporter_hooks'],
		'second_hooks' => array_slice( $GLOBALS['agentporter_hooks'], $hooks_before_second ),
		'booted'       => class_exists( 'AgentPorter\\Plugin', false ) && \AgentPorter\Plugin::instance()->is_booted(),
		'notices'      => $notices,
	)
);
