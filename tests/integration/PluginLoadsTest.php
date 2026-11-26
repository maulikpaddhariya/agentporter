<?php
/**
 * The plugin loads inside WordPress on every matrix cell.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Integration;

/**
 * @coversNothing
 */
final class PluginLoadsTest extends \WP_UnitTestCase {

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'AGENTPORTER_VERSION' ) );
		$this->assertTrue( defined( 'AGENTPORTER_FILE' ) );
	}

	public function test_environment_matches_matrix_cell(): void {
		$this->assertSame( '1' === getenv( 'WP_MULTISITE' ), is_multisite() );
		$this->assertSame( '1' === getenv( 'AGENTPORTER_WITH_WOO' ), class_exists( 'WooCommerce' ) );
	}
}
