<?php
/**
 * Plugin header sanity checks.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PluginHeaderTest extends TestCase {

	/**
	 * Parsed header fields.
	 *
	 * @return array<string, string>
	 */
	private function header(): array {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/agentporter.php' );
		preg_match_all( '/^\s*\*\s*([A-Za-z ]+):\s*(.+)$/m', $source, $matches, PREG_SET_ORDER );
		$fields = array();
		foreach ( $matches as $match ) {
			$fields[ trim( $match[1] ) ] = trim( $match[2] );
		}
		return $fields;
	}

	public function test_minimum_requirements_match_the_spec(): void {
		$header = $this->header();
		$this->assertSame( '8.1', $header['Requires PHP'] );
		$this->assertSame( '6.9', $header['Requires at least'] );
		$this->assertSame( 'agentporter', $header['Text Domain'] );
		$this->assertSame( 'GPL-2.0-or-later', $header['License'] );
	}

	public function test_version_constant_matches_header(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/agentporter.php' );
		$this->assertSame( 1, preg_match( "/define\(\s*'AGENTPORTER_VERSION',\s*'([^']+)'\s*\)/", $source, $m ) );
		$this->assertSame( $this->header()['Version'], $m[1] );
	}
}
