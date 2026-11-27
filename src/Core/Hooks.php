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
 * Wraps every callback so an unexpected exception disables only the owning subsystem.
 *
 * Two families, chosen by what a failure must mean:
 *
 * - add_filter() / add_action(): for behaviour where "do nothing" is safe (tracking, UI, output).
 *   On failure, or once the subsystem (or a dependency) is disabled, filters return their input
 *   unchanged and actions do nothing.
 * - add_guard_filter() / add_guard_action(): for anything that allows or denies. On failure, or
 *   once the subsystem (or a dependency) is disabled, filters return the deny value and actions run
 *   the deny handler. They fail closed, never open.
 *
 * Exceptions that WordPress, WooCommerce or AgentPorter use deliberately as control flow (for
 * example a Store API RouteException that rejects a checkout) are rethrown unchanged and never
 * disable the subsystem.
 */
final class Hooks {

	/**
	 * Exception classes that are decisions, not faults. Checked by name so the classes need not exist.
	 */
	private const DECISION_EXCEPTIONS = array(
		'WPDieException',
		'WPAjaxDieContinueException',
		'WPAjaxDieStopException',
		'WC_Data_Exception',
		'WC_REST_Exception',
		'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException',
	);

	/**
	 * Constructor.
	 *
	 * @param string $subsystem    Owning subsystem id.
	 * @param Faults $faults       Failure state.
	 * @param array  $dependencies Ids of subsystems this one depends on.
	 *
	 * @phpstan-param list<string> $dependencies
	 */
	public function __construct(
		private string $subsystem,
		private Faults $faults,
		private array $dependencies = array()
	) {
	}

	/**
	 * Adds a wrapped action where doing nothing on failure is safe.
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
	 * Adds a wrapped filter where returning the input unchanged on failure is safe.
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
	 * Adds a fail-closed filter for an allow/deny decision.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param mixed    $deny          Value returned on failure. A \Closure is called with the hook's
	 *                                arguments to build a fresh value (for example a WP_Error).
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted arguments.
	 */
	public function add_guard_filter( string $hook, callable $callback, $deny, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $this->wrap_guard_filter( $callback, $deny ), $priority, $accepted_args );
	}

	/**
	 * Adds a fail-closed action (for example one that blocks a request).
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param callable $on_deny       Runs instead of the callback on failure (for example: send 403 and exit).
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted arguments.
	 */
	public function add_guard_action( string $hook, callable $callback, callable $on_deny, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook, $this->wrap_guard_action( $callback, $on_deny ), $priority, $accepted_args );
	}

	/**
	 * Wraps an action callback (safe-to-skip).
	 *
	 * @param callable $callback Callback.
	 * @return \Closure
	 */
	public function wrap_action( callable $callback ): \Closure {
		return function ( ...$args ) use ( $callback ): void {
			if ( $this->inactive() ) {
				return;
			}
			try {
				$callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->fault( $error );
			}
		};
	}

	/**
	 * Wraps a filter callback (safe-to-pass-through).
	 *
	 * @param callable $callback Callback.
	 * @return \Closure
	 */
	public function wrap_filter( callable $callback ): \Closure {
		return function ( ...$args ) use ( $callback ) {
			$value = $args[0] ?? null;
			if ( $this->inactive() ) {
				return $value;
			}
			try {
				return $callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->fault( $error );
				return $value;
			}
		};
	}

	/**
	 * Wraps a fail-closed filter.
	 *
	 * @param callable $callback Callback.
	 * @param mixed    $deny     Deny value or \Closure building it.
	 * @return \Closure
	 */
	public function wrap_guard_filter( callable $callback, $deny ): \Closure {
		return function ( ...$args ) use ( $callback, $deny ) {
			if ( $this->inactive() ) {
				return $deny instanceof \Closure ? $deny( ...$args ) : $deny;
			}
			try {
				return $callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->fault( $error );
				return $deny instanceof \Closure ? $deny( ...$args ) : $deny;
			}
		};
	}

	/**
	 * Wraps a fail-closed action.
	 *
	 * @param callable $callback Callback.
	 * @param callable $on_deny  Deny handler.
	 * @return \Closure
	 */
	public function wrap_guard_action( callable $callback, callable $on_deny ): \Closure {
		return function ( ...$args ) use ( $callback, $on_deny ): void {
			if ( $this->inactive() ) {
				$on_deny( ...$args );
				return;
			}
			try {
				$callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->fault( $error );
				$on_deny( ...$args );
			}
		};
	}

	/**
	 * Whether a throwable is a deliberate control-flow decision.
	 *
	 * @param \Throwable $error Thrown value.
	 */
	public static function is_decision( \Throwable $error ): bool {
		if ( $error instanceof DecisionException ) {
			return true;
		}
		foreach ( self::DECISION_EXCEPTIONS as $class_name ) {
			if ( is_a( $error, $class_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this subsystem or one of its dependencies is disabled.
	 */
	private function inactive(): bool {
		if ( $this->faults->is_disabled( $this->subsystem ) ) {
			return true;
		}
		foreach ( $this->dependencies as $dependency ) {
			if ( $this->faults->is_disabled( $dependency ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rethrows decisions; records faults.
	 *
	 * @param \Throwable $error Thrown value.
	 * @throws \Throwable The same throwable when it is a deliberate decision.
	 */
	private function fault( \Throwable $error ): void {
		if ( self::is_decision( $error ) ) {
			throw $error;
		}
		$this->faults->disable( $this->subsystem, $error );
	}
}
