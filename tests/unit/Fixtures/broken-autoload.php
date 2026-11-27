<?php
/**
 * Autoloads the deliberately broken subsystem files used by boot-boundary tests.
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- Test-only autoloader for seeded fixtures.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AgentPorter\\Tests\\Broken\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__, 2 ) . '/seeded/fixtures/broken/' . substr( $class_name, strlen( $prefix ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
