<?php
/**
 * Seeded code for the Infection proof.
 *
 * @package AgentPorter\Tests
 */

namespace AgentPorter\Seeded;

/**
 * Arithmetic the seeded test barely checks.
 */
final class Weak {
	/**
	 * Clamp.
	 *
	 * @param int $value Value.
	 */
	public static function clamp( int $value ): int {
		if ( $value > 100 ) {
			return 100;
		}
		return $value < 0 ? 0 : $value + 0;
	}
}
