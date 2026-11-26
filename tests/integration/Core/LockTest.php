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
}
