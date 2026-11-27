<?php
/**
 * Collector for policy denials.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Passed to the agentporter_guard_check action. Listeners can only add denials; nothing can remove
 * one, so a later listener can never turn an earlier denial into an allow.
 */
final class GuardDenials {

	/**
	 * Denial codes, in the order they were added.
	 *
	 * @var list<string>
	 */
	private array $codes = array();

	/**
	 * Adds a denial. The code is shown to the caller only as a generic message; it is recorded in the
	 * audit log. Use a short machine code such as "outside_hours"; never put personal data in it.
	 *
	 * @param string $code Machine code (lowercase letters, digits, underscores; max 40).
	 */
	public function deny( string $code ): void {
		$clean         = substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $code ) ), 0, 40 );
		$this->codes[] = '' === $clean ? 'policy_denied' : $clean;
	}

	/**
	 * Whether anything denied.
	 */
	public function denied(): bool {
		return array() !== $this->codes;
	}

	/**
	 * First denial code.
	 */
	public function first(): string {
		return $this->codes[0] ?? '';
	}
}
