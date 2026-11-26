<?php
/**
 * Exercises the seeded deprecation. Only run by bin/check-seeded-violations.sh.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Seeded;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SeededDeprecationTest extends TestCase {
	public function test_triggers_deprecation_from_plugin_code(): void {
		$this->assertSame( 0, \AgentPorter\Seeded\SeededDeprecation::length( null ) );
	}
}
