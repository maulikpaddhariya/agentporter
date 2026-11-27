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
 * Exceptions used deliberately as control flow (AgentPorter's DecisionException, WordPress' die
 * exceptions, and any classes opted in with with_decisions()) are rethrown unchanged and never
 * disable the subsystem.
 *
 * Deny Closures passed to guard hooks must not throw; if one does, the exception escapes and the
 * request fails (closed).
 */
final class Hooks {

	/**
	 * Exception classes that are always decisions, not faults. Checked by name so they need not exist.
	 * Other classes (for example WooCommerce's RouteException) are opted in per registrar with
	 * {@see with_decisions()}, because elsewhere they usually signal a fault.
	 */
	private const DECISION_EXCEPTIONS = array(
		'WPDieException',
		'WPAjaxDieContinueException',
		'WPAjaxDieStopException',
	);

	/**
	 * Classes too broad to be "decisions": opting them in would hide every bug.
	 */
	private const TOO_BROAD = array( 'throwable', 'stringable', 'exception', 'error', 'runtimeexception', 'logicexception', 'errorexception', 'typeerror' );

	/**
	 * Whether guard hooks of this registrar also deny while agent access is locked down. On by
	 * default (fail closed); only the front-end request gate opts out with for_front_end().
	 *
	 * @var bool
	 */
	private bool $agent_facing = true;

	/**
	 * Extra decision exception classes for this registrar.
	 *
	 * @var list<string>
	 */
	private array $extra_decisions = array();

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
	 * Returns a registrar that also treats the given exception classes as deliberate decisions
	 * (rethrown, subsystem not disabled). Use for hooks where WordPress or WooCommerce documents
	 * throwing as the way to reject, for example Store API validation hooks.
	 *
	 * @param array $class_names Exception class names.
	 * @return self
	 * @throws \InvalidArgumentException For base or built-in exception classes.
	 *
	 * @phpstan-param list<string> $class_names
	 */
	public function with_decisions( array $class_names ): self {
		foreach ( $class_names as $class_name ) {
			$name         = strtolower( ltrim( $class_name, '\\' ) );
			$is_interface = interface_exists( $class_name, false );
			$is_internal  = class_exists( $class_name, false ) && ( new \ReflectionClass( $class_name ) )->isInternal();
			if ( in_array( $name, self::TOO_BROAD, true ) || $is_interface || $is_internal ) {
				throw new \InvalidArgumentException( 'Only specific, non-built-in exception classes can be decisions.' );
			}
		}
		$clone                  = clone $this;
		$clone->extra_decisions = array_values( array_unique( array_merge( $this->extra_decisions, $class_names ) ) );
		return $clone;
	}

	/**
	 * Returns a registrar whose guard hooks ignore lockdown (they still deny when their own subsystem
	 * or a dependency fails). Only for the front-end request gate, which must fail open to serving
	 * the page (SECURITY-MODEL E1). Every other guard denies under lockdown.
	 *
	 * @return self
	 */
	public function for_front_end(): self {
		$clone               = clone $this;
		$clone->agent_facing = false;
		return $clone;
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
	 *                                arguments to build a fresh value (for example a WP_Error). Other
	 *                                callables (such as '__return_false') are rejected: they would be
	 *                                returned as a truthy value.
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
	 * @throws \InvalidArgumentException For a non-Closure callable or an unexpected object as deny value.
	 */
	public function wrap_guard_filter( callable $callback, $deny ): \Closure {
		$bad_object = is_object( $deny ) && ! ( $deny instanceof \Closure ) && ! ( $deny instanceof \WP_Error );
		if ( $bad_object || ( ! $deny instanceof \Closure && is_callable( $deny ) ) ) {
			throw new \InvalidArgumentException( 'A guard deny value must be a Closure, a scalar, null, an array or a WP_Error.' );
		}
		return function ( ...$args ) use ( $callback, $deny ) {
			if ( $this->guard_inactive() ) {
				return $deny instanceof \Closure ? $deny( ...$args ) : $deny;
			}
			try {
				$result = $callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->fault( $error );
				return $deny instanceof \Closure ? $deny( ...$args ) : $deny;
			}
			// The subsystem (or a dependency) may have failed while the callback ran, for example in
			// a nested filter it called. Its answer can no longer be trusted: deny. Do not remove this
			// as "always false": guard_inactive() reads state the callback can change.
			if ( $this->guard_inactive() ) {
				return $deny instanceof \Closure ? $deny( ...$args ) : $deny;
			}
			return $result;
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
			if ( $this->guard_inactive() ) {
				$on_deny( ...$args );
				return;
			}
			try {
				$callback( ...$args );
			} catch ( \Throwable $error ) {
				$this->fault( $error );
				$on_deny( ...$args );
				return;
			}
			// Re-check after the callback (state may have changed). Guard callbacks should only decide;
			// do the actual work in a later hook, so a late denial has no side effects to undo.
			if ( $this->guard_inactive() ) {
				$on_deny( ...$args );
			}
		};
	}

	/**
	 * Whether a throwable is a deliberate control-flow decision.
	 *
	 * @param \Throwable $error Thrown value.
	 * @param array      $extra Extra decision classes.
	 *
	 * @phpstan-param list<string> $extra
	 */
	public static function is_decision( \Throwable $error, array $extra = array() ): bool {
		if ( $error instanceof DecisionException ) {
			return true;
		}
		foreach ( array_merge( self::DECISION_EXCEPTIONS, $extra ) as $class_name ) {
			if ( is_a( $error, $class_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Guard hooks: inactive also under lockdown when the registrar is agent-facing.
	 *
	 * @phpstan-impure
	 */
	private function guard_inactive(): bool {
		return $this->inactive() || ( $this->agent_facing && $this->faults->is_locked_down() );
	}

	/**
	 * Whether this subsystem or one of its dependencies is disabled. Impure: callbacks can change it.
	 *
	 * @phpstan-impure
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
		if ( self::is_decision( $error, $this->extra_decisions ) ) {
			throw $error;
		}
		$this->faults->disable( $this->subsystem, $error );
	}
}
