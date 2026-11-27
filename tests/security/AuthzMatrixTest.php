<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Security;

require_once __DIR__ . '/RestRoutes.php';

/**
 * Authorization matrix: low-privilege roles get 401/403 on every AgentPorter route unless the
 * matrix allows them (SECURITY-MODEL §5.4, CLAUDE.md §3 deny tests).
 */
final class AuthzMatrixTest extends \WP_UnitTestCase {

	public function test_low_privilege_roles_are_denied_unless_the_matrix_allows_them(): void {
		$matrix = require __DIR__ . '/authz-matrix.php';
		$public = require __DIR__ . '/public-routes.php';
		$roles  = array( 'anonymous', 'subscriber', 'contributor', 'author', 'editor' );
		foreach ( array( 'customer', 'shop_manager' ) as $woo_role ) {
			if ( null !== get_role( $woo_role ) ) {
				$roles[] = $woo_role;
			}
		}
		$users = array( 'anonymous' => 0 );
		foreach ( $roles as $role ) {
			if ( 'anonymous' !== $role ) {
				$users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
			}
		}

		$problems = array();
		$checked  = 0;
		foreach ( RestRoutes::endpoints() as $key => $endpoint ) {
			if ( isset( $public[ $key ] ) ) {
				continue; // Public routes are covered by RouteRegistryTest.
			}
			foreach ( $users as $role => $user_id ) {
				if ( in_array( $role, $matrix[ $key ] ?? array(), true ) ) {
					continue;
				}
				wp_set_current_user( $user_id );
				$response = rest_do_request( new \WP_REST_Request( $endpoint['method'], RestRoutes::sample_path( $endpoint['route'] ) ) );
				++$checked;
				if ( ! in_array( $response->get_status(), array( 401, 403 ), true ) ) {
					$problems[] = sprintf( '%s as %s returned %d (expected 401/403)', $key, $role, $response->get_status() );
				}
			}
		}
		wp_set_current_user( 0 );
		$this->assertSame( array(), $problems, "Checked $checked role/route pairs." );
	}
}
