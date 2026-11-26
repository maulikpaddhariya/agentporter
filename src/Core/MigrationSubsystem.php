<?php
/**
 * Wires schema migrations into WordPress.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs pending migrations only where a database write is acceptable: plugin activation, wp-admin
 * requests by users who can activate plugins, WP-Cron and WP-CLI, and new sites on a network.
 * Front-end visitor requests never trigger a migration (no request-time writes).
 */
final class MigrationSubsystem implements Subsystem {

	/**
	 * Container (set in register()).
	 *
	 * @var Container|null
	 */
	private ?Container $container = null;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'migrations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Service container.
	 * @param Hooks     $hooks     Hook registrar.
	 */
	public function register( Container $container, Hooks $hooks ): void {
		$this->container = $container;

		$container->set( Schema::class, static fn( Container $c ): Schema => new Schema( $c->get( \wpdb::class ) ) );
		$container->set(
			Migrator::class,
			static fn( Container $c ): Migrator => new Migrator(
				$c->get( \wpdb::class ),
				$c->get( Schema::class ),
				new Lock( $c->get( \wpdb::class ), 'migrate', 600 )
			)
		);

		$hooks->add_action( 'admin_init', array( $this, 'on_admin_init' ) );
		$hooks->add_action( 'init', array( $this, 'on_init' ) );
		$hooks->add_action( 'wp_initialize_site', array( $this, 'on_initialize_site' ), 20 );
	}

	/**
	 * Admin requests: migrate when the current user can activate plugins.
	 */
	public function on_admin_init(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			$this->migrator()->migrate();
		}
	}

	/**
	 * Cron and CLI requests: migrate. Front-end visitor requests: never.
	 */
	public function on_init(): void {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->migrator()->migrate();
		}
	}

	/**
	 * New site on a network where AgentPorter is network-active: create its tables.
	 *
	 * @param mixed $site New site.
	 */
	public function on_initialize_site( $site ): void {
		if ( ! $site instanceof \WP_Site || ! self::is_network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			$this->migrator()->migrate();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Whether AgentPorter is network-activated.
	 */
	public static function is_network_active(): bool {
		if ( ! is_multisite() || ! defined( 'AGENTPORTER_FILE' ) ) {
			return false;
		}
		$active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $active ) && isset( $active[ plugin_basename( AGENTPORTER_FILE ) ] );
	}

	/**
	 * Migrator service.
	 *
	 * @throws \LogicException When called before register().
	 */
	private function migrator(): Migrator {
		if ( null === $this->container ) {
			throw new \LogicException( 'Migration subsystem is not registered.' );
		}
		return $this->container->get( Migrator::class );
	}
}
