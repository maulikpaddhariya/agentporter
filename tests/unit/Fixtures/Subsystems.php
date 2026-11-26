<?php
/**
 * Subsystem doubles for boot-boundary tests.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Fixtures;

use AgentPorter\Core\Container;
use AgentPorter\Core\Hooks;
use AgentPorter\Core\Subsystem;

/**
 * Registers a filter that appends "-good".
 */
final class GoodSubsystem implements Subsystem {
	public function id(): string {
		return 'good';
	}
	public function register( Container $container, Hooks $hooks ): void {
		$hooks->add_filter( 'agentporter_test_value', static fn( $value ) => $value . '-good' );
	}
}

/**
 * Registers a filter, then throws during register().
 */
final class ThrowsOnRegisterSubsystem implements Subsystem {
	public function id(): string {
		return 'throws-on-register';
	}
	public function register( Container $container, Hooks $hooks ): void {
		$hooks->add_filter( 'agentporter_test_value', static fn( $value ) => $value . '-should-not-run' );
		throw new \RuntimeException( 'boom at boot' );
	}
}

/**
 * Throws from its constructor.
 */
final class ThrowsInConstructorSubsystem implements Subsystem {
	public function __construct() {
		throw new \RuntimeException( 'boom in constructor' );
	}
	public function id(): string {
		return 'never';
	}
	public function register( Container $container, Hooks $hooks ): void {
	}
}

/**
 * Registers a filter whose callback throws at runtime.
 */
final class ThrowsAtRuntimeSubsystem implements Subsystem {
	public function id(): string {
		return 'throws-at-runtime';
	}
	public function register( Container $container, Hooks $hooks ): void {
		$hooks->add_filter(
			'agentporter_test_value',
			static function () {
				throw new \RuntimeException( 'boom at runtime' );
			},
			5
		);
	}
}

/**
 * Has an invalid id.
 */
final class InvalidIdSubsystem implements Subsystem {
	public function id(): string {
		return 'Not Valid!';
	}
	public function register( Container $container, Hooks $hooks ): void {
	}
}

/**
 * Reuses the id "good".
 */
final class DuplicateIdSubsystem implements Subsystem {
	public function id(): string {
		return 'good';
	}
	public function register( Container $container, Hooks $hooks ): void {
	}
}

/**
 * Not a subsystem at all.
 */
final class NotASubsystem {
}
