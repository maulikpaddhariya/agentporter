<?php
/**
 * The single choke point for agent access.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

use AgentPorter\Core\Faults;
use AgentPorter\Core\Logger;
use AgentPorter\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability permission callback and every agent-facing route calls {@see AgentGuard::authorize()}.
 *
 * Checks, in order (first failure wins):
 *  1. Lockdown — a security-critical subsystem failed this request → 503 agentporter_unavailable.
 *  2. Kill switch (option or AGENTPORTER_DISABLE_AGENTS) → 403 agentporter_agents_disabled.
 *  3. Ability name is a valid agentporter/* name and is enabled by the owner → 403 agentporter_ability_disabled.
 *  4. Actor holds the capability (read or write) and, for scoped credentials, the scope → 403.
 *  5. Rate limits per credential (60/min) and per IP (120/min) → 429 agentporter_rate_limited.
 *  6. Policy filter, which can only deny.
 *
 * Fail closed: any exception, unexpected value or missing service denies. Denials are audited.
 */
final class AgentGuard {

	public const CAP_READ        = 'agentporter_read_abilities';
	public const CAP_WRITE       = 'agentporter_write_abilities';
	public const ENABLED_OPTION  = 'agentporter_abilities_enabled';
	public const ACTOR_PER_MIN   = 60;
	public const IP_PER_MIN      = 120;
	private const ABILITY_FORMAT = '/^agentporter\/[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * Constructor.
	 *
	 * @param Faults      $faults       Failure state (lockdown).
	 * @param KillSwitch  $kill_switch  Kill switch.
	 * @param RateLimiter $rate_limiter Rate limiter.
	 * @param AuditLog    $audit        Audit log.
	 * @param Logger      $logger       Debug logger.
	 */
	public function __construct(
		private Faults $faults,
		private KillSwitch $kill_switch,
		private RateLimiter $rate_limiter,
		private AuditLog $audit,
		private Logger $logger
	) {
	}

	/**
	 * Static entry point used by permission callbacks. Denies if the plugin or guard is unavailable.
	 *
	 * @param string            $ability Ability name, for example "agentporter/site-info".
	 * @param AgentContext|null $context Caller; null builds it from the current request.
	 * @param bool              $write   Whether the ability writes (needs the write capability).
	 * @return bool|\WP_Error True when allowed, WP_Error when denied.
	 */
	public static function authorize( string $ability, ?AgentContext $context = null, bool $write = false ) {
		try {
			$container = Plugin::instance()->container();
			if ( ! $container->has( self::class ) ) {
				return self::deny( 'agentporter_unavailable', 503 );
			}
			$guard = $container->get( self::class );
			return $guard->check( $ability, $context ?? AgentContext::from_request(), $write );
		} catch ( \Throwable $error ) {
			return self::deny( 'agentporter_unavailable', 503 );
		}
	}

	/**
	 * Runs every check.
	 *
	 * @param string       $ability Ability name.
	 * @param AgentContext $context Caller.
	 * @param bool         $write   Whether the ability writes.
	 * @return bool|\WP_Error True when allowed, WP_Error when denied.
	 */
	public function check( string $ability, AgentContext $context, bool $write = false ) {
		$started = microtime( true );
		try {
			$denial = $this->evaluate( $ability, $context, $write );
		} catch ( \Throwable $error ) {
			$this->logger->error( 'AgentGuard denied after ' . get_class( $error ) );
			$denial = self::deny( 'agentporter_guard_error', 403 );
		}

		if ( null === $denial ) {
			return true;
		}
		$this->audit_denial( $ability, $context, $write, $denial, $started );
		return $denial;
	}

	/**
	 * Returns null when allowed, or the denial.
	 *
	 * @param string       $ability Ability name.
	 * @param AgentContext $context Caller.
	 * @param bool         $write   Whether the ability writes.
	 */
	private function evaluate( string $ability, AgentContext $context, bool $write ): ?\WP_Error {
		if ( $this->faults->is_locked_down() ) {
			return self::deny( 'agentporter_unavailable', 503 );
		}
		if ( $this->kill_switch->is_on() ) {
			return self::deny( 'agentporter_agents_disabled', 403 );
		}
		if ( strlen( $ability ) > 100 || 1 !== preg_match( self::ABILITY_FORMAT, $ability ) || ! in_array( $ability, self::enabled_abilities(), true ) ) {
			return self::deny( 'agentporter_ability_disabled', 403 );
		}
		if ( $context->user_id <= 0 || ! user_can( $context->user_id, $write ? self::CAP_WRITE : self::CAP_READ ) ) {
			return self::deny( 'agentporter_forbidden', 403 );
		}
		if ( null !== $context->scopes && ! in_array( $ability, $context->scopes, true ) ) {
			return self::deny( 'agentporter_out_of_scope', 403 );
		}

		$by_actor = $this->rate_limiter->hit( 'actor:' . $context->actor_type . ':' . $context->user_id, self::ACTOR_PER_MIN, 60 );
		if ( ! $by_actor->allowed ) {
			return self::deny( 'agentporter_rate_limited', 429, $by_actor->retry_after );
		}
		if ( '' !== $context->ip ) {
			// Never store raw IPs in rate buckets: keyed HMAC of the address.
			$by_ip = $this->rate_limiter->hit( 'ip:' . substr( hash_hmac( 'sha256', $context->ip, wp_salt( 'nonce' ) ), 0, 32 ), self::IP_PER_MIN, 60 );
			if ( ! $by_ip->allowed ) {
				return self::deny( 'agentporter_rate_limited', 429, $by_ip->retry_after );
			}
		}

		/**
		 * Lets policy code deny an otherwise allowed agent call. Return a WP_Error to deny. Returning
		 * anything other than exactly true also denies; nothing can turn an earlier denial into an allow.
		 *
		 * @param true         $allowed Always true when this runs.
		 * @param string       $ability Ability name.
		 * @param AgentContext $context Caller.
		 * @param bool         $write   Whether the ability writes.
		 */
		$decision = apply_filters( 'agentporter_guard_decision', true, $ability, $context, $write );
		if ( true === $decision ) {
			return null;
		}
		if ( $decision instanceof \WP_Error ) {
			$data = $decision->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) || (int) $data['status'] < 400 ) {
				$decision->add_data( array( 'status' => 403 ) );
			}
			return $decision;
		}
		return self::deny( 'agentporter_policy_denied', 403 );
	}

	/**
	 * Abilities the owner switched on. Anything that is not a list of strings counts as none.
	 *
	 * @return list<string>
	 */
	public static function enabled_abilities(): array {
		$value = get_option( self::ENABLED_OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( $value, 'is_string' ) );
	}

	/**
	 * Builds a denial with a generic message and a stable code.
	 *
	 * @param string $code        Stable error code.
	 * @param int    $status      HTTP status.
	 * @param int    $retry_after Seconds (429 only).
	 */
	private static function deny( string $code, int $status, int $retry_after = 0 ): \WP_Error {
		$message = 429 === $status
			? __( 'Too many requests. Please try again later.', 'agentporter' )
			: __( 'This action is not available.', 'agentporter' );
		$data    = array( 'status' => $status );
		if ( $retry_after > 0 ) {
			$data['retry_after'] = $retry_after;
		}
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Writes an audit entry for a denial. A failing audit write never turns a denial into an allow.
	 *
	 * @param string       $ability Ability name.
	 * @param AgentContext $context Caller.
	 * @param bool         $write   Write ability.
	 * @param \WP_Error    $denial  Denial.
	 * @param float        $started microtime() at the start.
	 */
	private function audit_denial( string $ability, AgentContext $context, bool $write, \WP_Error $denial, float $started ): void {
		try {
			$this->audit->record(
				$context->actor_type,
				$context->user_id,
				$ability,
				array( 'write' => $write ),
				AuditLog::RESULT_DENIED,
				(string) $denial->get_error_code(),
				$context->ip,
				(int) round( ( microtime( true ) - $started ) * 1000 )
			);
		} catch ( \Throwable $error ) {
			$this->logger->error( 'Audit write failed: ' . get_class( $error ) );
		}
	}
}
