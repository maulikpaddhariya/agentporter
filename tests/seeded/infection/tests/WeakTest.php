<?php
/**
 * Seeded weak test: executes the code without checking results, so mutants survive.
 *
 * @package AgentPorter\Tests
 */

use AgentPorter\Seeded\Weak;
use PHPUnit\Framework\TestCase;

/**
 * Weak test.
 */
final class WeakTest extends TestCase {
	public function test_runs(): void {
		$this->assertIsInt( Weak::clamp( 50 ) );
		$this->assertIsInt( Weak::clamp( 500 ) );
	}
}
