<?php
/**
 * Boot and runtime failure boundaries against real WordPress hooks.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Integration\Core;

use AgentPorter\Core\Container;
use AgentPorter\Core\Faults;
use AgentPorter\Core\FaultNotice;
use AgentPorter\Core\Logger;
use AgentPorter\Core\Requirements;
use AgentPorter\Plugin;
use AgentPorter\Tests\Unit\Fixtures;

require_once dirname( __DIR__, 2 ) . '/unit/Fixtures/Subsystems.php';

/**
 * @covers \AgentPorter\Plugin
 * @covers \AgentPorter\Core\Hooks
 * @covers \AgentPorter\Core\FaultNotice
 */
final class BootBoundaryTest extends \WP_UnitTestCase {

	public function test_real_plugin_instance_is_booted(): void {
		$this->assertTrue( Plugin::instance()->is_booted() );
		$this->assertSame( array(), Plugin::instance()->faults()->disabled() );
	}

	public function test_exception_in_one_subsystem_disables_only_that_subsystem(): void {
		$faults = new Faults( new Logger( false ) );
		$plugin = new Plugin(
			new Container(),
			$faults,
			array(
				Fixtures\ThrowsOnRegisterSubsystem::class,
				Fixtures\ThrowsAtRuntimeSubsystem::class,
				Fixtures\GoodSubsystem::class,
			)
		);
		$plugin->boot();

		// Boot-time failure: its filter (registered before it threw) must not run.
		// Runtime failure: throws inside apply_filters at priority 5; input passes through unchanged.
		// Good subsystem: still applies its change.
		$this->assertSame( 'start-good', apply_filters( 'agentporter_test_value', 'start' ) );

		$this->assertSame(
			array( 'throws-on-register', 'throws-at-runtime' ),
			array_keys( $faults->disabled() )
		);
		// throws-at-runtime booted fine and only failed later, inside apply_filters.
		$this->assertSame( array( 'throws-at-runtime', 'good' ), $plugin->booted_subsystems() );
		$this->assertGreaterThanOrEqual( 2, did_action( 'agentporter_subsystem_failed' ) );

		// Second call: the runtime-failed subsystem is now a no-op, the good one still works.
		$this->assertSame( 'again-good', apply_filters( 'agentporter_test_value', 'again' ) );
	}

	public function test_guard_filter_fails_closed_after_unrelated_runtime_failure(): void {
		$faults = new Faults( new Logger( false ) );
		( new Plugin( new Container(), $faults, array( Fixtures\CriticalGuardSubsystem::class, Fixtures\DependentSubsystem::class ) ) )->boot();

		$this->assertTrue( apply_filters( 'agentporter_test_allow', null ) );
		$this->assertSame( 'x-serve', apply_filters( 'agentporter_test_value', 'x' ) );

		do_action( 'agentporter_test_trigger' ); // Unrelated action of the guard subsystem throws.

		$this->assertFalse( apply_filters( 'agentporter_test_allow', null ), 'Reviewer repro: must stay denied.' );
		$this->assertSame( 'x', apply_filters( 'agentporter_test_value', 'x' ), 'Dependent stops when its dependency fails.' );
		$this->assertTrue( $faults->is_locked_down() );
	}

	public function test_wordpress_die_exception_propagates_through_wrapped_filter(): void {
		$faults = new Faults( new Logger( false ) );
		$hooks  = new \AgentPorter\Core\Hooks( 'policy', $faults );
		$hooks->add_filter(
			'agentporter_test_value',
			static function () {
				wp_die( 'Denied by policy' );
			}
		);
		try {
			apply_filters( 'agentporter_test_value', 'x' );
			$this->fail( 'wp_die must not be swallowed.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Denied by policy', $e->getMessage() );
		}
		$this->assertFalse( $faults->is_disabled( 'policy' ) );
	}

	public function test_action_registered_before_boot_failure_never_runs(): void {
		Fixtures\ActionThenThrowsSubsystem::$runs = 0;
		( new Plugin( new Container(), new Faults( new Logger( false ) ), array( Fixtures\ActionThenThrowsSubsystem::class ) ) )->boot();
		do_action( 'agentporter_test_action' );
		$this->assertSame( 0, Fixtures\ActionThenThrowsSubsystem::$runs );
	}

	public function test_fault_notice_is_hidden_from_non_admins_and_off_screen(): void {
		$faults = new Faults( new Logger( false ) );
		$faults->disable( 'tracking', new \RuntimeException( '/secret/path' ) );
		$notice = new FaultNotice( $faults );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_current_screen( 'plugins' );
		$this->assertSame( '', $this->capture( array( $notice, 'render' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->capture( array( $notice, 'render' ) ) );
	}

	public function test_fault_notice_names_module_without_exception_details(): void {
		$faults = new Faults( new Logger( false ) );
		$faults->disable( 'tracking', new \RuntimeException( '/secret/path' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		set_current_screen( 'plugins' );
		$html = $this->capture( array( new FaultNotice( $faults ), 'render' ) );
		$this->assertStringContainsString( 'tracking', $html );
		$this->assertStringNotContainsString( 'secret', $html );
		$this->assertStringNotContainsString( 'RuntimeException', $html );
		$this->assertStringNotContainsString( 'WP_DEBUG_LOG', $html, 'Do not push admins toward a public debug.log.' );
	}

	public function test_fault_notice_shows_on_network_plugins_screen(): void {
		if ( ! is_multisite() ) {
			$this->assertNotFalse( has_action( 'network_admin_notices' ) );
			return;
		}
		$faults = new Faults( new Logger( false ) );
		$faults->disable( 'tracking', new \RuntimeException( 'x' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		grant_super_admin( get_current_user_id() );
		set_current_screen( 'plugins-network' );
		$this->assertStringContainsString( 'tracking', $this->capture( array( new FaultNotice( $faults ), 'render' ) ) );
	}

	public function test_requirements_notice_renders_for_admins_on_plugins_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		set_current_screen( 'plugins' );
		$html = $this->capture( array( new Requirements( '8.0.30', '6.8' ), 'render_notice' ) );
		$this->assertStringContainsString( 'PHP 8.1 or later', $html );
		$this->assertStringContainsString( 'WordPress 6.9 or later', $html );
	}

	/**
	 * Captures output of a callable.
	 *
	 * @param callable $callback Callback.
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		remove_all_filters( 'agentporter_test_value' );
		remove_all_filters( 'agentporter_test_allow' );
		remove_all_actions( 'agentporter_test_trigger' );
		remove_all_actions( 'agentporter_test_action' );
		parent::tear_down();
	}
}
