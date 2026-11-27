<?php
/**
 * Rate counters in a persistent object cache (Redis, Memcached).
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Uses wp_cache_add() + wp_cache_incr(), which are atomic in persistent object caches.
 */
final class CacheRateStore implements RateStore {

	public const GROUP = 'agentporter_rate';

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bucket       Bucket key.
	 * @param int    $window_start Window start.
	 * @param int    $window       Window length.
	 * @return int
	 * @throws \RuntimeException When the cache cannot increment.
	 */
	public function increment( string $bucket, int $window_start, int $window ): int {
		$key = $bucket . ':' . $window_start;
		wp_cache_add( $key, 0, self::GROUP, $window + 5 ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Expiry is the rate window (seconds) plus a margin, by design.
		$count = wp_cache_incr( $key, 1, self::GROUP );
		if ( false === $count ) {
			throw new \RuntimeException( 'Object cache could not increment the rate counter.' );
		}
		return (int) $count;
	}
}
