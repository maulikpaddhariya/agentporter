<?php
/**
 * Storage for fixed-window rate-limit counters.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Increments a counter for (bucket, window) atomically and returns the new count.
 */
interface RateStore {

	/**
	 * Increments the counter for the current window.
	 *
	 * @param string $bucket       Bucket key (validated, ≤ 80 chars).
	 * @param int    $window_start Unix time the window started.
	 * @param int    $window       Window length in seconds.
	 * @return int Count after the increment.
	 * @throws \RuntimeException When the store cannot count (callers deny).
	 */
	public function increment( string $bucket, int $window_start, int $window ): int;
}
