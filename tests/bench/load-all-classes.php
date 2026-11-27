<?php
/**
 * Prints the memory (bytes) added by autoloading every class under src/. Run in a fresh process.
 *
 * @package AgentPorter\Tests
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Required by plugin files.
$agentporter_root = dirname( __DIR__, 2 );
require $agentporter_root . '/vendor/autoload.php';
$agentporter_before = memory_get_usage();
$agentporter_files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $agentporter_root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $agentporter_files as $agentporter_file ) {
	$agentporter_class = 'AgentPorter\\' . str_replace( array( '/', '.php' ), array( '\\', '' ), substr( $agentporter_file->getPathname(), strlen( $agentporter_root . '/src/' ) ) );
	class_exists( $agentporter_class ) || interface_exists( $agentporter_class ) || trait_exists( $agentporter_class ) || enum_exists( $agentporter_class );
}
echo memory_get_usage() - $agentporter_before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer for the harness.
