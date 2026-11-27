<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit;

use AgentPorter\Core\Container;
use AgentPorter\Core\ContainerException;
use AgentPorter\Core\Faults;
use AgentPorter\Core\Logger;
use AgentPorter\Plugin;
use AgentPorter\Tests\Unit\Fixtures;
use Brain\Monkey\Actions;

require_once __DIR__ . '/Fixtures/Subsystems.php';
require_once __DIR__ . '/Fixtures/broken-autoload.php';

/**
 * @covers \AgentPorter\Plugin
 */
final class PluginTest extends TestCase {

	/**
	 * Container of the last plugin built.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Builds a plugin with the given subsystems.
	 *
	 * @param array $subsystems Subsystem classes.
	 * @param array $critical   Critical subsystem classes.
	 */
	private function plugin( array $subsystems, array $critical = array() ): Plugin {
		$this->container = new Container();
		return new Plugin( $this->container, new Faults( new Logger( false ) ), $subsystems, $critical );
	}

	public function test_failing_subsystems_are_disabled_and_others_boot(): void {
		$plugin = $this->plugin(
			array(
				Fixtures\ThrowsInConstructorSubsystem::class,
				Fixtures\GoodSubsystem::class,
				Fixtures\ThrowsOnRegisterSubsystem::class,
				Fixtures\InvalidIdSubsystem::class,
				Fixtures\DuplicateIdSubsystem::class,
				Fixtures\NotASubsystem::class,
				'AgentPorter\\Does\\Not\\Exist',
			)
		);
		$plugin->boot();

		$this->assertSame( array( 'good' ), $plugin->booted_subsystems() );
		$this->assertSame(
			array(
				'class:' . Fixtures\ThrowsInConstructorSubsystem::class,
				'throws-on-register',
				'class:' . Fixtures\InvalidIdSubsystem::class,
				'class:' . Fixtures\DuplicateIdSubsystem::class,
				'class:' . Fixtures\NotASubsystem::class,
				'class:AgentPorter\\Does\\Not\\Exist',
			),
			array_keys( $plugin->faults()->disabled() )
		);
		$this->assertFalse( $plugin->faults()->is_disabled( 'good' ) );
		$this->assertFalse( $plugin->faults()->is_locked_down() );
	}

	public function test_duplicate_id_does_not_disable_the_original(): void {
		$plugin = $this->plugin( array( Fixtures\GoodSubsystem::class, Fixtures\DuplicateIdSubsystem::class ) );
		$plugin->boot();
		$this->assertSame( array( 'good' ), $plugin->booted_subsystems() );
		$this->assertFalse( $plugin->faults()->is_disabled( 'good' ) );
	}

	public function test_subsystem_id_is_read_once_so_an_unstable_id_cannot_claim_another(): void {
		$plugin = $this->plugin( array( Fixtures\GoodSubsystem::class, Fixtures\ShiftingIdSubsystem::class ) );
		$plugin->boot();
		$this->assertTrue( $plugin->faults()->is_disabled( 'shifter' ) );
		$this->assertFalse( $plugin->faults()->is_disabled( 'good' ) );
	}

	public function test_critical_boot_failure_locks_down_and_dependents_do_not_boot(): void {
		$plugin = $this->plugin( array( Fixtures\CriticalThrowsOnRegisterSubsystem::class, Fixtures\DependentSubsystem::class ) );
		$plugin->boot();
		$this->assertTrue( $plugin->faults()->is_locked_down() );
		$this->assertSame( array(), $plugin->booted_subsystems() );
		$this->assertTrue( $plugin->faults()->is_disabled( 'serve' ) );
	}

	public function test_critical_constructor_failure_locks_down(): void {
		$plugin = $this->plugin( array( Fixtures\CriticalThrowsInConstructorSubsystem::class ) );
		$plugin->boot();
		$this->assertTrue( $plugin->faults()->is_locked_down() );
	}

	public function test_dependency_must_boot_first(): void {
		$plugin = $this->plugin( array( Fixtures\DependentSubsystem::class, Fixtures\CriticalGuardSubsystem::class ) );
		$plugin->boot();
		$this->assertSame( array( 'guard' ), $plugin->booted_subsystems() );
		$this->assertTrue( $plugin->faults()->is_disabled( 'serve' ) );
		$this->assertFalse( $plugin->faults()->is_locked_down(), 'serve is not security-critical.' );
	}

	public function test_dependent_boots_when_dependency_is_healthy(): void {
		$plugin = $this->plugin( array( Fixtures\CriticalGuardSubsystem::class, Fixtures\DependentSubsystem::class ) );
		$plugin->boot();
		$this->assertSame( array( 'guard', 'serve' ), $plugin->booted_subsystems() );
	}

	public function test_services_of_a_failed_subsystem_are_rolled_back(): void {
		$plugin = $this->plugin( array( Fixtures\RegistersServiceThenThrowsSubsystem::class ) );
		$plugin->boot();
		$this->assertFalse( $this->container->has( \SplObjectStorage::class ) );
	}

	public function test_container_is_sealed_after_boot(): void {
		$this->plugin( array( Fixtures\GoodSubsystem::class ) )->boot();
		$this->expectException( ContainerException::class );
		$this->container->set( \ArrayObject::class, static fn() => new \ArrayObject() );
	}

	public function test_booted_action_receives_read_only_status(): void {
		Actions\expectDone( 'agentporter_booted' )->once()->with(
			array(
				'booted'      => array( 'good' ),
				'disabled'    => array( 'throws-on-register' ),
				'locked_down' => false,
			)
		);
		$this->plugin( array( Fixtures\GoodSubsystem::class, Fixtures\ThrowsOnRegisterSubsystem::class ) )->boot();
		$this->addToAssertionCount( 1 );
	}

	public function test_throwing_booted_listener_does_not_escape(): void {
		Actions\expectDone( 'agentporter_booted' )->once()->whenHappen(
			static function () {
				throw new \RuntimeException( 'listener' );
			}
		);
		$plugin = $this->plugin( array( Fixtures\GoodSubsystem::class ) );
		$plugin->boot();
		$this->assertTrue( $plugin->is_booted() );
	}

	public function test_boot_is_idempotent(): void {
		$plugin = $this->plugin( array( Fixtures\GoodSubsystem::class ) );
		$this->assertFalse( $plugin->is_booted() );
		$plugin->boot();
		$plugin->boot();
		$this->assertTrue( $plugin->is_booted() );
		$this->assertSame( array( 'good' ), $plugin->booted_subsystems() );
		$this->assertSame( 1, did_action( 'agentporter_booted' ) );
	}

	public function test_default_wiring(): void {
		$plugin = Plugin::instance();
		$this->assertSame( $plugin, Plugin::instance() );
		$this->assertSame( $plugin->faults(), $plugin->container()->get( Faults::class ) );
	}

	public function test_subsystem_classes_that_fail_to_load_are_disabled_and_others_boot(): void {
		$plugin = $this->plugin(
			array(
				'AgentPorter\\Tests\\Broken\\MissingInterfaceSubsystem',
				'AgentPorter\\Tests\\Broken\\TopLevelThrowSubsystem',
				'AgentPorter\\Tests\\Broken\\ParseErrorSubsystem',
				Fixtures\GoodSubsystem::class,
			)
		);
		$plugin->boot();
		$this->assertSame( array( 'good' ), $plugin->booted_subsystems() );
		$this->assertCount( 3, $plugin->faults()->disabled() );
		$this->assertFalse( $plugin->faults()->is_locked_down() );
	}

	public function test_unloadable_critical_subsystem_locks_down(): void {
		$missing = 'AgentPorter\\Tests\\Broken\\GuardThatWasDeleted';
		$plugin  = $this->plugin( array( $missing, Fixtures\GoodSubsystem::class ), array( $missing ) );
		$plugin->boot();
		$this->assertTrue( $plugin->faults()->is_locked_down() );
		$this->assertSame( array( 'good' ), $plugin->booted_subsystems() );
	}

	public function test_failure_listener_during_boot_cannot_register_services(): void {
		$container = null;
		\Brain\Monkey\Actions\expectDone( 'agentporter_subsystem_failed' )->once()->whenHappen(
			function () use ( &$container ) {
				$this->container->set( \SplQueue::class, static fn() => new \SplQueue() );
			}
		);
		$plugin = $this->plugin( array( Fixtures\ThrowsOnRegisterSubsystem::class, Fixtures\GoodSubsystem::class ) );
		$plugin->boot();
		$this->assertFalse( $this->container->has( \SplQueue::class ), 'Listener ran after the seal, so set() was refused.' );
		$this->assertSame( array( 'good' ), $plugin->booted_subsystems() );
	}

	public function test_runtime_dependency_failure_disables_dependent_and_locks_down_when_critical(): void {
		$plugin = $this->plugin( array( Fixtures\KeysSubsystem::class, Fixtures\CriticalDependentGuardSubsystem::class ) );
		$plugin->boot();
		$this->assertFalse( $plugin->faults()->is_locked_down() );
		$plugin->faults()->disable( 'keys', new \RuntimeException( 'runtime' ) );
		$this->assertTrue( $plugin->faults()->is_disabled( 'agentguard' ), 'Cascades to the dependent.' );
		$this->assertSame( 'dependency:keys', $plugin->faults()->disabled()['agentguard'] );
		$this->assertTrue( $plugin->faults()->is_locked_down(), 'Reviewer M1: a critical dependent going dead must lock down.' );
	}

	public function test_subsystem_that_seals_the_container_then_fails_cannot_escape_boot(): void {
		$plugin = $this->plugin( array( Fixtures\SealsThenThrowsSubsystem::class, Fixtures\GoodSubsystem::class ) );
		$plugin->boot();
		$this->assertTrue( $plugin->faults()->is_locked_down() );
		$this->assertTrue( $plugin->faults()->is_disabled( 'sealer' ) );
		$this->assertTrue( $plugin->is_booted() );
	}

	public function test_critical_class_missing_from_boot_list_locks_down(): void {
		$plugin = $this->plugin( array( Fixtures\GoodSubsystem::class ), array( Fixtures\CriticalGuardSubsystem::class ) );
		$plugin->boot();
		$this->assertTrue( $plugin->faults()->is_locked_down() );
	}

	public function test_shipped_critical_subsystems_are_booted_and_marked(): void {
		$this->addToAssertionCount( 1 ); // P0-2 ships no critical subsystem yet; the loops guard future ones.
		foreach ( Plugin::critical_subsystems() as $critical ) {
			$this->assertContains( $critical, Plugin::default_subsystems() );
		}
		foreach ( Plugin::default_subsystems() as $class ) {
			if ( is_subclass_of( $class, \AgentPorter\Core\SecurityCritical::class ) ) {
				$this->assertContains( $class, Plugin::critical_subsystems(), $class . ' implements SecurityCritical but is not listed.' );
			}
		}
	}
}
