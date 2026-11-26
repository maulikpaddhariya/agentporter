<?php
/**
 * A self-contained part of the plugin that boots inside its own failure boundary.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Subsystems register their hooks through {@see Hooks} so that an exception thrown at boot or
 * inside a hook callback disables only that subsystem.
 */
interface Subsystem {

	/**
	 * Stable machine id, for example "tracking" or "serve".
	 */
	public function id(): string;

	/**
	 * Registers services and hooks. May throw; the boot boundary catches it.
	 *
	 * @param Container $container Service container.
	 * @param Hooks     $hooks     Hook registrar bound to this subsystem.
	 */
	public function register( Container $container, Hooks $hooks ): void;
}
