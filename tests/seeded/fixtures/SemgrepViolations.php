<?php
/**
 * SEEDED VIOLATIONS — never shipped. Each block must be reported by a rule in .semgrep.yml.
 *
 * @package AgentPorter\Tests
 */

namespace AgentPorter\Seeded;

final class SemgrepViolations {
	public function run( $input, $file, $token ) {
		eval( $input );
		$out = shell_exec( 'ls' );
		$listing = `ls -la`;
		$values = unserialize( $input );
		include $file;
		file_put_contents( WP_CONTENT_DIR . '/evil.php', $input );
		if ( $token === $input ) {
			return true;
		}
		register_rest_route( 'agentporter/v1', '/open', array( 'callback' => 'x' ) );
		register_rest_route( 'agentporter/v1', '/public', array( 'callback' => 'x', 'permission_callback' => '__return_true' ) );
		wp_register_ability( 'agentporter/unguarded', array( 'permission_callback' => '__return_true' ) );
		add_action( 'wp_ajax_nopriv_agentporter_x', 'x' );
		return $values . $out;
	}
}
