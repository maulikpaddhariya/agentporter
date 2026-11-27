<?php
/**
 * Seeded violation: Rector (PHP 8.1 sets) must want to change this code.
 *
 * @package AgentPorter
 */

namespace AgentPorter\Seeded;

defined( 'ABSPATH' ) || exit;

/**
 * Seeded.
 */
final class RectorUpgrade {
	/**
	 * Never reassigned after construction: Rector makes it readonly.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Constructor.
	 *
	 * @param string $name Name.
	 */
	public function __construct( string $name ) {
		$this->name = $name;
	}

	/**
	 * Name.
	 */
	public function name(): string {
		return $this->name;
	}
}
