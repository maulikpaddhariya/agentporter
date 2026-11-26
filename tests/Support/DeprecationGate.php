<?php
/**
 * Fails the test run on any notice, warning or deprecation raised by AgentPorter code.
 *
 * WordPress core, WooCommerce and third-party code may still raise deprecations on
 * new PHP versions; those are passed through untouched. Only issues whose file is
 * inside the plugin's own production code count.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Support;

/**
 * Error handler that records issues raised from first-party code and fails the run at shutdown.
 */
final class DeprecationGate {

	/**
	 * Absolute path prefixes that count as AgentPorter production code.
	 *
	 * @var list<string>
	 */
	private static array $owned_paths = array();

	/**
	 * Issues recorded during the run.
	 *
	 * @var list<string>
	 */
	private static array $issues = array();

	/**
	 * Previously installed handler, chained for non-owned code.
	 *
	 * @var callable|null
	 */
	private static $previous = null;

	/**
	 * Whether the gate is installed.
	 *
	 * @var bool
	 */
	private static bool $installed = false;

	/**
	 * Installs the gate.
	 *
	 * @param string $plugin_root Absolute plugin root directory.
	 */
	public static function install( string $plugin_root ): void {
		if ( self::$installed ) {
			return;
		}
		self::$installed = true;

		$root              = rtrim( (string) realpath( $plugin_root ), '/' );
		self::$owned_paths = array(
			$root . '/src/',
			$root . '/agentporter.php',
			$root . '/uninstall.php',
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Test-only: the gate must see every issue.
		error_reporting( E_ALL );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only gate.
		self::$previous = set_error_handler( array( self::class, 'handle' ) );

		register_shutdown_function( array( self::class, 'report' ) );
	}

	/**
	 * Error handler.
	 *
	 * @param int    $errno   Error level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return bool
	 * @throws \ErrorException When the issue was raised by AgentPorter code.
	 */
	public static function handle( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		if ( self::is_owned( $errfile ) ) {
			self::$issues[] = sprintf( '[%s] %s in %s:%d', self::level_name( $errno ), $errstr, $errfile, $errline );
			// Throw for runtime issues so the offending test fails with a clear trace.
			throw new \ErrorException( 'AgentPorter deprecation gate: ' . $errstr, 0, $errno, $errfile, $errline ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test output, not HTML.
		}

		if ( null !== self::$previous ) {
			return (bool) call_user_func( self::$previous, $errno, $errstr, $errfile, $errline );
		}

		return false;
	}

	/**
	 * Returns recorded issues (for the gate's own tests).
	 *
	 * @return list<string>
	 */
	public static function issues(): array {
		return self::$issues;
	}

	/**
	 * Clears recorded issues (for the gate's own tests only).
	 */
	public static function reset(): void {
		self::$issues = array();
	}

	/**
	 * Whether a file belongs to AgentPorter production code.
	 *
	 * @param string $file File path.
	 */
	public static function is_owned( string $file ): bool {
		if ( '' === $file ) {
			return false;
		}
		$real = realpath( $file );
		$path = false === $real ? $file : $real;
		foreach ( self::$owned_paths as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prints recorded issues and forces a failing exit code.
	 */
	public static function report(): void {
		if ( array() === self::$issues ) {
			return;
		}
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
		fwrite( STDERR, "\n\nAgentPorter deprecation gate FAILED: " . count( self::$issues ) . " issue(s) raised by AgentPorter code:\n" );
		foreach ( self::$issues as $issue ) {
			fwrite( STDERR, '  - ' . $issue . "\n" );
		}
		// phpcs:enable
		exit( 1 );
	}

	/**
	 * Human-readable error level.
	 *
	 * @param int $errno Error level.
	 */
	private static function level_name( int $errno ): string {
		return match ( $errno ) {
			E_DEPRECATED, E_USER_DEPRECATED => 'deprecation',
			E_NOTICE, E_USER_NOTICE         => 'notice',
			E_WARNING, E_USER_WARNING       => 'warning',
			default                         => 'error ' . $errno,
		};
	}
}
