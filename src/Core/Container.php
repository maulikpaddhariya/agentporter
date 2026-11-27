<?php
/**
 * Minimal service container used only by the composition root.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds and caches services by id (normally a class or interface name).
 *
 * Services are registered explicitly by the composition root; there is no auto-wiring and no
 * public filter to replace services, so another plugin cannot swap a security service.
 */
final class Container {

	/**
	 * Service factories.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * Built services.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Ids currently being built (circular-dependency detection).
	 *
	 * @var array<string, true>
	 */
	private array $building = array();

	/**
	 * Whether new registrations are refused (set after boot).
	 *
	 * @var bool
	 */
	private bool $sealed = false;

	/**
	 * Registers a factory.
	 *
	 * @param string                     $id      Service id.
	 * @param callable(Container):object $factory Factory receiving the container.
	 * @throws ContainerException When the container is sealed or the id is already registered.
	 */
	public function set( string $id, callable $factory ): void {
		if ( $this->sealed ) {
			throw new ContainerException( 'The container is sealed; services can only be registered during boot.' );
		}
		if ( isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] ) ) {
			throw new ContainerException( sprintf( 'Service "%s" is already registered.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal message, never rendered as HTML.
		}
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Refuses any further registrations. Called once boot finishes.
	 */
	public function seal(): void {
		$this->sealed = true;
	}

	/**
	 * Registered ids, in registration order (used to roll back a failed subsystem).
	 *
	 * @return list<string>
	 */
	public function ids(): array {
		return array_keys( $this->factories + $this->instances );
	}

	/**
	 * Removes every service registered after the given snapshot of ids.
	 *
	 * @param array $snapshot Result of ids() taken earlier.
	 *
	 * @phpstan-param list<string> $snapshot
	 */
	public function rollback( array $snapshot ): void {
		$keep = array_flip( $snapshot );
		foreach ( $this->ids() as $id ) {
			if ( ! isset( $keep[ $id ] ) ) {
				unset( $this->factories[ $id ], $this->instances[ $id ] );
			}
		}
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Returns a service, building it on first use.
	 *
	 * @template T of object
	 * @param string $id Service id.
	 * @return object
	 *
	 * @phpstan-param class-string<T> $id
	 * @phpstan-return T
	 * @throws ContainerException When unknown, circular, or the factory returns the wrong type.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) && $this->instances[ $id ] instanceof $id ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new ContainerException( sprintf( 'Service "%s" is not registered.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal message, never rendered as HTML.
		}
		if ( isset( $this->building[ $id ] ) ) {
			throw new ContainerException( sprintf( 'Circular dependency while building "%s".', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal message, never rendered as HTML.
		}

		$this->building[ $id ] = true;
		try {
			$service = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->building[ $id ] );
		}

		if ( ! $service instanceof $id ) {
			throw new ContainerException( sprintf( 'Factory for "%s" returned the wrong type.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal message, never rendered as HTML.
		}

		$this->instances[ $id ] = $service;
		return $service;
	}
}
