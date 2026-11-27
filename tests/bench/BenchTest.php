<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Bench;

use AgentPorter\Core\Lock;
use AgentPorter\Core\Migrator;
use AgentPorter\Core\Schema;
use AgentPorter\Plugin;
use AgentPorter\Security\AgentContext;
use AgentPorter\Security\AgentGuard;
use AgentPorter\Security\Redactor;

/**
 * Benchmark harness: gate timings and memory budgets on real WordPress (run on every PR).
 */
final class BenchTest extends \WP_UnitTestCase {

	/**
	 * Budgets.
	 *
	 * @var array<string, float|int>
	 */
	private static array $budgets = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$file          = getenv( 'AGENTPORTER_BENCH_BUDGETS' );
		self::$budgets = require $file ? $file : __DIR__ . '/budgets.php';
	}

	/**
	 * Median duration in milliseconds of $runs calls.
	 *
	 * @param callable $work Work.
	 * @param int      $runs Runs.
	 */
	private static function median_ms( callable $work, int $runs ): float {
		$times = array();
		for ( $i = 0; $i < $runs; $i++ ) {
			$start = hrtime( true );
			$work();
			$times[] = ( hrtime( true ) - $start ) / 1e6;
		}
		sort( $times );
		return $times[ intdiv( count( $times ), 2 ) ];
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		( new Migrator( $wpdb, new Schema( $wpdb ), new Lock( $wpdb, 'migrate' ) ) )->migrate( true );
	}

	public function test_guard_check_is_within_budget_and_does_not_leak(): void {
		update_option( AgentGuard::ENABLED_OPTION, array( 'agentporter/bench' ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		( new \WP_User( $user ) )->add_cap( AgentGuard::CAP_READ );
		$guard = Plugin::instance()->container()->get( AgentGuard::class );
		$i     = 0;
		$call  = static function () use ( $guard, $user, &$i ) {
			// Spread across networks so the per-network limit never trips during the run.
			++$i;
			$guard->check( 'agentporter/bench', new AgentContext( 'app_password', $user, '203.0.113.' . ( $i % 250 ) ) );
		};
		self::median_ms( $call, 20 ); // Warm up.
		$median = self::median_ms( $call, 40 );
		$before = memory_get_usage();
		for ( $n = 0; $n < 500; $n++ ) {
			$guard->check( 'agentporter/missing', new AgentContext( 'app_password', 0, '198.51.100.' . ( $n % 250 ) ) );
		}
		$leak_kb = ( memory_get_usage() - $before ) / 1024;
		fwrite( STDOUT, sprintf( "\n[bench] guard_check median %.3f ms, growth over 500 denials %.1f KB\n", $median, $leak_kb ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bench report.
		$this->assertLessThanOrEqual( self::$budgets['guard_check_ms'], $median, 'guard_check_ms budget' );
		$this->assertLessThanOrEqual( self::$budgets['guard_leak_kb'], $leak_kb, 'guard_leak_kb budget' );
	}

	public function test_redactor_is_within_budget(): void {
		$input  = array(
			'query'   => 'blue running shoes size 42',
			'filters' => array(
				'price_max' => 120,
				'in_stock'  => true,
			),
			'contact' => 'jane@example.com, +91 98765 43210',
			'note'    => 'Error: password=hunter2 at https://x.test/?token=abc123',
		);
		$median = self::median_ms( static fn() => ( new Redactor() )->to_json( $input ), 200 );
		fwrite( STDOUT, sprintf( "[bench] redactor median %.3f ms\n", $median ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bench report.
		$this->assertLessThanOrEqual( self::$budgets['redactor_ms'], $median, 'redactor_ms budget' );
	}

	public function test_loading_all_plugin_code_stays_within_the_memory_budget(): void {
		$script = escapeshellarg( __DIR__ . '/load-all-classes.php' );
		$bytes  = (int) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . $script ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Fresh process for an isolated measurement.
		$kb     = $bytes / 1024;
		fwrite( STDOUT, sprintf( "[bench] code memory %.1f KB\n", $kb ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bench report.
		$this->assertGreaterThan( 0, $bytes, 'measurement ran' );
		$this->assertLessThanOrEqual( self::$budgets['code_memory_kb'], $kb, 'code_memory_kb budget' );
	}
}
