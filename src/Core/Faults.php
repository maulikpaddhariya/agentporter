<?php
/**
 * Records subsystems disabled by an exception during this request.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Failure boundary state. A disabled subsystem's wrapped hooks become no-ops for the rest of the
 * request; other subsystems keep running.
 */
final class Faults {

	/**
	 * Disabled subsystem id => exception class.
	 *
	 * @var array<string, string>
	 */
	private array $disabled = array();

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Debug logger.
	 */
	public function __construct( private Logger $logger ) {
	}

	/**
	 * Disables a subsystem after an exception.
	 *
	 * @param string     $subsystem Subsystem id.
	 * @param \Throwable $error     What was thrown.
	 */
	public function disable( string $subsystem, \Throwable $error ): void {
		if ( isset( $this->disabled[ $subsystem ] ) ) {
			return;
		}
		$this->disabled[ $subsystem ] = get_class( $error );

		$this->logger->error(
			sprintf(
				'subsystem "%s" disabled for this request: %s: %s at %s:%d',
				$subsystem,
				get_class( $error ),
				$error->getMessage(),
				$error->getFile(),
				$error->getLine()
			)
		);

		try {
			/**
			 * Fires when AgentPorter disables a subsystem for the rest of the request after an exception.
			 *
			 * @param string $subsystem       Subsystem id.
			 * @param string $exception_class Exception class name (never the message).
			 */
			do_action( 'agentporter_subsystem_failed', $subsystem, get_class( $error ) );
		} catch ( \Throwable $listener_error ) {
			$this->logger->error( 'agentporter_subsystem_failed listener threw ' . get_class( $listener_error ) );
		}
	}

	/**
	 * Whether a subsystem is disabled.
	 *
	 * @param string $subsystem Subsystem id.
	 */
	public function is_disabled( string $subsystem ): bool {
		return isset( $this->disabled[ $subsystem ] );
	}

	/**
	 * All disabled subsystems.
	 *
	 * @return array<string, string> Subsystem id => exception class.
	 */
	public function disabled(): array {
		return $this->disabled;
	}
}
