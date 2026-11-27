<?php
/**
 * Minimum-requirements check.
 *
 * IMPORTANT: this file and agentporter.php are loaded before the version check passes, so they
 * must parse on the oldest PHP that WordPress itself runs on (7.2). Do not use typed properties,
 * arrow functions, match, named arguments, nullsafe operators, union types or PHP 8 functions here.
 * CI lints both files with PHP 7.2.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks PHP and WordPress versions and renders a notice when they are too old.
 */
final class Requirements {

	const MIN_PHP = '8.1';
	const MIN_WP  = '6.9';

	/**
	 * Running PHP version.
	 *
	 * @var string
	 */
	private $php_version;

	/**
	 * Running WordPress version.
	 *
	 * @var string
	 */
	private $wp_version;

	/**
	 * Constructor.
	 *
	 * @param string $php_version Running PHP version (normally PHP_VERSION).
	 * @param string $wp_version  Running WordPress version (normally $GLOBALS['wp_version']).
	 */
	public function __construct( string $php_version, string $wp_version ) {
		$this->php_version = $php_version;
		$this->wp_version  = $wp_version;
	}

	/**
	 * Builds a checker for the current environment.
	 *
	 * @return self
	 */
	public static function from_environment(): self {
		$wp_version = isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0';
		return new self( PHP_VERSION, $wp_version );
	}

	/**
	 * Whether every requirement is met.
	 *
	 * @return bool
	 */
	public function met(): bool {
		return array() === $this->failures();
	}

	/**
	 * Failed requirement keys: 'php' and/or 'wp'.
	 *
	 * @return string[]
	 */
	public function failures(): array {
		$failures = array();
		if ( version_compare( $this->php_version, self::MIN_PHP, '<' ) ) {
			$failures[] = 'php';
		}
		if ( version_compare( $this->wp_version, self::MIN_WP, '<' ) ) {
			$failures[] = 'wp';
		}
		return $failures;
	}

	/**
	 * Plain-language explanation of what is missing.
	 *
	 * @return string
	 */
	public function message(): string {
		$parts = array();
		foreach ( $this->failures() as $failure ) {
			if ( 'php' === $failure ) {
				$parts[] = sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'PHP %1$s or later (this site runs %2$s)', 'agentporter' ),
					self::MIN_PHP,
					$this->php_version
				);
			} else {
				$parts[] = sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version. */
					__( 'WordPress %1$s or later (this site runs %2$s)', 'agentporter' ),
					self::MIN_WP,
					$this->wp_version
				);
			}
		}
		return sprintf(
			/* translators: %s: list of unmet requirements. */
			__( 'AgentPorter is not running because it needs %s. Ask your host to upgrade, then reload this page. Nothing else in AgentPorter has been loaded.', 'agentporter' ),
			implode( __( ' and ', 'agentporter' ), $parts )
		);
	}

	/**
	 * Hooks the admin notice and the activation guard. Called only when requirements are not met.
	 *
	 * @param string $plugin_file Main plugin file.
	 * @return void
	 */
	public function hook_failure( string $plugin_file ) {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_notice' ) );
		register_activation_hook( $plugin_file, array( $this, 'block_activation' ) );
	}

	/**
	 * Prints the notice on the Plugins and Dashboard screens for users who can manage plugins.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = null !== $screen ? (string) $screen->id : '';
		if ( ! in_array( $id, array( 'plugins', 'plugins-network', 'dashboard', 'dashboard-network' ), true ) ) {
			return;
		}
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $this->message() ) );
	}

	/**
	 * Stops activation when requirements are not met.
	 *
	 * @return void
	 */
	public function block_activation() {
		wp_die(
			esc_html( $this->message() ),
			esc_html__( 'AgentPorter cannot be activated', 'agentporter' ),
			array(
				'response'  => 200,
				'back_link' => true,
			)
		);
	}
}
