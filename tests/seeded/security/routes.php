<?php
/**
 * Seeded violations for the REST security suite: an unregistered open route and a route any
 * logged-in user can call. Loaded only when AGENTPORTER_SEED_ROUTES is set.
 *
 * @package AgentPorter\Tests
 */

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'agentporter/v1',
			'/seeded-open',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'agentporter/v1',
			'/seeded-weak',
			array(
				'methods'             => 'POST',
				'callback'            => static fn() => array( 'ok' => true ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}
);
