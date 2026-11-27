<?php
/**
 * Subsystems must register hooks through the Hooks registrar so the failure boundary applies, and
 * must never remove other code's hooks.
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

	private const ADD_FUNCTIONS    = array( 'add_action', 'add_filter' );
	private const REMOVE_FUNCTIONS = array( 'remove_action', 'remove_filter', 'remove_all_actions', 'remove_all_filters' );

	/**
	 * Finds raw hook registration or removal in PHP source, using tokens (not regex) so aliases,
	 * string callables and variable function names are caught too.
	 *
	 * @param string $source  PHP source.
	 * @param bool   $allowed Whether add_* is allowed in this file.
	 * @return list<string> Offending names with line numbers.
	 */
	public static function scan( string $source, bool $allowed ): array {
		$found  = array();
		$tokens = token_get_all( $source );
		$banned = array_merge( self::REMOVE_FUNCTIONS, $allowed ? array() : self::ADD_FUNCTIONS );
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			[ $id, $text, $line ] = $token;
			$name                 = strtolower( ltrim( $text, '\\' ) );
			if ( in_array( $id, array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) && in_array( $name, $banned, true ) ) {
				$prev = self::previous( $tokens, $i );
				// "use function add_action as x;" imports the function: a bypass.
				$is_import = 'function' === $prev && 'use' === self::previous( $tokens, self::index_of_previous( $tokens, $i ) );
				// Method calls ($hooks->add_action, Foo::add_action) and method declarations are fine.
				if ( ! $is_import && in_array( $prev, array( '->', '?->', '::', 'function' ), true ) ) {
					continue;
				}
				$found[] = $name . ':' . $line;
			} elseif ( in_array( $id, array( T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && 1 === preg_match( '/wp_filter/i', $text ) ) {
				// Direct manipulation of the global hook registry ($GLOBALS['wp_filter'], $wp_filter).
				$found[] = 'wp_filter:' . $line;
			} elseif ( in_array( $id, array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) && 'wp_hook' === strtolower( ltrim( $text, '\\' ) ) ) {
				$found[] = 'WP_Hook:' . $line;
			} elseif ( T_CONSTANT_ENCAPSED_STRING === $id && in_array( strtolower( ltrim( trim( $text, '\'"' ), '\\' ) ), $banned, true ) ) {
				// String callables such as call_user_func with a hook function name, or a variable function name.
				$found[] = 'string ' . trim( $text, '\'"' ) . ':' . $line;
			}
		}
		return $found;
	}

	/**
	 * Index of the previous significant token.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Current index.
	 */
	private static function index_of_previous( array $tokens, int $index ): int {
		for ( $j = $index - 1; $j >= 0; $j-- ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $j;
		}
		return 0;
	}

	/**
	 * Previous significant token text.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Current index.
	 */
	private static function previous( array $tokens, int $index ): string {
		for ( $j = $index - 1; $j >= 0; $j-- ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return is_array( $t ) ? strtolower( $t[1] ) : $t;
		}
		return '';
	}

	public function test_no_raw_hook_registration_or_removal_in_src(): void {
		$root       = dirname( __DIR__, 2 );
		$violations = array();
		$files      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$relative = substr( (string) $file, strlen( $root ) + 1 );
			if ( '.php' !== substr( $relative, -4 ) ) {
				continue;
			}
			foreach ( self::scan( (string) file_get_contents( (string) $file ), in_array( $relative, self::ALLOWED, true ) ) as $hit ) {
				$violations[] = $relative . ' ' . $hit;
			}
		}
		$this->assertSame( array(), $violations, 'Use the Hooks registrar inside subsystems, and never remove other code\'s hooks.' );
	}

	public function test_scanner_catches_aliases_strings_variables_and_removals(): void {
		$bypasses = array(
			'<?php add_action( "init", "f" );',
			'<?php \\add_filter( "x", "f" );',
			'<?php use function add_action as reg; reg( "init", "f" );',
			'<?php call_user_func( "add_action", "init", "f" );',
			'<?php $f = \'add_filter\'; $f( "x", "f" );',
			'<?php remove_all_filters( "the_content" );',
			'<?php remove_action( "init", "other_plugin" );',
			'<?php call_user_func( "\\add_action", "init", "f" );',
			'<?php unset( $GLOBALS["wp_filter"]["init"] );',
			'<?php global $wp_filter; $wp_filter = array();',
			'<?php $h = new WP_Hook();',
			'<?php $h = new \\WP_Hook();',
			'<?php \\WP_Hook::build_preinitialized_hooks( array() );',
		);
		foreach ( $bypasses as $code ) {
			$this->assertNotSame( array(), self::scan( $code, false ), $code );
		}
		$this->assertSame( array(), self::scan( '<?php $hooks->add_action( "init", $cb ); Foo::add_filter(); function add_filter_x() {}', false ) );
		$this->assertNotSame( array(), self::scan( '<?php remove_filter( "x", "y" );', true ), 'Removal is never allowed.' );
	}
}
