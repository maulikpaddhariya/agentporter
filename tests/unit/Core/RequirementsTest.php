<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Core;

use AgentPorter\Core\Requirements;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \AgentPorter\Core\Requirements
 */
final class RequirementsTest extends TestCase {

	public function test_old_php_fails(): void {
		$requirements = new Requirements( '8.0.30', '7.1.0' );
		$this->assertFalse( $requirements->met() );
		$this->assertSame( array( 'php' ), $requirements->failures() );
		$this->assertStringContainsString( 'PHP 8.1 or later', $requirements->message() );
	}

	public function test_old_wordpress_fails(): void {
		$requirements = new Requirements( '8.3.0', '6.8.3' );
		$this->assertSame( array( 'wp' ), $requirements->failures() );
		$this->assertStringContainsString( 'WordPress 6.9 or later', $requirements->message() );
	}

	public function test_both_failures_are_reported(): void {
		$requirements = new Requirements( '7.4.33', '6.4' );
		$this->assertSame( array( 'php', 'wp' ), $requirements->failures() );
		$this->assertStringContainsString( ' and ', $requirements->message() );
	}

	public function test_wordpress_pre_release_of_minimum_is_rejected(): void {
		$this->assertFalse( ( new Requirements( '8.1.0', '6.9-RC1' ) )->met() );
	}

	public function test_garbage_versions_fail_closed(): void {
		$this->assertFalse( ( new Requirements( '', '' ) )->met() );
		$this->assertFalse( ( new Requirements( 'abc', 'xyz' ) )->met() );
	}

	public function test_minimum_versions_pass(): void {
		$this->assertTrue( ( new Requirements( '8.1.0', '6.9' ) )->met() );
		$this->assertTrue( ( new Requirements( '8.5.1', '7.1.5' ) )->met() );
		$this->assertSame( array(), ( new Requirements( '8.1.0', '6.9.0' ) )->failures() );
	}

	public function test_notice_hidden_from_users_who_cannot_manage_plugins(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_current_screen' )->never();
		$this->expectOutputString( '' );
		( new Requirements( '8.0.0', '7.0' ) )->render_notice();
	}

	public function test_notice_hidden_on_unrelated_screens(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-post' ) );
		$this->expectOutputString( '' );
		( new Requirements( '8.0.0', '7.0' ) )->render_notice();
	}

	public function test_notice_shown_and_escaped_on_plugins_screen(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		ob_start();
		( new Requirements( '8.3.0', '6.8<script>alert(1)</script>' ) )->render_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'WordPress 6.9 or later', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_failure_hooks_notice_and_blocks_activation(): void {
		$requirements = new Requirements( '8.0.0', '7.0' );
		Functions\expect( 'register_activation_hook' )->once()->with( '/x/agentporter.php', array( $requirements, 'block_activation' ) );
		$requirements->hook_failure( '/x/agentporter.php' );
		$this->assertNotFalse( has_action( 'admin_notices', array( $requirements, 'render_notice' ) ) );
		$this->assertNotFalse( has_action( 'network_admin_notices', array( $requirements, 'render_notice' ) ) );
	}

	public function test_block_activation_dies_with_message(): void {
		Functions\expect( 'wp_die' )->once()->andReturnUsing(
			function ( $message ) {
				$this->assertStringContainsString( 'PHP 8.1 or later', $message );
				throw new \RuntimeException( 'died' );
			}
		);
		$this->expectException( \RuntimeException::class );
		( new Requirements( '8.0.0', '7.0' ) )->block_activation();
	}
}
