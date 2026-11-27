<?php
/**
 * Debug logger.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Writes diagnostic lines to the PHP error log only when the owner turned on WP_DEBUG and
 * WP_DEBUG_LOG. Never pass secrets, tokens, emails or full IPs here.
 *
 * The protected uploads log folder described in ARCHITECTURE.md §16 replaces the error_log
 * sink in a later task; callers do not change.
 */
final class Logger {

	private const MAX_LENGTH = 1000;

	/**
	 * Whether logging is enabled.
	 *
	 * @var bool
	 */
	private readonly bool $enabled;

	/**
	 * Constructor.
	 *
	 * @param bool|null $enabled Force on/off (tests); null reads WP_DEBUG and WP_DEBUG_LOG.
	 */
	public function __construct( ?bool $enabled = null ) {
		$this->enabled = $enabled ?? ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
	}

	/**
	 * Logs an error line.
	 *
	 * @param string $message Message without personal data or secrets.
	 */
	public function error( string $message ): void {
		if ( ! $this->enabled ) {
			return;
		}
		$line = 'AgentPorter: ' . preg_replace( '/[\r\n]+/', ' ', $message );
		if ( strlen( $line ) > self::MAX_LENGTH ) {
			$line = ( function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, self::MAX_LENGTH, 'UTF-8' ) : substr( $line, 0, self::MAX_LENGTH ) ) . '…';
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The plugin's single debug sink; only active with WP_DEBUG + WP_DEBUG_LOG.
	}
}
