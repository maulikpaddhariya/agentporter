<?php
/**
 * Fixed-window rate limiter.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Counts calls per bucket in fixed windows. Uses the persistent object cache when the site has one,
 * otherwise the agentporter_rate table. A store failure throws; callers (AgentGuard) deny.
 */
final class RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param RateStore     $store Counter storage.
	 * @param \Closure|null $clock Returns the current Unix time (tests).
	 */
	public function __construct( private RateStore $store, private ?\Closure $clock = null ) {
	}

	/**
	 * Records one call and says whether it is within the limit.
	 *
	 * @param string $bucket Bucket name (for example "actor:12" or "ip:<hash>").
	 * @param int    $limit  Allowed calls per window (≥ 1).
	 * @param int    $window Window length in seconds (≥ 1).
	 * Store failures surface as \RuntimeException from the store; callers (AgentGuard) deny.
	 *
	 * @throws \InvalidArgumentException For a non-positive limit or window.
	 */
	public function hit( string $bucket, int $limit, int $window = 60 ): RateResult {
		if ( $limit < 1 || $window < 1 ) {
			throw new \InvalidArgumentException( 'Limit and window must be positive.' );
		}
		$now          = null !== $this->clock ? (int) ( $this->clock )() : time();
		$window_start = $now - ( $now % $window );
		$count        = $this->store->increment( self::key( $bucket ), $window_start, $window );

		if ( $count > $limit ) {
			return new RateResult( false, 0, max( 1, $window_start + $window - $now ) );
		}
		return new RateResult( true, $limit - $count, 0 );
	}

	/**
	 * Normalised bucket key: safe characters only, at most 80 bytes (long or unusual keys are hashed).
	 *
	 * @param string $bucket Bucket name.
	 */
	public static function key( string $bucket ): string {
		if ( 1 === preg_match( '/^[a-z0-9:_.-]{1,64}\z/', $bucket ) ) {
			return 'r:' . $bucket;
		}
		return 'h:' . substr( hash( 'sha256', $bucket ), 0, 40 );
	}
}
