<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Security\AgentContext;
use AgentPorter\Security\AgentGuard;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables -- Tests set request headers to prove which ones are trusted.

/**
 * @covers \AgentPorter\Security\AgentContext
 */
final class AgentContextTest extends TestCase {

	/**
	 * Saved $_SERVER.
	 *
	 * @var array<string, mixed>
	 */
	private array $server = array();

	protected function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( null );
	}

	protected function tear_down(): void {
		$_SERVER = $this->server;
		parent::tear_down();
	}

	public function test_from_request_uses_remote_addr_and_ignores_forwarded_headers(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.2';
		$_SERVER['HTTP_CLIENT_IP']       = '198.51.100.3';
		$_SERVER['HTTP_FORWARDED']       = 'for=198.51.100.4';
		$context                         = AgentContext::from_request();
		$this->assertSame( '203.0.113.9', $context->ip );
		$this->assertSame( 7, $context->user_id );
		$this->assertNull( $context->scopes );
	}

	public function test_invalid_or_missing_remote_addr_becomes_empty_and_maps_to_unknown_bucket(): void {
		foreach ( array( 'not-an-ip', '203.0.113.9, 198.51.100.1', '' ) as $value ) {
			$_SERVER['REMOTE_ADDR'] = $value;
			$this->assertSame( '', AgentContext::from_request()->ip, $value );
		}
		unset( $_SERVER['REMOTE_ADDR'] );
		$context = AgentContext::from_request();
		$this->assertSame( '', $context->ip );
		$this->assertSame( 'unknown', AgentGuard::network_of( $context->ip ) );
	}

	public function test_application_password_requests_are_labelled(): void {
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( 'uuid-1' );
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
		$context                = AgentContext::from_request();
		$this->assertSame( 'app_password', $context->actor_type );
		$this->assertSame( '2001:db8::1', $context->ip );
	}
}
