<?php
/**
 * In-memory rate store for unit tests.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Fixtures;

use AgentPorter\Security\RateStore;

/**
 * In-memory store.
 */
final class MemoryRateStore implements RateStore {
	/**
	 * Counters.
	 *
	 * @var array<string, int>
	 */
	public array $counts = array();
	/**
	 * Whether to fail.
	 *
	 * @var bool
	 */
	public bool $fail = false;
	public function increment( string $bucket, int $window_start, int $window ): int {
		if ( $this->fail ) {
			throw new \RuntimeException( 'down' );
		}
		$key                  = $bucket . '@' . $window_start;
		$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + 1;
		return $this->counts[ $key ];
	}
}
