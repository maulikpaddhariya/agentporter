<?php
/**
 * Seeded violation: PHPStan level 8 must reject a wrong return type.
 *
 * @package AgentPorter
 */

namespace AgentPorter\Seeded;

defined( 'ABSPATH' ) || exit;

/**
 * Seeded.
 */
final class PhpstanTypeError {
	/**
	 * Returns a string where an int is declared.
	 */
	public function count(): int {
		return 'many';
	}
}
