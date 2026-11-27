<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Core;

use AgentPorter\Core\Faults;
use AgentPorter\Core\Logger;
use AgentPorter\Tests\Unit\TestCase;

/**
 * @covers \AgentPorter\Core\Faults
 * @covers \AgentPorter\Core\Logger
 */
final class FaultsTest extends TestCase {

	/**
	 * Temporary error log.
	 *
	 * @var string
	 */
	private string $log_file = '';

	/**
	 * Previous error_log ini value.
	 *
	 * @var string
	 */
	private string $previous_log = '';

	protected function set_up(): void {
		parent::set_up();
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'aplog' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test captures the debug sink.
	}

	protected function tear_down(): void {
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore.
		if ( is_file( $this->log_file ) ) {
			unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temp file.
		}
		parent::tear_down();
	}

	public function test_log_line_omits_message_and_absolute_path(): void {
		$faults = new Faults( new Logger( true ) );
		$faults->disable( 'tracking', new \RuntimeException( 'token=sk_live_SECRET user@example.com 203.0.113.9' ) );
		$log = (string) file_get_contents( $this->log_file );
		$this->assertStringContainsString( 'subsystem "tracking" disabled', $log );
		$this->assertStringContainsString( 'RuntimeException', $log );
		$this->assertStringNotContainsString( 'SECRET', $log );
		$this->assertStringNotContainsString( 'example.com', $log );
		$this->assertStringNotContainsString( '203.0.113.9', $log );
		$this->assertStringNotContainsString( dirname( __DIR__, 3 ), $log, 'No absolute plugin path.' );
	}

	public function test_logging_is_off_unless_enabled(): void {
		( new Faults( new Logger( false ) ) )->disable( 'tracking', new \RuntimeException( 'x' ) );
		$this->assertSame( '', (string) file_get_contents( $this->log_file ) );
	}

	public function test_relative_path_never_returns_paths_outside_known_roots(): void {
		$this->assertSame( 'secret.php', Faults::relative_path( '/home/someone/private/secret.php' ) );
	}

	public function test_failure_of_critical_subsystem_locks_down(): void {
		$faults = new Faults( new Logger( false ) );
		$faults->mark_critical( 'guard' );
		$this->assertFalse( $faults->is_locked_down() );
		$faults->disable( 'guard', new \RuntimeException( 'x' ) );
		$this->assertTrue( $faults->is_locked_down() );
	}

	public function test_marking_an_already_failed_subsystem_critical_locks_down(): void {
		$faults = new Faults( new Logger( false ) );
		$faults->disable( 'guard', new \RuntimeException( 'x' ) );
		$faults->mark_critical( 'guard' );
		$this->assertTrue( $faults->is_locked_down() );
	}

	public function test_non_critical_failure_does_not_lock_down(): void {
		$faults = new Faults( new Logger( false ) );
		$faults->mark_critical( 'guard' );
		$faults->disable( 'tracking', new \RuntimeException( 'x' ) );
		$this->assertFalse( $faults->is_locked_down() );
	}
}
