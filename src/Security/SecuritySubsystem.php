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
			RateLimiter::class,
			static fn( Container $c ): RateLimiter => new RateLimiter(
				wp_using_ext_object_cache()
					? new CacheRateStore()
					: new DatabaseRateStore( $c->get( \wpdb::class ), $c->get( Schema::class ) )
			)
		);
		$container->set(
			AuditLog::class,
			static fn( Container $c ): AuditLog => new AuditLog( $c->get( \wpdb::class ), $c->get( Schema::class ), $c->get( Redactor::class ) )
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
	}
}
