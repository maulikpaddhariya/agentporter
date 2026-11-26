<?php
/**
 * SEEDED VIOLATION — never shipped. An implicitly nullable parameter is deprecated at compile time
 * on PHP 8.4+. bin/check-seeded-violations.sh copies this into src/ on PHP 8.4+ and asserts failure.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Seeded;

final class SeededCompileTimeDeprecation {
	public static function name( \stdClass $value = null ): string {
		return null === $value ? 'none' : 'some';
	}
}
