<?php
/**
 * The uninstall.php file does nothing unless WordPress is uninstalling the plugin.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class UninstallGuardTest extends TestCase {

	public function test_direct_or_unguarded_load_does_nothing(): void {
		$file = dirname( __DIR__, 2 ) . '/uninstall.php';
		foreach ( array( '', "define( 'ABSPATH', '/tmp/' );" ) as $setup ) {
			$code   = $setup . ' include ' . "'" . addslashes( $file ) . "'" . '; echo "REACHED";';
			$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Test-only subprocess.
			$this->assertSame( '', (string) $output, 'Setup: ' . $setup );
		}
	}
}
