<?php
/**
 * Composition root.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter;

use AgentPorter\Core\Container;
use AgentPorter\Core\Faults;
use AgentPorter\Core\FaultNotice;
use AgentPorter\Core\Hooks;
use AgentPorter\Core\Logger;
use AgentPorter\Core\Subsystem;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the core services and boots each subsystem inside its own failure boundary.
 *
 * This is the only singleton in the plugin (ARCHITECTURE.md §10.3).
 */
final class Plugin {

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
	 * @param Container $container  Service container.
	 * @param Faults    $faults     Failure state.
	 * @param array     $subsystems Subsystem classes, in boot order.
	 *
	 * @phpstan-param list<class-string<Subsystem>> $subsystems
	 */
	public function __construct(
		private Container $container,
		private Faults $faults,
		private array $subsystems
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
			$container->set( Logger::class, static fn(): Logger => new Logger() );
			$container->set( Faults::class, static fn( Container $c ): Faults => new Faults( $c->get( Logger::class ) ) );
			self::$instance = new self( $container, $container->get( Faults::class ), self::default_subsystems() );
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
	 * Boots every subsystem. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		( new FaultNotice( $this->faults ) )->register();

		$seen = array();
		foreach ( $this->subsystems as $class ) {
			// Until a subsystem proves a valid, unique id, failures are recorded under an id derived
			// from its class name, so a bad subsystem can never disable another one by claiming its id.
			$fault_id = $this->boundary_id( $class );
			try {
				$subsystem   = $this->instantiate( $class, $seen );
				$id          = $subsystem->id();
				$seen[ $id ] = true;
				$fault_id    = $id;
				$subsystem->register( $this->container, new Hooks( $id, $this->faults ) );
				$this->booted_subsystems[] = $id;
			} catch ( \Throwable $error ) {
				$this->faults->disable( $fault_id, $error );
			}
		}

		/**
		 * Fires after AgentPorter booted its subsystems.
		 *
		 * @param Plugin $plugin The plugin.
		 */
		do_action( 'agentporter_booted', $this );
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
	 * Instantiates and validates a subsystem.
	 *
	 * @param string              $class_name Subsystem class.
	 * @param array<string, bool> $seen       Ids already booted.
	 * @return Subsystem
	 * @throws \LogicException When the class is not a subsystem or its id is invalid or taken.
	 */
	private function instantiate( string $class_name, array $seen ): Subsystem {
		$subsystem = new $class_name();
		if ( ! $subsystem instanceof Subsystem ) {
			throw new \LogicException( 'Not a subsystem.' );
		}
		$id = $subsystem->id();
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]{1,39}$/', $id ) || isset( $seen[ $id ] ) ) {
			throw new \LogicException( 'Invalid or duplicate subsystem id.' );
		}
		return $subsystem;
	}

	/**
	 * Id used for a subsystem that failed before reporting its own id.
	 *
	 * @param string $class_name Subsystem class.
	 */
	private function boundary_id( string $class_name ): string {
		$short = substr( (string) strrchr( '\\' . $class_name, '\\' ), 1 );
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $short ) );
	}
}
