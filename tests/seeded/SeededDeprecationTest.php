<?php
/**
 * Exercises the seeded deprecations. Only run by bin/check-seeded-violations.sh.
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
	public function test_runtime_deprecation(): void {
		$this->assertSame( 0, \AgentPorter\Seeded\SeededDeprecation::length( null ) );
	}

	public function test_swallowed_deprecation(): void {
		$this->assertSame( -1, \AgentPorter\Seeded\SeededDeprecation::swallowed() );
	}

	public function test_compile_time_deprecation(): void {
		$this->assertSame( 'none', \AgentPorter\Seeded\SeededCompileTimeDeprecation::name() );
	}
}
