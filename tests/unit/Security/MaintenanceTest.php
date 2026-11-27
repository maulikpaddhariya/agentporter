<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Core\Schema;
use AgentPorter\Security\AuditLog;
use AgentPorter\Security\DatabaseRateStore;
use AgentPorter\Security\Maintenance;
use AgentPorter\Security\Redactor;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * @covers \AgentPorter\Security\Maintenance
 */
final class MaintenanceTest extends TestCase {

	public function test_retention_defaults_to_30_days_and_is_clamped(): void {
		$this->assertSame( 30, Maintenance::retention_days() );
		Filters\expectApplied( 'agentporter_audit_retention_days' )->andReturn( 0, 99999, 'garbage', '7' );
		$this->assertSame( 1, Maintenance::retention_days() );
		$this->assertSame( 3650, Maintenance::retention_days() );
		$this->assertSame( 30, Maintenance::retention_days() );
		$this->assertSame( 7, Maintenance::retention_days() );
	}

	public function test_rate_purge_runs_in_bounded_chunks_then_audit_purge(): void {
		$wpdb                = new \wpdb();
		$wpdb->query_results = array( 5000, 5000, 12, 3 ); // Rate: two full chunks then a partial; audit: one partial.
		$schema              = new Schema( $wpdb );
		$result              = ( new Maintenance( new AuditLog( $wpdb, $schema, new Redactor() ), new DatabaseRateStore( $wpdb, $schema ) ) )->run();
		$this->assertSame(
			array(
				'rate'   => 10012,
				'audit'  => 3,
				'errors' => 0,
			),
			$result
		);
		$this->assertCount( 4, $wpdb->queries );
	}

	public function test_failures_are_isolated_and_never_throw(): void {
		$wpdb       = new \wpdb();
		$wpdb->fail = true;
		$schema     = new Schema( $wpdb );
		$result     = ( new Maintenance( new AuditLog( $wpdb, $schema, new Redactor() ), new DatabaseRateStore( $wpdb, $schema ) ) )->run();
		$this->assertSame( 0, $result['rate'] );
		$this->assertSame( 0, $result['audit'] );
		$this->assertSame( 0, $result['errors'] ); // A failed DELETE reports 0 rows rather than throwing.
	}

	public function test_object_cache_counters_are_not_purged(): void {
		$wpdb   = new \wpdb();
		$result = ( new Maintenance( new AuditLog( $wpdb, new Schema( $wpdb ), new Redactor() ), null ) )->run();
		$this->assertSame( 0, $result['rate'] );
		$this->assertCount( 1, $wpdb->queries ); // Audit only.
	}

	public function test_scheduling_needs_an_administrator(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->never();
		Maintenance::ensure_scheduled();
		$this->addToAssertionCount( 1 );
	}

	public function test_scheduling_is_idempotent_and_unscheduling_clears(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'wp_next_scheduled' )->twice()->with( Maintenance::HOOK )->andReturn( false, 1234 );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'hourly', Maintenance::HOOK );
		Maintenance::ensure_scheduled();
		Maintenance::ensure_scheduled();
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Maintenance::HOOK );
		Maintenance::unschedule();
		$this->addToAssertionCount( 1 ); // Expectations above are the assertions.
	}
}
