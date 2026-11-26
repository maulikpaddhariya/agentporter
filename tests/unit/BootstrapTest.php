<?php
/**
 * Loads the real main plugin file in a separate PHP process.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class BootstrapTest extends TestCase {

	/**
	 * Runs the harness and decodes its report.
	 *
	 * @param string $wp_version Fake WordPress version.
	 * @return array{classes: list<string>, hooks: list<string>, booted: bool}
	 */
	private function load( string $wp_version ): array {
		$script = __DIR__ . '/Fixtures/load-plugin.php';
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $wp_version ) . ' 2>&1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Test-only subprocess.
		$this->assertIsString( $output );
		$report = json_decode( $output, true );
		$this->assertIsArray( $report, 'Harness output: ' . $output );
		return $report;
	}

	public function test_old_wordpress_loads_nothing_but_the_requirements_check(): void {
		$report = $this->load( '6.8.3' );
		$this->assertSame( array( 'AgentPorter\\Core\\Requirements' ), $report['classes'] );
		$this->assertFalse( $report['booted'] );
		$this->assertSame( array( 'admin_notices', 'network_admin_notices', 'activation' ), $report['hooks'] );
	}

	public function test_supported_wordpress_boots_the_plugin(): void {
		$report = $this->load( '6.9' );
		$this->assertTrue( $report['booted'] );
		$this->assertContains( 'AgentPorter\\Plugin', $report['classes'] );
		$this->assertContains( 'did:agentporter_booted', $report['hooks'] );
	}
}
