<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Core\Faults;
use AgentPorter\Core\Logger;
use AgentPorter\Core\Schema;
use AgentPorter\Security\AgentContext;
use AgentPorter\Security\AgentGuard;
use AgentPorter\Security\AuditLog;
use AgentPorter\Security\KillSwitch;
use AgentPorter\Security\RateLimiter;
use AgentPorter\Security\Redactor;
use AgentPorter\Tests\Unit\Fixtures\MemoryRateStore;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Fixtures/WordPressDoubles.php';
require_once dirname( __DIR__ ) . '/Fixtures/MemoryRateStore.php';

/**
 * @covers \AgentPorter\Security\AgentGuard
 * @covers \AgentPorter\Security\AgentContext
 */
final class AgentGuardTest extends TestCase {

	private const ABILITY = 'agentporter/site-info';

	/**
	 * Options returned by get_option().
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Capabilities granted per user id.
	 *
	 * @var array<int, list<string>>
	 */
	private array $caps = array();

	/**
	 * Fake database (records audit rows).
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Failure state.
	 *
	 * @var Faults
	 */
	private Faults $faults;

	/**
	 * Rate store.
	 *
	 * @var MemoryRateStore
	 */
	private MemoryRateStore $rates;

	protected function set_up(): void {
		parent::set_up();
		$this->options = array( AgentGuard::ENABLED_OPTION => array( self::ABILITY ) );
		$this->caps    = array( 7 => array( AgentGuard::CAP_READ ) );
		$this->wpdb    = new \wpdb();
		$this->faults  = new Faults( new Logger( false ) );
		$this->rates   = new MemoryRateStore();
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
		Functions\when( 'user_can' )->alias( fn( $user, $cap ) => in_array( $cap, $this->caps[ (int) $user ] ?? array(), true ) );
		Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
	}

	private function guard( bool $kill_switch_constant = false ): AgentGuard {
		return new AgentGuard(
			$this->faults,
			new KillSwitch( $kill_switch_constant ),
			new RateLimiter( $this->rates ),
			new AuditLog( $this->wpdb, new Schema( $this->wpdb ), new Redactor() ),
			new Logger( false )
		);
	}

	private function context( int $user = 7, ?array $scopes = null, string $ip = '203.0.113.9' ): AgentContext {
		return new AgentContext( 'app_password', $user, $ip, $scopes );
	}

	/**
	 * Asserts a denial with the given code and status.
	 *
	 * @param mixed  $result Guard result.
	 * @param string $code   Expected error code.
	 * @param int    $status Expected HTTP status.
	 */
	private function assertDenied( $result, string $code, int $status = 403 ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	public function test_denies_everything_when_locked_down(): void {
		$this->faults->lock_down();
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_unavailable', 503 );
	}

	public function test_denies_when_kill_switch_option_is_on(): void {
		$this->options[ KillSwitch::OPTION ] = '1';
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_agents_disabled' );
	}

	public function test_denies_when_kill_switch_constant_is_set(): void {
		$this->assertDenied( $this->guard( true )->check( self::ABILITY, $this->context() ), 'agentporter_agents_disabled' );
	}

	public function test_kill_switch_takes_effect_within_the_same_request(): void {
		$guard = $this->guard();
		$this->assertTrue( $guard->check( self::ABILITY, $this->context() ) );
		$this->options[ KillSwitch::OPTION ] = '1';
		$this->assertDenied( $guard->check( self::ABILITY, $this->context() ), 'agentporter_agents_disabled' );
	}

	public function test_denies_disabled_unknown_or_malformed_abilities(): void {
		foreach ( array( 'agentporter/get-post', 'other/site-info', 'agentporter/../x', 'agentporter/Site-Info', str_repeat( 'a', 200 ), '' ) as $ability ) {
			$this->assertDenied( $this->guard()->check( $ability, $this->context() ), 'agentporter_ability_disabled' );
		}
	}

	public function test_corrupt_enabled_list_counts_as_nothing_enabled(): void {
		foreach ( array( 'agentporter/site-info', null, 42, array( array( 'agentporter/site-info' ) ) ) as $value ) {
			$this->options[ AgentGuard::ENABLED_OPTION ] = $value;
			$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_ability_disabled' );
		}
	}

	public function test_denies_anonymous_and_users_without_the_capability(): void {
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context( 0 ) ), 'agentporter_forbidden' );
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context( 99 ) ), 'agentporter_forbidden' );
	}

	public function test_write_abilities_need_the_write_capability(): void {
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context(), true ), 'agentporter_forbidden' );
		$this->caps[7][] = AgentGuard::CAP_WRITE;
		$this->assertTrue( $this->guard()->check( self::ABILITY, $this->context(), true ) );
	}

	public function test_scoped_credentials_are_limited_to_their_scopes(): void {
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context( 7, array( 'agentporter/get-faq' ) ) ), 'agentporter_out_of_scope' );
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context( 7, array() ) ), 'agentporter_out_of_scope' );
		$this->assertTrue( $this->guard()->check( self::ABILITY, $this->context( 7, array( self::ABILITY ) ) ) );
	}

	public function test_denies_with_429_and_retry_after_when_actor_rate_is_exceeded(): void {
		$guard = $this->guard();
		for ( $i = 0; $i < AgentGuard::ACTOR_PER_MIN; $i++ ) {
			$this->assertTrue( $guard->check( self::ABILITY, $this->context() ) );
		}
		$denied = $guard->check( self::ABILITY, $this->context() );
		$this->assertDenied( $denied, 'agentporter_rate_limited', 429 );
		$this->assertGreaterThan( 0, $denied->get_error_data()['retry_after'] );
	}

	public function test_ip_rate_limit_applies_across_users_and_never_stores_the_raw_ip(): void {
		$guard = $this->guard();
		for ( $user = 1; $user <= AgentGuard::IP_PER_MIN; $user++ ) {
			$this->caps[ 1000 + $user ] = array( AgentGuard::CAP_READ );
			$this->assertTrue( $guard->check( self::ABILITY, $this->context( 1000 + $user ) ) );
		}
		$this->caps[5000] = array( AgentGuard::CAP_READ );
		$this->assertDenied( $guard->check( self::ABILITY, $this->context( 5000 ) ), 'agentporter_rate_limited', 429 );
		foreach ( array_keys( $this->rates->counts ) as $bucket ) {
			$this->assertStringNotContainsString( '203.0.113.9', $bucket );
		}
	}

	public function test_rate_store_failure_denies(): void {
		$this->rates->fail = true;
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_guard_error' );
	}

	public function test_policy_filter_can_deny_but_never_allow(): void {
		Filters\expectApplied( 'agentporter_guard_decision' )->once()->andReturn( new \WP_Error( 'policy_hours', 'Outside allowed hours' ) );
		$denied = $this->guard()->check( self::ABILITY, $this->context() );
		$this->assertDenied( $denied, 'policy_hours' );
	}

	public function test_policy_filter_returning_anything_but_true_denies(): void {
		foreach ( array( false, 1, 'yes', null, array() ) as $value ) {
			Filters\expectApplied( 'agentporter_guard_decision' )->once()->andReturn( $value );
			$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_policy_denied' );
		}
	}

	public function test_policy_filter_cannot_override_an_earlier_denial(): void {
		Filters\expectApplied( 'agentporter_guard_decision' )->never();
		$this->options[ KillSwitch::OPTION ] = '1';
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_agents_disabled' );
	}

	public function test_exception_anywhere_denies(): void {
		Functions\when( 'user_can' )->alias(
			static function () {
				throw new \RuntimeException( 'capability layer broke' );
			}
		);
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_guard_error' );
	}

	public function test_denials_are_audited_with_truncated_ip_and_generic_message(): void {
		$this->options[ KillSwitch::OPTION ] = '1';
		$denied                              = $this->guard()->check( self::ABILITY, $this->context() );
		$this->assertSame( 'This action is not available.', $denied->get_error_message() );
		$row = $this->wpdb->inserted[0][1];
		$this->assertSame( AuditLog::RESULT_DENIED, $row['result'] );
		$this->assertSame( 'agentporter_agents_disabled', $row['error_code'] );
		$this->assertSame( inet_pton( '203.0.113.0' ), $row['ip_trunc'] );
	}

	public function test_audit_failure_never_turns_a_denial_into_an_allow(): void {
		$this->wpdb->fail                    = true;
		$this->options[ KillSwitch::OPTION ] = '1';
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_agents_disabled' );
	}

	public function test_allows_an_enabled_ability_for_a_permitted_actor(): void {
		$this->assertTrue( $this->guard()->check( self::ABILITY, $this->context() ) );
		$this->assertSame( array(), $this->wpdb->inserted, 'Allowed calls are audited by the ability wrapper after execution, not here.' );
	}
}
