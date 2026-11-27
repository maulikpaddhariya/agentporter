<?php
/**
 * Outcome of a rate-limit check.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result.
 */
final class RateResult {

	/**
	 * Constructor.
	 *
	 * @param bool $allowed     Whether the call may proceed.
	 * @param int  $remaining   Calls left in this window.
	 * @param int  $retry_after Seconds until the window resets (0 when allowed).
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly int $remaining,
		public readonly int $retry_after
	) {
	}
}
