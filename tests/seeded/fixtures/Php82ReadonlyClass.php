<?php
/**
 * SEEDED VIOLATION — never shipped. Uses PHP 8.2 syntax (readonly class), above the PHP 8.1 ceiling.
 * bin/check-seeded-violations.sh asserts that `php -l` on PHP 8.1 and PHPCompatibilityWP both reject it.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Seeded;

readonly class Php82ReadonlyClass {
	public function __construct( public string $name ) {}
}
