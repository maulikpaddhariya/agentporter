<?php
/**
 * Who is calling, from where, with which scopes.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of the actor behind an agent call.
 */
final class AgentContext {

	/**
	 * Constructor.
	 *
	 * @param string            $actor_type app_password, agent_key, webmcp, cli or user.
	 * @param int               $user_id    WordPress user the call runs as (0 = anonymous).
	 * @param string            $ip         Client IP ('' when unknown).
	 * @param list<string>|null $scopes     Abilities the credential is limited to; null = not scoped.
	 */
	public function __construct(
		public readonly string $actor_type,
		public readonly int $user_id,
		public readonly string $ip,
		public readonly ?array $scopes = null
	) {
	}

	/**
	 * Context for the current request. Uses REMOTE_ADDR only; trusted-proxy handling arrives with
	 * the Http/ClientIp service (ARCHITECTURE.md §5.4).
	 */
	public static function from_request(): self {
		$actor = 'user';
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$actor = 'cli';
		} elseif ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			$actor = 'app_password';
		}

		// REMOTE_ADDR is set by the web server from the TCP connection (not a request header) and is
		// validated as an IP below. Agent API calls are never page-cached, so the cache warning does not apply.
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		return new self( $actor, get_current_user_id(), $ip, null );
	}
}
