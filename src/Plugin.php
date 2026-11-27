<?php
/**
 * Composition root.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter;

use AgentPorter\Core\Container;
use AgentPorter\Core\DependsOn;
use AgentPorter\Core\Faults;
use AgentPorter\Core\FaultNotice;
use AgentPorter\Core\Hooks;
use AgentPorter\Core\Logger;
use AgentPorter\Core\SecurityCritical;
use AgentPorter\Core\Subsystem;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the core services and boots each subsystem inside its own failure boundary.
 *
 * This is the only singleton in the plugin (ARCHITECTURE.md §10.3). The subsystem list is fixed in
 * code and the container is sealed after boot, so other plugins cannot add or replace services.
 */
final class Plugin {

	private const ID_PATTERN = '/^[a-z][a-z0-9_-]{1,39}$/';

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Booted subsystem ids, in boot order.
	 *
	 * @var list<string>
	 */
	private array $booted_subsystems = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container        Service container.
	 * @param Faults    $faults           Failure state.
	 * @param array     $subsystems       Subsystem classes, in boot order.
	 * @param array     $critical_classes Subsystem classes whose failure, for any reason (including the
	 *                                    class failing to load), locks agent access down.
	 *
	 * @phpstan-param list<class-string<Subsystem>> $subsystems
	 * @phpstan-param list<class-string<Subsystem>> $critical_classes
	 */
	public function __construct(
		private Container $container,
		private Faults $faults,
		private array $subsystems,
		private array $critical_classes = array()
	) {
	}

	/**
	 * The shared instance with the default wiring.
	 *
	 * @throws Core\ContainerException Only if the default wiring is broken (a programming error).
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			$container = new Container();
			$container->set( \wpdb::class, static fn(): \wpdb => $GLOBALS['wpdb'] );
			$container->set( Logger::class, static fn(): Logger => new Logger() );
			$container->set( Faults::class, static fn( Container $c ): Faults => new Faults( $c->get( Logger::class ) ) );
			self::$instance = new self( $container, $container->get( Faults::class ), self::default_subsystems(), self::critical_subsystems() );
		}
		return self::$instance;
	}

	/**
	 * Subsystems shipped with the plugin, in boot order. Not filterable: other plugins must not be able
	 * to remove or replace security subsystems.
	 *
	 * @return list<class-string<Subsystem>>
	 */
	public static function default_subsystems(): array {
		return array();
	}

	/**
	 * Security-critical subsystems, declared here rather than read from the class, so that a class
	 * that fails to load still triggers lockdown.
	 *
	 * @return list<class-string<Subsystem>>
	 */
	public static function critical_subsystems(): array {
		return array();
	}

	/**
	 * Boots every subsystem. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		( new FaultNotice( $this->faults ) )->register();

		// A critical subsystem that is not in the boot list would never run: deny agent access.
		foreach ( $this->critical_classes as $critical ) {
			if ( ! in_array( $critical, $this->subsystems, true ) ) {
				$this->faults->lock_down();
			}
		}

		// Failure listeners run only after the container is sealed, so they cannot register services.
		$this->faults->begin_deferral();
		try {
			foreach ( $this->subsystems as $class_name ) {
				$this->boot_one( $class_name );
			}
		} finally {
			$this->container->seal();
			$this->faults->end_deferral();
		}

		try {
			/**
			 * Fires after AgentPorter booted its subsystems.
			 *
			 * @param array{booted: list<string>, disabled: list<string>, locked_down: bool} $status Read-only boot status.
			 */
			do_action(
				'agentporter_booted',
				array(
					'booted'      => $this->booted_subsystems,
					'disabled'    => array_keys( $this->faults->disabled() ),
					'locked_down' => $this->faults->is_locked_down(),
				)
			);
		} catch ( \Throwable $error ) {
			// A third-party listener must never take the site down.
			if ( $this->container->has( Logger::class ) ) {
				$this->container->get( Logger::class )->error( 'agentporter_booted listener threw ' . Faults::class_name( $error ) );
			}
		}
	}

	/**
	 * Boots one subsystem inside its failure boundary. Never throws: every failure is recorded.
	 *
	 * @param string $class_name Subsystem class.
	 * @throws \LogicException Internally for a missing dependency; always caught here.
	 */
	private function boot_one( string $class_name ): void {
		// Until a subsystem proves a valid, unique id, failures are recorded under "class:<name>", which
		// the id pattern can never match, so a bad subsystem cannot disable another by claiming its id.
		$fault_id = 'class:' . $class_name;
		$critical = in_array( $class_name, $this->critical_classes, true );
		$snapshot = $this->container->ids();

		try {
			// Anything that can autoload the class (and so throw) stays inside the boundary.
			$critical = $critical || is_subclass_of( $class_name, SecurityCritical::class );

			[ $subsystem, $id ] = $this->instantiate( $class_name );
			$fault_id           = $id;
			if ( $critical ) {
				$this->faults->mark_critical( $id );
			}

			$dependencies = $subsystem instanceof DependsOn ? array_values( $subsystem->depends_on() ) : array();
			$this->faults->register_dependencies( $id, $dependencies );
			foreach ( $dependencies as $dependency ) {
				if ( ! in_array( $dependency, $this->booted_subsystems, true ) || $this->faults->is_disabled( $dependency ) ) {
					throw new \LogicException( 'A required subsystem is not available.' );
				}
			}

			$subsystem->register( $this->container, new Hooks( $id, $this->faults, $dependencies ) );
			if ( $this->faults->is_disabled( $id ) ) {
				// A dependency failed while this subsystem was registering (cascade): treat as failed.
				throw new \LogicException( 'Disabled during registration.' );
			}
			$this->booted_subsystems[] = $id;
		} catch ( \Throwable $error ) {
			// Record the failure first: nothing below may prevent lockdown or the disable.
			if ( $critical ) {
				$this->faults->lock_down();
			}
			$this->faults->disable( $fault_id, $error );
			// Drop services the failed subsystem registered so nothing else can resolve half-built state.
			try {
				$this->container->rollback( $snapshot );
			} catch ( \Throwable $rollback_error ) {
				// The subsystem sealed or broke the container: treat as critical.
				$this->faults->lock_down();
			}
		}
	}

	/**
	 * Whether boot() ran.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Subsystems that booted without error.
	 *
	 * @return list<string>
	 */
	public function booted_subsystems(): array {
		return $this->booted_subsystems;
	}

	/**
	 * Failure state.
	 */
	public function faults(): Faults {
		return $this->faults;
	}

	/**
	 * Service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Instantiates and validates a subsystem; reads its id exactly once.
	 *
	 * @param string $class_name Subsystem class.
	 * @return array{0: Subsystem, 1: string}
	 * @throws \LogicException When the class is not a subsystem or its id is invalid or taken.
	 */
	private function instantiate( string $class_name ): array {
		$subsystem = new $class_name();
		if ( ! $subsystem instanceof Subsystem ) {
			throw new \LogicException( 'Not a subsystem.' );
		}
		$id = $subsystem->id();
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || in_array( $id, $this->booted_subsystems, true ) || $this->faults->is_disabled( $id ) ) {
			throw new \LogicException( 'Invalid or duplicate subsystem id.' );
		}
		return array( $subsystem, $id );
	}
}
