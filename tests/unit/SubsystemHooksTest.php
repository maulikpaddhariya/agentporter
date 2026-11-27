<?php
/**
 * Subsystems must register hooks through the Hooks registrar so the failure boundary applies.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SubsystemHooksTest extends TestCase {

	/**
	 * Files allowed to call add_action()/add_filter() directly: the registrar itself and code that
	 * runs outside any subsystem (requirements check, fault notice).
	 */
	private const ALLOWED = array(
		'src/Core/Hooks.php',
		'src/Core/Requirements.php',
		'src/Core/FaultNotice.php',
	);

	public function test_no_raw_hook_registration_outside_the_registrar(): void {
		$root       = dirname( __DIR__, 2 );
		$violations = array();
		$files      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$relative = substr( (string) $file, strlen( $root ) + 1 );
			if ( '.php' !== substr( $relative, -4 ) || in_array( $relative, self::ALLOWED, true ) ) {
				continue;
			}
			$source = (string) file_get_contents( (string) $file );
			if ( preg_match_all( '/(?<!->|::|function )\badd_(?:action|filter)\s*\(/', $source, $m ) > 0 ) {
				$violations[] = $relative;
			}
		}
		$this->assertSame( array(), $violations, 'Use the Hooks registrar ($hooks->add_action / add_guard_filter …) inside subsystems.' );
	}
}
