<?php
/**
 * Tells administrators when a subsystem was switched off by an error.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Shown only on the Plugins screen, only to users who can manage plugins. Names the module, never
 * the exception message (which could contain paths).
 */
final class FaultNotice {

	/**
	 * Constructor.
	 *
	 * @param Faults $faults Failure state.
	 */
	public function __construct( private Faults $faults ) {
	}

	/**
	 * Registers the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Renders the notice when something is disabled.
	 */
	public function render(): void {
		$disabled = $this->faults->disabled();
		if ( array() === $disabled || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of module ids. */
					__( 'AgentPorter switched off part of itself after an error (modules: %s). Everything else keeps working, and agent access stays closed if a security module is affected. If this keeps happening, update AgentPorter or contact support.', 'agentporter' ),
					implode( ', ', array_keys( $disabled ) )
				)
			)
		);
	}
}
