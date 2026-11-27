<?php
/**
 * Helpers shared by the REST security suite.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Security;

/**
 * Enumerates AgentPorter REST endpoints on a fresh REST server.
 */
final class RestRoutes {

	/**
	 * Every AgentPorter endpoint: 'METHOD /route' => [route, method, handler].
	 *
	 * @return array<string, array{route: string, method: string, handler: array<string, mixed>}>
	 */
	public static function endpoints(): array {
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core global.
		if ( getenv( 'AGENTPORTER_SEED_ROUTES' ) ) {
			require_once dirname( __DIR__ ) . '/seeded/security/routes.php';
		}
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Fresh server so rest_api_init runs with current hooks.
		$server         = rest_get_server();
		$out            = array();
		foreach ( $server->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/agentporter' ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) ) as $method ) {
					$out[ $method . ' ' . $route ] = array(
						'route'   => $route,
						'method'  => (string) $method,
						'handler' => $handler,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * A concrete path for a route pattern: every named group becomes "1".
	 *
	 * @param string $route Route regex.
	 */
	public static function sample_path( string $route ): string {
		return (string) preg_replace( '/\(\?P<[^>]+>[^)]*\)/', '1', $route );
	}
}
