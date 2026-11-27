<?php
/**
 * Security core against real WordPress, database and options.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Integration\Security;

use AgentPorter\Core\Lock;
use AgentPorter\Core\Migrator;
use AgentPorter\Core\Schema;
use AgentPorter\Plugin;
use AgentPorter\Security\AgentContext;
use AgentPorter\Security\AgentGuard;
use AgentPorter\Security\AuditLog;
use AgentPorter\Security\DatabaseRateStore;
use AgentPorter\Security\KillSwitch;
use AgentPorter\Security\RateLimiter;
use AgentPorter\Security\Redactor;
use AgentPorter\Security\SecretStore;

/**
 * @covers \AgentPorter\Security\AgentGuard
 * @covers \AgentPorter\Security\DatabaseRateStore
 * @covers \AgentPorter\Security\AuditLog
 * @covers \AgentPorter\Security\SecretStore
 * @covers \AgentPorter\Security\SecuritySubsystem
 */
final class SecurityCoreTest extends \WP_UnitTestCase {

	private const ABILITY = 'agentporter/site-info';

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		( new Migrator( $wpdb, new Schema( $wpdb ), new Lock( $wpdb, 'migrate' ) ) )->migrate( true );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . Schema::RATE ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . Schema::AUDIT ) );
		delete_option( KillSwitch::OPTION );
		update_option( AgentGuard::ENABLED_OPTION, array( self::ABILITY ) );
	}

	private function agent( array $caps = array( AgentGuard::CAP_READ ) ): int {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$wp   = new \WP_User( $user );
		foreach ( $caps as $cap ) {
			$wp->add_cap( $cap );
		}
		return $user;
	}

	public function test_security_subsystem_is_booted_and_critical(): void {
		$this->assertContains( 'security', Plugin::instance()->booted_subsystems() );
		$this->assertTrue( Plugin::instance()->container()->has( AgentGuard::class ) );
		$this->assertContains( \AgentPorter\Security\SecuritySubsystem::class, Plugin::critical_subsystems() );
	}

	public function test_authorize_denies_subscriber_without_the_capability(): void {
		$result = AgentGuard::authorize( self::ABILITY, new AgentContext( 'app_password', self::factory()->user->create( array( 'role' => 'subscriber' ) ), '' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'agentporter_forbidden', $result->get_error_code() );
	}

	public function test_authorize_denies_administrators_too_unless_explicitly_granted(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$result = AgentGuard::authorize( self::ABILITY, new AgentContext( 'user', $admin, '' ) );
		$this->assertWPError( $result, 'Agents get the minimum: admins are not agents by default.' );
	}

	public function test_kill_switch_turned_on_mid_request_denies_the_next_call(): void {
		$agent = $this->agent();
		$ctx   = new AgentContext( 'app_password', $agent, '' );
		$this->assertTrue( AgentGuard::authorize( self::ABILITY, $ctx ) );
		( new KillSwitch( false ) )->set( true );
		$result = AgentGuard::authorize( self::ABILITY, $ctx );
		$this->assertWPError( $result );
		$this->assertSame( 'agentporter_agents_disabled', $result->get_error_code() );
	}

	public function test_disabled_ability_returns_403_with_stable_code(): void {
		update_option( AgentGuard::ENABLED_OPTION, array() );
		$result = AgentGuard::authorize( self::ABILITY, new AgentContext( 'app_password', $this->agent(), '' ) );
		$this->assertSame( 'agentporter_ability_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_denials_are_written_to_the_audit_table(): void {
		global $wpdb;
		AgentGuard::authorize( self::ABILITY, new AgentContext( 'app_password', 0, '198.51.100.23' ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $wpdb->prefix . Schema::AUDIT ), ARRAY_A );
		$this->assertSame( 'agentporter_forbidden', $row['error_code'] );
		$this->assertSame( (string) AuditLog::RESULT_DENIED, (string) $row['result'] );
		$this->assertSame( inet_pton( '198.51.100.0' ), $row['ip_trunc'] );
	}

	public function test_database_rate_store_counts_per_window_and_purges(): void {
		global $wpdb;
		$store = new DatabaseRateStore( $wpdb, new Schema( $wpdb ) );
		$this->assertSame( 1, $store->increment( 'actor:x', 600, 60 ) );
		$this->assertSame( 2, $store->increment( 'actor:x', 600, 60 ) );
		$this->assertSame( 1, $store->increment( 'actor:x', 660, 60 ), 'New window resets.' );
		$this->assertSame( 1, $store->increment( 'actor:y', 660, 60 ) );
		$this->assertSame( 2, $store->purge( 1000 ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . Schema::RATE ) ) );
	}

	public function test_rate_limit_denies_with_429_through_the_real_stack(): void {
		global $wpdb;
		$limiter = new RateLimiter( new DatabaseRateStore( $wpdb, new Schema( $wpdb ) ), static fn() => 1200 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->hit( 'actor:z', 3, 60 )->allowed );
		}
		$this->assertFalse( $limiter->hit( 'actor:z', 3, 60 )->allowed );
	}

	public function test_missing_rate_table_fails_closed(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . Schema::RATE ) );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->expectException( \RuntimeException::class );
			( new DatabaseRateStore( $wpdb, new Schema( $wpdb ) ) )->increment( 'actor:x', 0, 60 );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_audit_purge_removes_only_old_rows(): void {
		global $wpdb;
		$log = new AuditLog( $wpdb, new Schema( $wpdb ), new Redactor() );
		$log->record( 'cli', 1, self::ABILITY, array(), AuditLog::RESULT_ALLOWED, '', '', 1 );
		$wpdb->insert(
			$wpdb->prefix . Schema::AUDIT,
			array(
				'ts'         => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
				'actor_type' => 'cli',
			) 
		);
		$this->assertSame( 1, $log->purge( 30 ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . Schema::AUDIT ) ) );
	}

	public function test_secrets_are_encrypted_and_never_autoloaded_in_the_options_table(): void {
		global $wpdb;
		$store = new SecretStore();
		$this->assertTrue( $store->is_available() );
		$store->put( 'webhook', 'whsec_plaintext_value' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, 'agentporter_secret_webhook' ), ARRAY_A );
		$this->assertStringNotContainsString( 'whsec_plaintext_value', $row['option_value'] );
		$this->assertContains( $row['autoload'], array( 'off', 'no' ) );
		$this->assertSame( 'whsec_plaintext_value', $store->get( 'webhook' ) );
		$dump = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(option_value) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'agentporter_' ) . '%' ) );
		$this->assertStringNotContainsString( 'whsec_plaintext_value', $dump );
	}

	public function test_lockdown_denies_every_call(): void {
		$agent     = $this->agent();
		$container = Plugin::instance()->container();
		$faults    = new \AgentPorter\Core\Faults( new \AgentPorter\Core\Logger( false ) );
		$guard     = new AgentGuard( $faults, $container->get( KillSwitch::class ), $container->get( RateLimiter::class ), $container->get( AuditLog::class ), new \AgentPorter\Core\Logger( false ) );
		$ctx       = new AgentContext( 'app_password', $agent, '' );
		$this->assertTrue( $guard->check( self::ABILITY, $ctx ) );
		$faults->lock_down();
		$result = $guard->check( self::ABILITY, $ctx );
		$this->assertWPError( $result );
		$this->assertSame( 'agentporter_unavailable', $result->get_error_code() );
	}
}
