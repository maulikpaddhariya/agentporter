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
 * requests by users who manage the site (plugin managers; on a network, also the site's own
 * administrators), WP-Cron and WP-CLI, Network Admin → Upgrade Network, and new network sites.
 * Front-end page views, REST and anonymous admin-ajax never trigger a migration. The up-to-date
 * check is a cached option read, so these hooks cost nothing once the schema is current.
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

		$hooks->add_action( 'admin_init', $this->on_admin_init( ... ) );
		$hooks->add_action( 'init', $this->on_init( ... ) );
		$hooks->add_action( 'wp_initialize_site', $this->on_initialize_site( ... ), 20 );
		$hooks->add_action( 'wpmu_upgrade_site', $this->on_upgrade_site( ... ) );
	}

	/**
	 * Admin requests: migrate when the current user can activate plugins.
	 */
	public function on_admin_init(): void {
		$can = current_user_can( 'activate_plugins' ) || ( is_multisite() && current_user_can( 'manage_options' ) );
		if ( $can ) {
			$this->migrator()->migrate();
		}
	}

	/**
	 * Cron and CLI requests: migrate. Front-end visitor requests: never.
	 */
	public function on_init(): void {
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( $cli || wp_doing_cron() ) {
			$this->migrator()->migrate( $cli );
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
			$this->migrator()->migrate( true );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Network Admin → Upgrade Network fires this once per site (from the network admin, without
	 * switching): migrate that site. Only reachable by network admins.
	 *
	 * @param mixed $blog_id Site id.
	 */
	public function on_upgrade_site( $blog_id ): void {
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 || ! self::is_network_active() || ! current_user_can( 'manage_network' ) || null === get_site( $blog_id ) ) {
			return;
		}
		switch_to_blog( $blog_id );
		try {
			$this->migrator()->migrate( true );
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
