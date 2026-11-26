<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Core;

use AgentPorter\Core\Faults;
use AgentPorter\Core\Hooks;
use AgentPorter\Core\Logger;
use AgentPorter\Tests\Unit\TestCase;

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
}
