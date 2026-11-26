<?php
/**
 * Tests for the deprecation gate itself.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Support;

use AgentPorter\Tests\Support\DeprecationGate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgentPorter\Tests\Support\DeprecationGate
 */
final class DeprecationGateTest extends TestCase {

	/**
	 * Plugin root.
	 *
	 * @var string
	 */
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = (string) realpath( dirname( __DIR__, 3 ) );
		DeprecationGate::reset();
	}

	public function test_source_files_count_as_owned(): void {
		$this->assertTrue( DeprecationGate::is_owned( $this->root . '/src/Anything.php' ) );
		$this->assertTrue( DeprecationGate::is_owned( $this->root . '/agentporter.php' ) );
		$this->assertTrue( DeprecationGate::is_owned( $this->root . '/uninstall.php' ) );
	}

	public function test_third_party_and_test_files_are_not_owned(): void {
		$this->assertFalse( DeprecationGate::is_owned( $this->root . '/vendor/foo/bar.php' ) );
		$this->assertFalse( DeprecationGate::is_owned( $this->root . '/tests/unit/bootstrap.php' ) );
		$this->assertFalse( DeprecationGate::is_owned( '/tmp/wordpress/wp-includes/functions.php' ) );
		$this->assertFalse( DeprecationGate::is_owned( '' ) );
		// A sibling directory sharing the prefix must not count.
		$this->assertFalse( DeprecationGate::is_owned( $this->root . '/src-other/file.php' ) );
	}

	public function test_deprecation_from_owned_code_is_recorded_and_thrown(): void {
		try {
			DeprecationGate::handle( E_DEPRECATED, 'strlen(): Passing null is deprecated', $this->root . '/src/Example.php', 12 );
			$this->fail( 'Expected the gate to throw.' );
		} catch ( \ErrorException $e ) {
			$this->assertStringContainsString( 'AgentPorter deprecation gate', $e->getMessage() );
		}
		$issues = DeprecationGate::issues();
		DeprecationGate::reset(); // Do not fail the real run because of this simulated issue.
		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '[deprecation]', $issues[0] );
	}

	public function test_notices_and_warnings_from_owned_code_also_count(): void {
		foreach ( array( E_NOTICE, E_WARNING, E_USER_DEPRECATED ) as $level ) {
			try {
				DeprecationGate::handle( $level, 'issue', $this->root . '/src/Example.php', 1 );
			} catch ( \ErrorException $e ) {
				unset( $e );
			}
		}
		$count = count( DeprecationGate::issues() );
		DeprecationGate::reset();
		$this->assertSame( 3, $count );
	}

	public function test_deprecation_from_third_party_code_is_ignored(): void {
		$handled = DeprecationGate::handle( E_DEPRECATED, 'core deprecation', '/tmp/wordpress/wp-includes/class-wp.php', 5 );
		$this->assertFalse( $handled );
		$this->assertSame( array(), DeprecationGate::issues() );
	}

	public function test_gate_is_the_active_error_handler(): void {
		$this->assertTrue( DeprecationGate::is_active() );
	}

	public function test_wordpress_notice_without_plugin_code_on_stack_is_ignored(): void {
		DeprecationGate::record_wordpress_notice( 'doing_it_wrong_run', 'register_rest_route' );
		$this->assertSame( array(), DeprecationGate::issues() );
	}
}
