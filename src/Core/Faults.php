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
 * Failure boundary state. A disabled subsystem's wrapped hooks stop running for the rest of the
 * request (safe hooks pass through, guard hooks deny); other subsystems keep running.
 *
 * Lockdown: when a security-critical subsystem fails, {@see is_locked_down()} is true for the rest
 * of the request and every agent-facing path must deny.
 */
final class Faults {

	/**
	 * Disabled subsystem id => exception class.
	 *
	 * @var array<string, string>
	 */
	private array $disabled = array();

	/**
	 * Ids of security-critical subsystems.
	 *
	 * @var array<string, true>
	 */
	private array $critical = array();

	/**
	 * Whether agent access is locked down.
	 *
	 * @var bool
	 */
	private bool $locked_down = false;

	/**
	 * While true, failure actions are queued instead of fired (during boot, before the container is sealed).
	 *
	 * @var bool
	 */
	private bool $deferring = false;

	/**
	 * Dependency graph: subsystem id => ids it depends on.
	 *
	 * @var array<string, list<string>>
	 */
	private array $dependencies = array();

	/**
	 * Queued failure actions: [subsystem id, exception class].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $queued = array();

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Debug logger.
	 */
	public function __construct( private readonly Logger $logger ) {
	}

	/**
	 * Marks a subsystem id as security-critical (its failure triggers lockdown).
	 *
	 * @param string $subsystem Subsystem id.
	 */
	public function mark_critical( string $subsystem ): void {
		$this->critical[ $subsystem ] = true;
		if ( isset( $this->disabled[ $subsystem ] ) ) {
			$this->locked_down = true;
		}
	}

	/**
	 * Records what a subsystem depends on, so a later failure of a dependency also disables it.
	 *
	 * @param string $subsystem    Subsystem id.
	 * @param array  $dependencies Ids it depends on.
	 *
	 * @phpstan-param list<string> $dependencies
	 */
	public function register_dependencies( string $subsystem, array $dependencies ): void {
		// Write-once and merge-only: dependencies can be added, never removed, so the cascade cannot be weakened.
		$known                            = $this->dependencies[ $subsystem ] ?? array();
		$this->dependencies[ $subsystem ] = array_values( array_unique( array_merge( $known, $dependencies ) ) );
	}

	/**
	 * Enters lockdown directly (for example when a critical subsystem failed before reporting its id).
	 */
	public function lock_down(): void {
		$this->locked_down = true;
	}

	/**
	 * Whether agent access must be denied because a security-critical subsystem failed.
	 */
	public function is_locked_down(): bool {
		return $this->locked_down;
	}

	/**
	 * Disables a subsystem after an exception.
	 *
	 * @param string     $subsystem Subsystem id.
	 * @param \Throwable $error     What was thrown.
	 */
	public function disable( string $subsystem, \Throwable $error ): void {
		if ( isset( $this->critical[ $subsystem ] ) ) {
			$this->locked_down = true;
		}
		if ( isset( $this->disabled[ $subsystem ] ) ) {
			return;
		}
		$class                        = self::class_name( $error );
		$this->disabled[ $subsystem ] = $class;
		$this->cascade( $subsystem );

		// Never log the exception message (it may carry tokens, emails or request data) or absolute paths.
		$this->logger->error(
			sprintf(
				'subsystem "%s" disabled for this request: %s at %s:%d%s',
				$subsystem,
				$class,
				self::relative_path( $error->getFile() ),
				$error->getLine(),
				$this->locked_down ? ' (agent access locked down)' : ''
			)
		);

		if ( $this->deferring ) {
			$this->queued[] = array( $subsystem, $class );
			return;
		}
		$this->fire( $subsystem, $class );
	}

	/**
	 * Disables every subsystem that (transitively) depends on a disabled one, entering lockdown if any
	 * of them is security-critical. Runs at boot and at runtime alike.
	 *
	 * @param string $failed Disabled subsystem id.
	 */
	private function cascade( string $failed ): void {
		$queue = array( $failed );
		while ( array() !== $queue ) {
			$current = array_shift( $queue );
			foreach ( $this->dependencies as $dependent => $needs ) {
				if ( isset( $this->disabled[ $dependent ] ) || ! in_array( $current, $needs, true ) ) {
					continue;
				}
				$this->disabled[ $dependent ] = 'dependency:' . $current;
				if ( isset( $this->critical[ $dependent ] ) ) {
					$this->locked_down = true;
				}
				$queue[] = $dependent;
			}
		}
	}

	/**
	 * Queues failure actions until {@see end_deferral()} (used during boot so listeners cannot
	 * register services before the container is sealed).
	 */
	public function begin_deferral(): void {
		$this->deferring = true;
	}

	/**
	 * Fires queued failure actions and stops queueing.
	 */
	public function end_deferral(): void {
		$this->deferring = false;
		$queued          = $this->queued;
		$this->queued    = array();
		foreach ( $queued as $item ) {
			$this->fire( $item[0], $item[1] );
		}
	}

	/**
	 * Fires the failure action; listener errors never escape.
	 *
	 * @param string $subsystem       Subsystem id.
	 * @param string $exception_class Exception class name.
	 */
	private function fire( string $subsystem, string $exception_class ): void {
		try {
			/**
			 * Fires when AgentPorter disables a subsystem for the rest of the request after an exception.
			 *
			 * @param string $subsystem       Subsystem id.
			 * @param string $exception_class Exception class name (never the message).
			 */
			do_action( 'agentporter_subsystem_failed', $subsystem, $exception_class );
		} catch ( \Throwable $listener_error ) {
			$this->logger->error( 'agentporter_subsystem_failed listener threw ' . self::class_name( $listener_error ) );
		}
	}

	/**
	 * Exception class name safe to log: anonymous classes lose the NUL byte and file path PHP appends.
	 *
	 * @param \Throwable $error Thrown value.
	 */
	public static function class_name( \Throwable $error ): string {
		$name = $error::class;
		$pos  = strpos( $name, '@anonymous' );
		return false === $pos ? $name : substr( $name, 0, $pos + strlen( '@anonymous' ) );
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

	/**
	 * Path relative to the plugin (or WordPress) root; just the file name for anything else.
	 *
	 * @param string $file Absolute path.
	 */
	public static function relative_path( string $file ): string {
		$roots = array();
		if ( defined( 'AGENTPORTER_DIR' ) ) {
			$roots[] = (string) AGENTPORTER_DIR;
		}
		$roots[] = ABSPATH;
		foreach ( $roots as $root ) {
			if ( '' !== $root && str_starts_with( $file, $root ) ) {
				return substr( $file, strlen( $root ) );
			}
		}
		return basename( $file );
	}
}
