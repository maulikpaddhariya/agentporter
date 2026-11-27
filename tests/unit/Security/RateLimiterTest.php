<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Security\CacheRateStore;
use AgentPorter\Security\RateLimiter;
use AgentPorter\Tests\Unit\TestCase;
use AgentPorter\Tests\Unit\Fixtures\MemoryRateStore;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Fixtures/MemoryRateStore.php';

/**
 * @covers \AgentPorter\Security\RateLimiter
 * @covers \AgentPorter\Security\CacheRateStore
 */
final class RateLimiterTest extends TestCase {

	public function test_denies_over_the_limit_with_retry_after(): void {
		$now     = 1000020;
		$limiter = new RateLimiter( new MemoryRateStore(), static fn() => $now );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->hit( 'actor:1', 3, 60 )->allowed );
		}
		$denied = $limiter->hit( 'actor:1', 3, 60 );
		$this->assertFalse( $denied->allowed );
		$this->assertSame( 0, $denied->remaining );
		$this->assertSame( 60 - ( $now % 60 ), $denied->retry_after );
	}

	public function test_store_failure_throws_so_callers_deny(): void {
		$store       = new MemoryRateStore();
		$store->fail = true;
		$this->expectException( \RuntimeException::class );
		( new RateLimiter( $store ) )->hit( 'actor:1', 10 );
	}

	public function test_invalid_limits_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new RateLimiter( new MemoryRateStore() ) )->hit( 'actor:1', 0 );
	}

	public function test_new_window_resets_and_buckets_are_independent(): void {
		$time    = 600;
		$limiter = new RateLimiter(
			new MemoryRateStore(),
			static function () use ( &$time ) {
				return $time;
			} 
		);
		$limiter->hit( 'a', 1 );
		$this->assertFalse( $limiter->hit( 'a', 1 )->allowed );
		$this->assertTrue( $limiter->hit( 'b', 1 )->allowed );
		$time += 60;
		$this->assertTrue( $limiter->hit( 'a', 1 )->allowed );
	}

	public function test_unusual_or_long_keys_are_hashed_and_bounded(): void {
		$this->assertSame( 'actor:app_password:12', RateLimiter::key( 'actor:app_password:12' ) );
		$hashed = RateLimiter::key( "evil'; DROP TABLE x; --" . str_repeat( 'z', 200 ) );
		$this->assertMatchesRegularExpression( '/^h:[0-9a-f]{40}$/', $hashed );
		$this->assertLessThanOrEqual( 80, strlen( $hashed ) );
	}

	public function test_cache_store_counts_atomically_and_fails_closed(): void {
		$cache = array();
		Functions\when( 'wp_cache_add' )->alias(
			static function ( $key, $value ) use ( &$cache ) {
				if ( isset( $cache[ $key ] ) ) {
					return false;
				}
				$cache[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_incr' )->alias(
			static function ( $key ) use ( &$cache ) {
				return isset( $cache[ $key ] ) ? ++$cache[ $key ] : false;
			}
		);
		$store = new CacheRateStore();
		$this->assertSame( 1, $store->increment( 'b', 60, 60 ) );
		$this->assertSame( 2, $store->increment( 'b', 60, 60 ) );

		Functions\when( 'wp_cache_incr' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$store->increment( 'b', 120, 60 );
	}
}
