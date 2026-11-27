<?php
/**
 * Subsystems that need other subsystems.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A subsystem with dependencies boots only after all of them booted, and its wrapped hooks stop
 * running as soon as any dependency is disabled. Dependencies must appear earlier in the boot order.
 */
interface DependsOn extends Subsystem {

	/**
	 * Ids of the subsystems this one needs.
	 *
	 * @return list<string>
	 */
	public function depends_on(): array;
}
