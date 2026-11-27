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
use AgentPorter\Security\GuardDenials;
use Brain\Monkey\Actions;
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
		Functions\when( 'get_userdata' )->alias(
			fn( $user ) => isset( $this->caps[ (int) $user ] )
				? new \WP_User( (int) $user, array_fill_keys( $this->caps[ (int) $user ], true ) )
				: false
		);
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

	public function test_users_without_the_capability_get_one_uniform_code(): void {
		$this->options[ KillSwitch::OPTION ] = '1';
		$denied                              = $this->guard()->check( self::ABILITY, $this->context( 0 ) );
		$this->assertDenied( $denied, 'agentporter_forbidden' );
		$this->assertSame( 'agentporter_agents_disabled', $this->wpdb->inserted[0][1]['error_code'], 'The audit keeps the real reason.' );
		$this->options[ KillSwitch::OPTION ] = '0';
		$this->assertDenied( $this->guard()->check( 'agentporter/not-enabled', $this->context( 0 ) ), 'agentporter_forbidden' );
	}

	public function test_super_admin_style_users_without_an_explicit_grant_are_denied(): void {
		// A super admin passes user_can() for everything; the guard must look only at explicit grants.
		Functions\when( 'user_can' )->justReturn( true );
		$this->caps[50] = array( 'manage_network', 'manage_options' );
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context( 50 ) ), 'agentporter_forbidden' );
	}

	public function test_ability_name_with_trailing_newline_is_rejected(): void {
		$this->options[ AgentGuard::ENABLED_OPTION ] = array( "agentporter/site-info\n" );
		$this->assertDenied( $this->guard()->check( "agentporter/site-info\n", $this->context() ), 'agentporter_ability_disabled' );
	}

	public function test_ipv6_addresses_in_the_same_64_share_a_bucket(): void {
		$this->assertSame( AgentGuard::network_of( '2001:db8:1:2::1' ), AgentGuard::network_of( '2001:db8:1:2:ffff:ffff:ffff:ffff' ) );
		$this->assertNotSame( AgentGuard::network_of( '2001:db8:1:2::1' ), AgentGuard::network_of( '2001:db8:1:3::1' ) );
		$this->assertSame( '203.0.113.9', AgentGuard::network_of( '::ffff:203.0.113.9' ) );
		$this->assertSame( 'unknown', AgentGuard::network_of( '' ) );
		$this->assertSame( 'unknown', AgentGuard::network_of( 'garbage' ) );
		// IPv6 that embeds one IPv4 host (6to4, Teredo) buckets as that host, not as 65k /64s.
		$this->assertSame( '192.0.2.1', AgentGuard::network_of( '2002:c000:0201:1::1' ) );
		$this->assertSame( '192.0.2.1', AgentGuard::network_of( '2002:c000:0201:ffff::1' ) );
		$this->assertSame( '192.0.2.1', AgentGuard::network_of( '2001:0:4136:e378:8000:63bf:3fff:fdfe' ) );
	}

	public function test_rotating_ipv6_within_a_64_hits_the_ip_limit(): void {
		$guard = $this->guard();
		for ( $i = 1; $i <= AgentGuard::IP_PER_MIN; $i++ ) {
			$this->caps[ 2000 + $i ] = array( AgentGuard::CAP_READ );
			$this->assertTrue( $guard->check( self::ABILITY, $this->context( 2000 + $i, null, '2001:db8:aa:bb::' . dechex( $i ) ) ) );
		}
		$this->caps[9000] = array( AgentGuard::CAP_READ );
		$this->assertDenied( $guard->check( self::ABILITY, $this->context( 9000, null, '2001:db8:aa:bb::ffff' ) ), 'agentporter_rate_limited', 429 );
	}

	public function test_user_bucket_does_not_depend_on_actor_type(): void {
		$guard = $this->guard();
		$types = array( 'app_password', 'user', 'cli', 'webmcp' );
		for ( $i = 0; $i < AgentGuard::ACTOR_PER_MIN; $i++ ) {
			$this->assertTrue( $guard->check( self::ABILITY, new AgentContext( $types[ $i % 4 ], 7, '198.51.100.' . ( $i % 200 ) ) ) );
		}
		$this->assertDenied( $guard->check( self::ABILITY, new AgentContext( 'agent_key', 7, '198.51.100.250' ) ), 'agentporter_rate_limited', 429 );
	}

	public function test_denied_calls_write_a_bounded_number_of_audit_rows(): void {
		$guard = $this->guard();
		for ( $i = 0; $i < 200; $i++ ) {
			$guard->check( self::ABILITY, $this->context( 0 ) );
		}
		$this->assertSame( AgentGuard::AUDIT_DENIALS, count( $this->wpdb->inserted ) );
	}

	public function test_denial_audit_has_a_global_ceiling_across_networks(): void {
		$guard = $this->guard();
		for ( $i = 0; $i < 2000; $i++ ) { // 2000 distinct /64s, as from a free /48.
			$guard->check( self::ABILITY, $this->context( 0, null, sprintf( '2001:db8:0:%x::1', $i ) ) );
		}
		$codes = array_column( array_column( $this->wpdb->inserted, 1 ), 'error_code' );
		$this->assertSame( AgentGuard::AUDIT_SITE_MAX, count( array_keys( $codes, 'agentporter_forbidden', true ) ) );
		$this->assertSame( array( AgentGuard::SUPPRESSED_CODE ), array_values( array_diff( $codes, array( 'agentporter_forbidden' ) ) ), 'one suppression marker' );
		$network_counters = array_filter( array_keys( $this->rates->counts ), static fn( $k ) => str_contains( $k, 'audit-deny:' ) && ! str_contains( $k, 'site' ) );
		$this->assertLessThanOrEqual( AgentGuard::AUDIT_SITE_MAX, count( $network_counters ), 'no per-network counters past the ceiling' );
	}

	public function test_authenticated_denials_are_audited_despite_anonymous_flood_from_same_network(): void {
		$guard = $this->guard();
		for ( $i = 0; $i < 500; $i++ ) {
			$guard->check( self::ABILITY, $this->context( 0 ) );
		}
		$before = count( $this->wpdb->inserted );
		$this->assertDenied( $guard->check( self::ABILITY, $this->context( 7, array( 'agentporter/other' ) ) ), 'agentporter_out_of_scope' );
		$this->assertCount( $before + 1, $this->wpdb->inserted );
		$this->assertSame( 'agentporter_out_of_scope', end( $this->wpdb->inserted )[1]['error_code'] );
	}

	public function test_suppressed_denials_leave_one_marker_per_user_window(): void {
		$guard = $this->guard();
		for ( $i = 0; $i < 100; $i++ ) {
			$guard->check( self::ABILITY, $this->context( 7, array( 'agentporter/other' ) ) );
		}
		$codes = array_column( array_column( $this->wpdb->inserted, 1 ), 'error_code' );
		$this->assertSame( AgentGuard::AUDIT_DENIALS, count( array_keys( $codes, 'agentporter_out_of_scope', true ) ) );
		$this->assertSame( 1, count( array_keys( $codes, AgentGuard::SUPPRESSED_CODE, true ) ) );
		$marker = end( $this->wpdb->inserted )[1];
		$this->assertSame( 7, $marker['actor_id'] );
		$this->assertSame( '{"scope":"user"}', $marker['input_redacted'] );
	}

	public function test_static_authorize_failure_returns_uniform_forbidden_to_unauthorized_callers(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		// No booted plugin/guard in unit tests: the static entry point must fail closed.
		$this->assertDenied( AgentGuard::authorize( self::ABILITY, $this->context( 0 ) ), 'agentporter_forbidden' );
		$this->assertDenied( AgentGuard::authorize( self::ABILITY, $this->context( 42 ) ), 'agentporter_forbidden' );
		$this->assertDenied( AgentGuard::authorize( self::ABILITY, $this->context( 7 ) ), 'agentporter_unavailable', 503 );
		$this->assertDenied( AgentGuard::authorize( self::ABILITY ), 'agentporter_forbidden' );
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

	public function test_policy_listener_can_deny_with_a_sanitised_code(): void {
		Actions\expectDone( 'agentporter_guard_check' )->once()->whenHappen(
			static function ( GuardDenials $denials ) {
				$denials->deny( 'Outside Hours for bob@example.com!' );
			}
		);
		$denied = $this->guard()->check( self::ABILITY, $this->context() );
		$this->assertDenied( $denied, 'agentporter_policy_outsidehoursforbobexamplecom' );
		$this->assertSame( 'This action is not available.', $denied->get_error_message(), 'Policy text never reaches the caller.' );
		$this->assertSame( array( 'status' => 403 ), $denied->get_error_data() );
	}

	public function test_later_policy_listener_cannot_cancel_an_earlier_denial(): void {
		Actions\expectDone( 'agentporter_guard_check' )->once()->whenHappen(
			static function ( GuardDenials $denials ) {
				$denials->deny( 'membership' );
				// A later listener has no way to remove it: the collector only adds.
			}
		);
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_policy_membership' );
	}

	public function test_policy_cannot_override_an_earlier_built_in_denial(): void {
		Actions\expectDone( 'agentporter_guard_check' )->never();
		$this->options[ KillSwitch::OPTION ] = '1';
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_agents_disabled' );
	}

	public function test_throwing_policy_listener_denies(): void {
		Actions\expectDone( 'agentporter_guard_check' )->once()->whenHappen(
			static function () {
				throw new \RuntimeException( 'policy broke' );
			}
		);
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_guard_error' );
	}

	public function test_exception_anywhere_denies(): void {
		Functions\when( 'get_userdata' )->alias(
			static function () {
				throw new \RuntimeException( 'capability layer broke' );
			}
		);
		$this->assertDenied( $this->guard()->check( self::ABILITY, $this->context() ), 'agentporter_forbidden' );
		$this->assertSame( 'agentporter_guard_error', $this->wpdb->inserted[0][1]['error_code'] );
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
