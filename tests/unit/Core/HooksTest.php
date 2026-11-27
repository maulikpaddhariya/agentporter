<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Core;

use AgentPorter\Core\Faults;
use AgentPorter\Core\Hooks;
use AgentPorter\Core\Logger;
use AgentPorter\Tests\Unit\Fixtures\DenyDecision;
use AgentPorter\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Fixtures/Subsystems.php';
require_once dirname( __DIR__ ) . '/Fixtures/WPDieException.php';

/**
 * @covers \AgentPorter\Core\Hooks
 * @covers \AgentPorter\Core\Faults
 */
final class HooksTest extends TestCase {

	/**
	 * Failure state under test.
	 *
	 * @var Faults
	 */
	private Faults $faults;

	protected function set_up(): void {
		parent::set_up();
		$this->faults = new Faults( new Logger( false ) );
	}

	public function test_throwing_filter_returns_input_unchanged_and_disables_subsystem(): void {
		$hooks   = new Hooks( 'broken', $this->faults );
		$wrapped = $hooks->wrap_filter(
			static function () {
				throw new \RuntimeException( 'secret detail' );
			}
		);
		$this->assertSame( 'original', $wrapped( 'original', 'extra' ) );
		$this->assertTrue( $this->faults->is_disabled( 'broken' ) );
		$this->assertSame( array( 'broken' => \RuntimeException::class ), $this->faults->disabled() );
	}

	public function test_disabled_subsystem_callbacks_become_no_ops(): void {
		$calls  = 0;
		$hooks  = new Hooks( 'broken', $this->faults );
		$filter = $hooks->wrap_filter(
			static function ( $value ) use ( &$calls ) {
				++$calls;
				return $value . '!';
			}
		);
		$action = $hooks->wrap_action(
			static function () use ( &$calls ) {
				++$calls;
			}
		);
		$this->faults->disable( 'broken', new \RuntimeException( 'x' ) );
		$this->assertSame( 'v', $filter( 'v' ) );
		$action();
		$this->assertSame( 0, $calls );
	}

	public function test_throwing_action_is_contained(): void {
		$wrapped = ( new Hooks( 'broken', $this->faults ) )->wrap_action(
			static function () {
				throw new \Error( 'fatal-ish' );
			}
		);
		$wrapped( 1, 2 );
		$this->assertTrue( $this->faults->is_disabled( 'broken' ) );
	}

	public function test_failure_fires_action_with_class_name_only(): void {
		( new Hooks( 'broken', $this->faults ) )->wrap_action(
			static function () {
				throw new \RuntimeException( 'contains /var/www/secret/path' );
			}
		)();
		$this->assertSame( 1, did_action( 'agentporter_subsystem_failed' ) );
	}

	public function test_throwing_failure_listener_does_not_escape(): void {
		\Brain\Monkey\Actions\expectDone( 'agentporter_subsystem_failed' )->once()->whenHappen(
			static function () {
				throw new \RuntimeException( 'listener' );
			}
		);
		$this->faults->disable( 'broken', new \RuntimeException( 'x' ) );
		$this->assertTrue( $this->faults->is_disabled( 'broken' ) );
	}

	public function test_other_subsystems_keep_working(): void {
		( new Hooks( 'broken', $this->faults ) )->wrap_action(
			static function () {
				throw new \RuntimeException( 'x' );
			}
		)();
		$healthy = ( new Hooks( 'healthy', $this->faults ) )->wrap_filter( static fn( $v ) => $v . '-ok' );
		$this->assertSame( 'a-ok', $healthy( 'a' ) );
		$this->assertFalse( $this->faults->is_disabled( 'healthy' ) );
	}

	public function test_healthy_callbacks_pass_all_arguments_and_return_value(): void {
		$filter = ( new Hooks( 'healthy', $this->faults ) )->wrap_filter( static fn( $a, $b, $c ) => "$a|$b|$c" );
		$this->assertSame( '1|2|3', $filter( 1, 2, 3 ) );
	}

	public function test_registration_uses_wordpress_api_with_priority_and_args(): void {
		\Brain\Monkey\Filters\expectAdded( 'the_title' )->once()->with( \Mockery::type( \Closure::class ), 7, 2 );
		\Brain\Monkey\Actions\expectAdded( 'init' )->once()->with( \Mockery::type( \Closure::class ), 3, 0 );
		$hooks = new Hooks( 'healthy', $this->faults );
		$hooks->add_filter( 'the_title', 'strtoupper', 7, 2 );
		$hooks->add_action( 'init', 'time', 3, 0 );
		$this->addToAssertionCount( 2 );
	}

	public function test_guard_filter_returns_deny_value_when_subsystem_disabled_by_other_hook(): void {
		$hooks = new Hooks( 'guard', $this->faults );
		$allow = $hooks->wrap_guard_filter( static fn() => true, false );
		$other = $hooks->wrap_action(
			static function () {
				throw new \RuntimeException( 'unrelated' );
			}
		);
		$this->assertTrue( $allow( null ) );
		$other();
		$this->assertFalse( $allow( null ), 'Once disabled, the guard must deny, not pass its input through.' );
	}

	public function test_guard_filter_returns_deny_value_when_callback_throws(): void {
		$deny = ( new Hooks( 'guard', $this->faults ) )->wrap_guard_filter(
			static function () {
				throw new \RuntimeException( 'x' );
			},
			'denied'
		);
		$this->assertSame( 'denied', $deny( 'allowed' ) );
		$this->assertTrue( $this->faults->is_disabled( 'guard' ) );
	}

	public function test_guard_filter_deny_closure_builds_a_fresh_value_from_hook_args(): void {
		$this->faults->disable( 'guard', new \RuntimeException( 'x' ) );
		$wrapped = ( new Hooks( 'guard', $this->faults ) )->wrap_guard_filter(
			static fn() => 'allow',
			static fn( $value, $extra ) => array( 'denied', $extra )
		);
		$first   = $wrapped( null, 'a' );
		$second  = $wrapped( null, 'b' );
		$this->assertSame( array( 'denied', 'a' ), $first );
		$this->assertSame( array( 'denied', 'b' ), $second );
	}

	public function test_guard_action_runs_deny_handler_when_subsystem_disabled(): void {
		$ran    = 0;
		$denied = 0;
		$this->faults->disable( 'guard', new \RuntimeException( 'x' ) );
		( new Hooks( 'guard', $this->faults ) )->wrap_guard_action(
			static function () use ( &$ran ) {
				++$ran;
			},
			static function () use ( &$denied ) {
				++$denied;
			}
		)();
		$this->assertSame( array( 0, 1 ), array( $ran, $denied ) );
	}

	public function test_guard_action_runs_deny_handler_when_callback_throws(): void {
		$denied = 0;
		( new Hooks( 'guard', $this->faults ) )->wrap_guard_action(
			static function () {
				throw new \RuntimeException( 'x' );
			},
			static function () use ( &$denied ) {
				++$denied;
			}
		)();
		$this->assertSame( 1, $denied );
	}

	public function test_hooks_stop_when_a_dependency_is_disabled(): void {
		$hooks = new Hooks( 'serve', $this->faults, array( 'guard' ) );
		$safe  = $hooks->wrap_filter( static fn( $v ) => $v . '-changed' );
		$guard = $hooks->wrap_guard_filter( static fn() => true, false );
		$this->assertSame( 'v-changed', $safe( 'v' ) );
		$this->faults->disable( 'guard', new \RuntimeException( 'x' ) );
		$this->assertSame( 'v', $safe( 'v' ) );
		$this->assertFalse( $guard( null ) );
		$this->assertFalse( $this->faults->is_disabled( 'serve' ) );
	}

	public function test_control_flow_exceptions_are_rethrown_without_disabling(): void {
		$hooks = new Hooks( 'policy', $this->faults );
		foreach ( array( new DenyDecision( 'no' ), new \WPDieException( 'die' ) ) as $decision ) {
			foreach ( array( 'wrap_filter', 'wrap_action' ) as $method ) {
				try {
					$hooks->{$method}(
						static function () use ( $decision ) {
							throw $decision;
						}
					)( 'v' );
					$this->fail( 'Expected the decision to propagate.' );
				} catch ( \Throwable $caught ) {
					$this->assertSame( $decision, $caught );
				}
			}
			try {
				$hooks->wrap_guard_filter(
					static function () use ( $decision ) {
						throw $decision;
					},
					false
				)( true );
				$this->fail( 'Expected the decision to propagate.' );
			} catch ( \Throwable $caught ) {
				$this->assertSame( $decision, $caught );
			}
		}
		$this->assertFalse( $this->faults->is_disabled( 'policy' ) );
	}

	public function test_unknown_exceptions_are_not_decisions(): void {
		$this->assertFalse( Hooks::is_decision( new \RuntimeException( 'x' ) ) );
		$this->assertTrue( Hooks::is_decision( new DenyDecision( 'x' ) ) );
	}

	public function test_guard_filter_denies_when_subsystem_disabled_during_its_own_callback(): void {
		$hooks = new Hooks( 'guard', $this->faults );
		$inner = $hooks->wrap_filter(
			static function () {
				throw new \RuntimeException( 'nested adapter failed' );
			}
		);
		$guard = $hooks->wrap_guard_filter( static fn() => $inner( true ), false );
		$this->assertFalse( $guard( null ), 'Reviewer repro: the answer computed while failing must not be trusted.' );
	}

	public function test_guard_filter_denies_when_dependency_disabled_during_callback(): void {
		$dependency = ( new Hooks( 'serve', $this->faults ) )->wrap_filter(
			static function () {
				throw new \RuntimeException( 'x' );
			}
		);
		$guard      = ( new Hooks( 'policy', $this->faults, array( 'serve' ) ) )->wrap_guard_filter( static fn() => $dependency( true ), false );
		$this->assertFalse( $guard( null ) );
	}

	public function test_guard_action_runs_deny_handler_when_disabled_during_callback(): void {
		$denied = 0;
		$hooks  = new Hooks( 'guard', $this->faults );
		$inner  = $hooks->wrap_action(
			static function () {
				throw new \RuntimeException( 'x' );
			}
		);
		$hooks->wrap_guard_action(
			static function () use ( $inner ) {
				$inner();
			},
			static function () use ( &$denied ) {
				++$denied;
			}
		)();
		$this->assertSame( 1, $denied );
	}

	public function test_woocommerce_exceptions_are_faults_unless_opted_in(): void {
		if ( ! class_exists( 'WC_Data_Exception' ) ) {
			eval( 'class WC_Data_Exception extends Exception {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test double for a WooCommerce class name.
		}
		$throw = static function () {
			throw new \WC_Data_Exception( 'bad data' );
		};
		( new Hooks( 'tracking', $this->faults ) )->wrap_action( $throw )();
		$this->assertTrue( $this->faults->is_disabled( 'tracking' ), 'By default a WooCommerce exception is a fault.' );

		$checkout = ( new Hooks( 'checkout-policy', $this->faults ) )->with_decisions( array( 'WC_Data_Exception' ) );
		try {
			$checkout->wrap_action( $throw )();
			$this->fail( 'Opted-in decision must propagate.' );
		} catch ( \WC_Data_Exception $e ) {
			$this->assertFalse( $this->faults->is_disabled( 'checkout-policy' ) );
		}
	}

	public function test_non_closure_callable_deny_values_are_rejected(): void {
		foreach ( array( '__return_false', array( 'DateTime', 'createFromFormat' ), 'DateTime::createFromFormat', new \stdClass() ) as $deny ) {
			try {
				( new Hooks( 'guard', $this->faults ) )->wrap_guard_filter( static fn() => true, $deny );
				$this->fail( 'Expected rejection.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->assertInstanceOf( \Closure::class, ( new Hooks( 'guard', $this->faults ) )->wrap_guard_filter( static fn() => true, 'denied-string' ) );
	}

	public function test_broad_exception_classes_cannot_be_decisions(): void {
		foreach ( array( 'Throwable', '\\Exception', 'Error', 'RuntimeException', 'TypeError', 'InvalidArgumentException' ) as $class ) {
			try {
				( new Hooks( 'guard', $this->faults ) )->with_decisions( array( $class ) );
				$this->fail( 'Expected rejection of ' . $class );
			} catch ( \InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_agent_facing_guards_deny_under_lockdown_but_gate_guards_do_not(): void {
		$this->faults->lock_down();
		$hooks = new Hooks( 'serve', $this->faults );
		$this->assertFalse( $hooks->for_agents()->wrap_guard_filter( static fn() => true, false )( null ) );
		$this->assertTrue( $hooks->wrap_guard_filter( static fn() => true, false )( null ), 'The front-end gate must keep serving pages.' );
	}
}
