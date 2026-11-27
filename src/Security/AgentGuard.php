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
 *  4. The actor was explicitly granted the capability (read or write) — super admins and
 *     administrators are not agents unless granted — and, for scoped credentials, the scope → 403.
 *  5. Rate limits per user (60/min) and per network (120/min; IPv6 grouped by /64) → 429.
 *  6. Policy listeners on agentporter_guard_check, which can only add denials.
 *
 * Callers that lack the read capability always get the same 403 agentporter_forbidden, so they
 * cannot learn whether the kill switch is on or which abilities exist; the audit log keeps the real
 * reason. Fail closed: any exception, unexpected value or missing service denies.
 *
 * Denials are audited with bounded volume: callers holding the read grant get their own budget
 * (AUDIT_DENIALS per user per minute), so an anonymous flood cannot hide a misbehaving agent; all
 * other denials share a site-wide ceiling (AUDIT_DENIALS_SITE per minute) and a per-network budget.
 * When a budget runs out, one "agentporter_denials_suppressed" row per window records that.
 */
final class AgentGuard {

	public const CAP_READ        = 'agentporter_read_abilities';
	public const CAP_WRITE       = 'agentporter_write_abilities';
	public const ENABLED_OPTION  = 'agentporter_abilities_enabled';
	public const ACTOR_PER_MIN   = 60;
	public const IP_PER_MIN      = 120;
	public const AUDIT_DENIALS   = 30;
	public const AUDIT_SITE_MAX  = 300;
	public const SUPPRESSED_CODE = 'agentporter_denials_suppressed';
	private const ABILITY_FORMAT = '/^agentporter\/[a-z0-9]+(?:-[a-z0-9]+)*\z/';

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
		private readonly Faults $faults,
		private readonly KillSwitch $kill_switch,
		private readonly RateLimiter $rate_limiter,
		private readonly AuditLog $audit,
		private readonly Logger $logger
	) {
	}

	/**
	 * Per-request cache of the bucket HMAC key.
	 *
	 * @var string|null
	 */
	private ?string $bucket_key = null;

	/**
	 * Unix time until which the site-wide denial audit budget is known to be spent (this request).
	 *
	 * @var int
	 */
	private int $site_audit_full_until = 0;

	/**
	 * Static entry point used by permission callbacks. Denies if the plugin or guard is unavailable.
	 *
	 * @param string            $ability Ability name, for example "agentporter/site-info".
	 * @param AgentContext|null $context Caller; null builds it from the current request.
	 * @param bool              $write   Whether the ability writes (needs the write capability).
	 * @return bool|\WP_Error True when allowed, WP_Error when denied.
	 */
	public static function authorize( string $ability, ?AgentContext $context = null, bool $write = false ): \WP_Error|bool {
		try {
			$container = Plugin::instance()->container();
			if ( $container->has( self::class ) ) {
				$guard = $container->get( self::class );
				return $guard->check( $ability, $context ?? AgentContext::from_request(), $write );
			}
		} catch ( \Throwable ) {
			return self::unavailable( $context ); // Fail closed.
		}
		return self::unavailable( $context );
	}

	/**
	 * Denial when the guard itself cannot run. Only granted callers learn that the service is down;
	 * everyone else gets the uniform 403, so probing cannot reveal a lockdown.
	 *
	 * @param AgentContext|null $context Caller, when known.
	 */
	private static function unavailable( ?AgentContext $context ): \WP_Error {
		try {
			$granted = self::has_grant( null !== $context ? $context->user_id : get_current_user_id(), self::CAP_READ );
		} catch ( \Throwable ) {
			$granted = false;
		}
		return $granted ? self::deny( 'agentporter_unavailable', 503 ) : self::deny( 'agentporter_forbidden', 403 );
	}

	/**
	 * Runs every check.
	 *
	 * @param string       $ability Ability name.
	 * @param AgentContext $context Caller.
	 * @param bool         $write   Whether the ability writes.
	 * @return bool|\WP_Error True when allowed, WP_Error when denied.
	 */
	public function check( string $ability, AgentContext $context, bool $write = false ): \WP_Error|bool {
		$started = microtime( true );
		try {
			$denial = $this->evaluate( $ability, $context, $write );
		} catch ( \Throwable $error ) {
			$this->logger->error( 'AgentGuard denied after ' . Faults::class_name( $error ) );
			$denial = self::deny( 'agentporter_guard_error', 403 );
		}

		if ( null === $denial ) {
			return true;
		}
		$this->audit_denial( $ability, $context, $write, $denial, $started );
		return $this->public_denial( $denial, $context );
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
		if ( ! self::has_grant( $context->user_id, $write ? self::CAP_WRITE : self::CAP_READ ) ) {
			return self::deny( 'agentporter_forbidden', 403 );
		}
		if ( null !== $context->scopes && ! in_array( $ability, $context->scopes, true ) ) {
			return self::deny( 'agentporter_out_of_scope', 403 );
		}

		$by_user = $this->rate_limiter->hit( 'user:' . $context->user_id, self::ACTOR_PER_MIN, 60 );
		if ( ! $by_user->allowed ) {
			return self::deny( 'agentporter_rate_limited', 429, $by_user->retry_after );
		}
		$by_ip = $this->rate_limiter->hit( $this->network_bucket( 'ip', $context->ip ), self::IP_PER_MIN, 60 );
		if ( ! $by_ip->allowed ) {
			return self::deny( 'agentporter_rate_limited', 429, $by_ip->retry_after );
		}

		$denials = new GuardDenials();
		/**
		 * Lets policy code deny an otherwise allowed agent call. Listeners can only add denials with
		 * $denials->deny( 'code' ); nothing can remove one. Runs only after every built-in check passed.
		 *
		 * @param GuardDenials $denials Collector.
		 * @param string       $ability Ability name.
		 * @param AgentContext $context Caller.
		 * @param bool         $write   Whether the ability writes.
		 */
		do_action( 'agentporter_guard_check', $denials, $ability, $context, $write );
		if ( $denials->denied() ) {
			return self::deny( 'agentporter_policy_' . $denials->first(), 403 );
		}
		return null;
	}

	/**
	 * Whether a user was explicitly granted a capability through a role or a user capability.
	 * Unlike user_can(), this does not let multisite super admins pass automatically.
	 *
	 * @param int    $user_id User id.
	 * @param string $cap     Capability.
	 */
	public static function has_grant( int $user_id, string $cap ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		return true === ( $user->allcaps[ $cap ] ?? false ) || 1 === ( $user->allcaps[ $cap ] ?? false );
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
	 * Network part of an IP for rate limiting: IPv4 as is; IPv6 that embeds an IPv4 host (mapped,
	 * 6to4, Teredo) as that IPv4, since one IPv4 host controls the whole range; other IPv6 by /64
	 * (one host can hold a whole /64); one shared value when the IP is unknown.
	 *
	 * @param string $ip IP address.
	 */
	public static function network_of( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return 'unknown';
		}
		$packed = (string) inet_pton( $ip );
		if ( 16 !== strlen( $packed ) ) {
			return $ip;
		}
		if ( str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}
		if ( str_starts_with( $packed, "\x20\x02" ) ) { // 6to4: 2002:VVVV:VVVV::/48.
			return (string) inet_ntop( substr( $packed, 2, 4 ) );
		}
		if ( str_starts_with( $packed, "\x20\x01\x00\x00" ) ) { // Teredo: client IPv4 is the last 32 bits, inverted.
			return (string) inet_ntop( substr( $packed, 12 ) ^ "\xff\xff\xff\xff" );
		}
		return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
	}

	/**
	 * Rate bucket for a network, keyed with HMAC so raw IPs are never stored.
	 *
	 * @param string $prefix Bucket prefix.
	 * @param string $ip     IP address.
	 */
	private function network_bucket( string $prefix, string $ip ): string {
		if ( null === $this->bucket_key ) {
			$salt = (string) wp_salt( 'nonce' );
			// A purpose-bound key derived from the salt, so bucket names never reuse the nonce key itself.
			$this->bucket_key = Crypto::hmac( str_pad( $salt, 32, "\0" ), 'agentporter/rate-bucket/v1' );
		}
		return $prefix . ':' . substr( bin2hex( Crypto::hmac( $this->bucket_key, self::network_of( $ip ) ) ), 0, 32 );
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
	 * What the caller sees: callers without the read capability get one uniform denial.
	 *
	 * @param \WP_Error    $denial  Internal denial.
	 * @param AgentContext $context Caller.
	 */
	private function public_denial( \WP_Error $denial, AgentContext $context ): \WP_Error {
		try {
			$authorized = self::has_grant( $context->user_id, self::CAP_READ );
		} catch ( \Throwable ) {
			$authorized = false;
		}
		if ( $authorized ) {
			return $denial;
		}
		return self::deny( 'agentporter_forbidden', 403 );
	}

	/**
	 * Writes an audit entry for a denial within the audit budgets (see the class docblock). A failing
	 * or skipped audit write never turns a denial into an allow.
	 *
	 * @param string       $ability Ability name.
	 * @param AgentContext $context Caller.
	 * @param bool         $write   Write ability.
	 * @param \WP_Error    $denial  Denial.
	 * @param float        $started microtime() at the start.
	 */
	private function audit_denial( string $ability, AgentContext $context, bool $write, \WP_Error $denial, float $started ): void {
		try {
			$scope = $this->audit_budget_exhausted( $context );
			if ( null !== $scope ) {
				$this->record_suppression( $scope, $ability, $context );
				return;
			}
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
			$this->logger->error( 'Audit write failed: ' . Faults::class_name( $error ) );
		}
	}

	/**
	 * Spends audit budget for one denial. Returns null when it may be written, or the exhausted scope
	 * ('user', 'site' or 'network').
	 *
	 * @param AgentContext $context Caller.
	 */
	private function audit_budget_exhausted( AgentContext $context ): ?string {
		try {
			$granted = self::has_grant( $context->user_id, self::CAP_READ );
		} catch ( \Throwable ) {
			$granted = false; // The capability layer failing is itself worth auditing.
		}
		if ( $granted ) {
			return $this->rate_limiter->hit( 'audit-deny:user:' . $context->user_id, self::AUDIT_DENIALS, 60 )->allowed ? null : 'user';
		}
		// Site ceiling first, so a flood spread over many networks creates no per-network counters
		// once the ceiling is reached.
		if ( time() < $this->site_audit_full_until ) {
			return 'site-known'; // Already spent and marked: no further counter writes this window.
		}
		$site = $this->rate_limiter->hit( 'audit-deny:site', self::AUDIT_SITE_MAX, 60 );
		if ( ! $site->allowed ) {
			$this->site_audit_full_until = time() + $site->retry_after;
			return 'site';
		}
		return $this->rate_limiter->hit( $this->network_bucket( 'audit-deny', $context->ip ), self::AUDIT_DENIALS, 60 )->allowed ? null : 'network';
	}

	/**
	 * Writes one marker row per window when a user's or the site's audit budget runs out, so a gap in
	 * the log is visible. Per-network suppression has no marker: the site ceiling bounds it.
	 *
	 * @param string       $scope   Exhausted scope.
	 * @param string       $ability Ability name.
	 * @param AgentContext $context Caller.
	 */
	private function record_suppression( string $scope, string $ability, AgentContext $context ): void {
		if ( 'network' === $scope || 'site-known' === $scope ) {
			return;
		}
		$bucket = 'user' === $scope ? 'audit-suppressed:user:' . $context->user_id : 'audit-suppressed:site';
		if ( ! $this->rate_limiter->hit( $bucket, 1, 60 )->allowed ) {
			return;
		}
		$this->audit->record(
			'user' === $scope ? $context->actor_type : 'unknown',
			'user' === $scope ? $context->user_id : 0,
			$ability,
			array( 'scope' => $scope ),
			AuditLog::RESULT_DENIED,
			self::SUPPRESSED_CODE,
			'user' === $scope ? $context->ip : '',
			0
		);
	}
}
