<?php
/**
 * SEEDED VIOLATION — never shipped. Passing null to a non-nullable internal parameter is deprecated
 * on PHP 8.1+. bin/check-seeded-violations.sh copies this into src/ and asserts the test run fails.
 *
 * No strict_types on purpose: with strict types the call would be a TypeError, not a deprecation.
 *
 * @package AgentPorter\Tests
 */

namespace AgentPorter\Seeded;

final class SeededDeprecation {
	public static function length( $value ): int {
		return strlen( $value );
	}

	/** Plugin code that catches the gate's exception must still fail the run (shutdown check). */
	public static function swallowed(): int {
		try {
			return strlen( null );
		} catch ( \Throwable $e ) {
			return -1;
		}
	}
}
