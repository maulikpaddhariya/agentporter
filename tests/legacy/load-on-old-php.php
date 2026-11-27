<?php
/**
 * Runs agentporter.php on an unsupported PHP version (CI: 7.2 and 8.0) with WordPress stubs and
 * checks that it shows the notice, blocks activation and loads nothing else. Exit code 0 = pass.
 *
 * This file must stay PHP 7.2-compatible.
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- Standalone harness with WordPress function stubs.

if ( PHP_VERSION_ID >= 80100 ) {
	fwrite( STDERR, "Run this harness on PHP < 8.1.\n" );
	exit( 2 );
}

define( 'ABSPATH', sys_get_temp_dir() . '/agentporter-fake-wp/' );

$GLOBALS['wp_version'] = '7.1';
$GLOBALS['ap_hooks']   = array();
$GLOBALS['ap_cbs']     = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['ap_hooks'][]        = $hook;
	$GLOBALS['ap_cbs'][ $hook ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}
function do_action( $hook ) {
	$GLOBALS['ap_hooks'][] = 'did:' . $hook;
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['ap_hooks'][] = 'activation';
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

require dirname( __DIR__, 2 ) . '/agentporter.php';

$failures = array();

$classes = array();
foreach ( get_declared_classes() as $class ) {
	if ( 0 === strpos( $class, 'AgentPorter\\' ) ) {
		$classes[] = $class;
	}
}
if ( array( 'AgentPorter\\Core\\Requirements' ) !== $classes ) {
	$failures[] = 'Unexpected classes loaded: ' . implode( ', ', $classes );
}
if ( array( 'admin_notices', 'network_admin_notices', 'activation' ) !== $GLOBALS['ap_hooks'] ) {
	$failures[] = 'Unexpected hooks: ' . implode( ', ', $GLOBALS['ap_hooks'] );
}

ob_start();
foreach ( $GLOBALS['ap_cbs']['admin_notices'] as $callback ) {
	call_user_func( $callback );
}
$html = (string) ob_get_clean();
if ( false === strpos( $html, 'PHP 8.1 or later' ) ) {
	$failures[] = 'Notice did not mention the PHP requirement: ' . $html;
}

if ( array() !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'PASS on PHP ' . PHP_VERSION . ": notice shown, activation blocked, nothing else loaded.\n";
