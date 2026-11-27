<?php
/**
 * Wires the security core.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

use AgentPorter\Core\Container;
use AgentPorter\Core\Faults;
use AgentPorter\Core\Hooks;
use AgentPorter\Core\Logger;
use AgentPorter\Core\Schema;
use AgentPorter\Core\SecurityCritical;

defined( 'ABSPATH' ) || exit;

/**
 * Registers AgentGuard and its services. Security-critical: if this subsystem fails, the plugin
 * enters lockdown and every agent call is denied.
 */
final class SecuritySubsystem implements SecurityCritical {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Service container.
	 * @param Hooks     $hooks     Hook registrar.
	 */
	public function register( Container $container, Hooks $hooks ): void {
		if ( ! $container->has( Schema::class ) ) {
			$container->set( Schema::class, static fn( Container $c ): Schema => new Schema( $c->get( \wpdb::class ) ) );
		}
		$container->set( KillSwitch::class, static fn(): KillSwitch => new KillSwitch() );
		$container->set( Redactor::class, static fn(): Redactor => new Redactor() );
		$container->set( SecretStore::class, static fn(): SecretStore => new SecretStore() );
		$container->set(
			RateStore::class,
			static fn( Container $c ): RateStore => wp_using_ext_object_cache()
				? new CacheRateStore()
				: new DatabaseRateStore( $c->get( \wpdb::class ), $c->get( Schema::class ) )
		);
		$container->set( RateLimiter::class, static fn( Container $c ): RateLimiter => new RateLimiter( $c->get( RateStore::class ) ) );
		$container->set(
			AuditLog::class,
			static fn( Container $c ): AuditLog => new AuditLog( $c->get( \wpdb::class ), $c->get( Schema::class ), $c->get( Redactor::class ) )
		);
		$container->set(
			Maintenance::class,
			static function ( Container $c ): Maintenance {
				$store = $c->get( RateStore::class );
				return new Maintenance( $c->get( AuditLog::class ), $store instanceof DatabaseRateStore ? $store : null );
			}
		);
		$container->set(
			AgentGuard::class,
			static fn( Container $c ): AgentGuard => new AgentGuard(
				$c->get( Faults::class ),
				$c->get( KillSwitch::class ),
				$c->get( RateLimiter::class ),
				$c->get( AuditLog::class ),
				$c->get( Logger::class )
			)
		);
	
		$hooks->add_action( Maintenance::HOOK, static fn() => $container->get( Maintenance::class )->run() );
		// Scheduling writes the cron option, so only admin and CLI requests do it, never page views.
		$hooks->add_action( 'admin_init', array( Maintenance::class, 'ensure_scheduled' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$hooks->add_action( 'init', array( Maintenance::class, 'ensure_scheduled' ) );
		}
	}
}
