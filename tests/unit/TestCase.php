<?php
/**
 * Base unit test case with Brain Monkey.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit;

use Brain\Monkey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Sets up and tears down Brain Monkey around each test.
 */
abstract class TestCase extends PolyfillTestCase {

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
