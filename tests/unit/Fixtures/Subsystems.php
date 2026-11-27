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

/**
 * Returns a different id on the second call, then throws in register().
 */
final class ShiftingIdSubsystem implements Subsystem {
	/**
	 * Calls to id().
	 *
	 * @var int
	 */
	private int $calls = 0;
	public function id(): string {
		++$this->calls;
		return 1 === $this->calls ? 'shifter' : 'good';
	}
	public function register( Container $container, Hooks $hooks ): void {
		throw new \RuntimeException( 'shifter fails' );
	}
}

/**
 * Security-critical subsystem with a guard filter (deny = false) and an action that throws at runtime.
 */
final class CriticalGuardSubsystem implements \AgentPorter\Core\SecurityCritical {
	public function id(): string {
		return 'guard';
	}
	public function register( Container $container, Hooks $hooks ): void {
		$hooks->add_guard_filter( 'agentporter_test_allow', static fn() => true, false );
		$hooks->add_action(
			'agentporter_test_trigger',
			static function () {
				throw new \RuntimeException( 'unrelated runtime failure' );
			}
		);
	}
}

/**
 * Security-critical subsystem that fails at boot.
 */
final class CriticalThrowsOnRegisterSubsystem implements \AgentPorter\Core\SecurityCritical {
	public function id(): string {
		return 'guard';
	}
	public function register( Container $container, Hooks $hooks ): void {
		throw new \RuntimeException( 'guard cannot boot' );
	}
}

/**
 * Security-critical subsystem that fails in its constructor.
 */
final class CriticalThrowsInConstructorSubsystem implements \AgentPorter\Core\SecurityCritical {
	public function __construct() {
		throw new \RuntimeException( 'guard constructor' );
	}
	public function id(): string {
		return 'guard';
	}
	public function register( Container $container, Hooks $hooks ): void {
	}
}

/**
 * Depends on "guard"; serves something agent-facing.
 */
final class DependentSubsystem implements \AgentPorter\Core\DependsOn {
	public function id(): string {
		return 'serve';
	}
	public function depends_on(): array {
		return array( 'guard' );
	}
	public function register( Container $container, Hooks $hooks ): void {
		$hooks->add_filter( 'agentporter_test_value', static fn( $value ) => $value . '-serve' );
	}
}

/**
 * Registers a container service, then throws.
 */
final class RegistersServiceThenThrowsSubsystem implements Subsystem {
	public function id(): string {
		return 'half-built';
	}
	public function register( Container $container, Hooks $hooks ): void {
		$container->set( \SplObjectStorage::class, static fn() => new \SplObjectStorage() );
		throw new \RuntimeException( 'half built' );
	}
}

/**
 * Registers an action, then throws in register().
 */
final class ActionThenThrowsSubsystem implements Subsystem {
	/**
	 * Times the action ran.
	 *
	 * @var int
	 */
	public static int $runs = 0;
	public function id(): string {
		return 'action-then-throws';
	}
	public function register( Container $container, Hooks $hooks ): void {
		$hooks->add_action(
			'agentporter_test_action',
			static function () {
				++self::$runs;
			}
		);
		throw new \RuntimeException( 'after action' );
	}
}

/**
 * A deliberate decision exception.
 */
final class DenyDecision extends \RuntimeException implements \AgentPorter\Core\DecisionException {
}
