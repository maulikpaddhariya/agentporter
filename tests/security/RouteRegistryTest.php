<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Security;

require_once __DIR__ . '/RestRoutes.php';

/**
 * Public-route registry: every AgentPorter route has a real permission callback, and only
 * registered routes may answer anonymous callers.
 */
final class RouteRegistryTest extends \WP_UnitTestCase {

	public function test_every_route_has_a_permission_callback_and_open_routes_are_registered(): void {
		$public    = require __DIR__ . '/public-routes.php';
		$endpoints = RestRoutes::endpoints();
		$problems  = array();
		wp_set_current_user( 0 );
		foreach ( $endpoints as $key => $endpoint ) {
			$callback = $endpoint['handler']['permission_callback'] ?? null;
			if ( ! is_callable( $callback ) ) {
				$problems[] = "$key has no permission_callback";
				continue;
			}
			$request = new \WP_REST_Request( $endpoint['method'], RestRoutes::sample_path( $endpoint['route'] ) );
			$open    = '__return_true' === $callback || true === call_user_func( $callback, $request );
			if ( $open && ! isset( $public[ $key ] ) ) {
				$problems[] = "$key answers anonymous callers but is not in tests/security/public-routes.php";
			}
		}
		foreach ( array_keys( $public ) as $key ) {
			if ( ! isset( $endpoints[ $key ] ) ) {
				$problems[] = "public-routes.php lists $key, which is not registered";
			}
		}
		$this->assertSame( array(), $problems );
	}
}
