<?php
/**
 * Hook registrar bound to one subsystem.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps every callback so an exception disables only the owning subsystem.
 *
 * - Actions: the exception is caught and the subsystem is disabled.
 * - Filters: the exception is caught, the subsystem is disabled and the unmodified input is returned,
 *   so a broken subsystem never changes the value other code sees.
 *
 * Security decisions must not rely on this wrapper to deny: an authorization filter that throws
 * returns its input. Authorization code (AgentGuard) catches its own exceptions and fails closed.
 */
final class Hooks {

	/**
	 * Constructor.
	 *
	 * @param string $subsystem Owning subsystem id.
	 * @param Faults $faults    Failure state.
	 */
	public function __construct( private string $subsystem, private Faults $faults ) {
	}

	/**
	 * Adds a wrapped action.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted arguments.
	 */
	public function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook, $this->wrap_action( $callback ), $priority, $accepted_args );
	}

	/**
	 * Adds a wrapped filter.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted arguments.
	 */
	public function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $this->wrap_filter( $callback ), $priority, $accepted_args );
	}

	/**
	 * Wraps an action callback.
	 *
	 * @param callable $callback Callback.
	 * @return \Closure
	 */
	public function wrap_action( callable $callback ): \Closure {
		return function ( ...$args ) use ( $callback ): void {
			if ( $this->faults->is_disabled( $this->subsystem ) ) {
				return;
			}
			try {
				$callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->faults->disable( $this->subsystem, $error );
			}
		};
	}

	/**
	 * Wraps a filter callback.
	 *
	 * @param callable $callback Callback.
	 * @return \Closure
	 */
	public function wrap_filter( callable $callback ): \Closure {
		return function ( ...$args ) use ( $callback ) {
			$value = $args[0] ?? null;
			if ( $this->faults->is_disabled( $this->subsystem ) ) {
				return $value;
			}
			try {
				return $callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->faults->disable( $this->subsystem, $error );
				return $value;
			}
		};
	}
}
