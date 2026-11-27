<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Integration\Core;

use AgentPorter\Core\Lock;

/**
 * Each Lock instance stands in for a separate PHP process: state lives only in the database row.
 *
 * @covers \AgentPorter\Core\Lock
 */
final class LockTest extends \WP_UnitTestCase {

	private function lock( int $ttl = 600 ): Lock {
		global $wpdb;
		return new Lock( $wpdb, 'test', $ttl );
	}

	public function test_second_process_cannot_acquire_a_held_lock(): void {
		$first  = $this->lock();
		$second = $this->lock();
		$this->assertTrue( $first->acquire() );
		$this->assertFalse( $second->acquire() );
		$this->assertTrue( $first->held() );
		$this->assertFalse( $second->held() );
	}

	public function test_release_by_non_holder_does_not_free_the_lock(): void {
		$first  = $this->lock();
		$second = $this->lock();
		$first->acquire();
		$second->release();
		$this->assertFalse( $this->lock()->acquire() );
	}

	public function test_lock_can_be_reacquired_after_release(): void {
		$first = $this->lock();
		$first->acquire();
		$first->release();
		$this->assertFalse( $first->held() );
		$this->assertTrue( $this->lock()->acquire() );
	}

	public function test_stale_lock_is_taken_over_and_old_holder_cannot_release_it(): void {
		$crashed = $this->lock( 10 );
		$this->assertTrue( $crashed->acquire( 1000 ) );

		$rescuer = $this->lock( 10 );
		$this->assertFalse( $rescuer->acquire( 1005 ), 'Not yet expired.' );
		$this->assertTrue( $rescuer->acquire( 2000 ), 'Expired lock is taken over.' );

		// The original holder wakes up and releases: must not delete the rescuer's lock.
		$crashed->release();
		$this->assertFalse( $this->lock( 10 )->acquire( 2001 ) );
	}

	public function test_malformed_lock_value_counts_as_expired(): void {
		$probe = $this->lock();
		update_option( $probe->option_name(), 'garbage', false );
		wp_cache_flush();
		$this->assertTrue( $this->lock()->acquire() );
	}

	public function test_lock_row_is_never_autoloaded(): void {
		global $wpdb;
		$lock = $this->lock();
		$lock->acquire();
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $lock->option_name() ) );
		$this->assertSame( 'off', $autoload );
		$this->assertArrayNotHasKey( $lock->option_name(), wp_load_alloptions( true ) );
	}

	public function test_invalid_names_are_rejected(): void {
		global $wpdb;
		$this->expectException( \InvalidArgumentException::class );
		new Lock( $wpdb, "bad'; DROP TABLE x; --" );
	}

	public function test_future_dated_lock_beyond_one_ttl_is_stale(): void {
		$skewed = $this->lock( 600 );
		$this->assertTrue( $skewed->acquire( 2000000000 ), 'A node with a clock years ahead.' );
		$this->assertTrue( $this->lock( 600 )->acquire( 1700000000 ), 'Not blocked forever by a bad clock.' );
	}

	public function test_renew_extends_a_held_lock(): void {
		$holder = $this->lock( 10 );
		$holder->acquire( 1000 );
		$this->assertTrue( $holder->renew( 1008 ) );
		$this->assertFalse( $this->lock( 10 )->acquire( 1012 ), 'Renewed lock is still live.' );
	}

	public function test_renew_fails_after_takeover_and_forgets_the_token(): void {
		$slow = $this->lock( 10 );
		$slow->acquire( 1000 );
		$this->assertTrue( $this->lock( 10 )->acquire( 2000 ) );
		$this->assertFalse( $slow->renew( 2001 ) );
		$this->assertFalse( $slow->held() );
	}

	public function test_acquire_while_holding_renews_instead_of_trusting_memory(): void {
		$slow = $this->lock( 10 );
		$slow->acquire( 1000 );
		$this->lock( 10 )->acquire( 2000 );
		$this->assertFalse( $slow->acquire( 2001 ), 'Lost lock is not reported as held.' );
	}

	public function test_database_error_is_reported_not_treated_as_contention(): void {
		global $wpdb;
		$original      = $wpdb->options;
		$wpdb->options = $wpdb->prefix . 'agentporter_no_such_table';
		$suppress      = $wpdb->suppress_errors( true );
		try {
			$this->expectException( \RuntimeException::class );
			$this->lock()->acquire();
		} finally {
			$wpdb->suppress_errors( $suppress );
			$wpdb->options = $original;
		}
	}

	public function test_one_second_clock_skew_between_nodes_cannot_steal_a_live_lock(): void {
		$fast = $this->lock( 600 );
		$this->assertTrue( $fast->acquire( 1000001 ) );
		$this->assertFalse( $this->lock( 600 )->acquire( 1000000 ), 'Reviewer repro: slower node must not take over.' );
		$this->assertTrue( $fast->renew( 1000002 ) );
	}
}
