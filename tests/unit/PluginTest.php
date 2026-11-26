<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit;

use AgentPorter\Core\Container;
use AgentPorter\Core\Faults;
use AgentPorter\Core\Logger;
use AgentPorter\Plugin;
use AgentPorter\Tests\Unit\Fixtures;

require_once __DIR__ . '/Fixtures/Subsystems.php';

/**
 * @covers \AgentPorter\Plugin
 */
final class PluginTest extends TestCase {

	/**
	 * Builds a plugin with the given subsystems.
	 *
	 * @param array $subsystems Subsystem classes.
	 */
	private function plugin( array $subsystems ): Plugin {
		return new Plugin( new Container(), new Faults( new Logger( false ) ), $subsystems );
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
		$disabled = $plugin->faults()->disabled();
		$this->assertArrayHasKey( 'throwsinconstructorsubsystem', $disabled );
		$this->assertArrayHasKey( 'throws-on-register', $disabled );
		$this->assertArrayHasKey( 'invalididsubsystem', $disabled );
		$this->assertArrayHasKey( 'duplicateidsubsystem', $disabled );
		$this->assertArrayHasKey( 'notasubsystem', $disabled );
		$this->assertArrayHasKey( 'exist', $disabled );
		$this->assertFalse( $plugin->faults()->is_disabled( 'good' ) );
	}

	public function test_duplicate_id_does_not_disable_the_original(): void {
		$plugin = $this->plugin( array( Fixtures\GoodSubsystem::class, Fixtures\DuplicateIdSubsystem::class ) );
		$plugin->boot();
		$this->assertSame( array( 'good' ), $plugin->booted_subsystems() );
		$this->assertFalse( $plugin->faults()->is_disabled( 'good' ) );
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
		$this->assertInstanceOf( Faults::class, $plugin->container()->get( Faults::class ) );
		$this->assertSame( $plugin->faults(), $plugin->container()->get( Faults::class ) );
	}
}
