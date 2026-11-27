<?php
/**
 * Real multi-process race test for Core\Lock (CI, MySQL/MariaDB only).
 *
 * Forks N processes, each with its own database connection, that all try to acquire the same lock at
 * the same instant. Exactly one must win, for a fresh lock and for a stale-lock takeover.
 * Run after the integration suite (which installs WordPress into the test database):
 *   WP_TESTS_DIR=/tmp/wordpress-tests-lib php tests/concurrency/lock-race.php
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- CLI test harness.

if ( ! function_exists( 'pcntl_fork' ) ) {
	fwrite( STDERR, "pcntl is required.\n" );
	exit( 2 );
}

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
require $tests_dir . '/wp-tests-config.php';
if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
	exit( 2 );
}
$_SERVER['HTTP_HOST']   = WP_TESTS_DOMAIN;
$_SERVER['SERVER_NAME'] = WP_TESTS_DOMAIN;
$PHP_SELF               = $GLOBALS['PHP_SELF'] = $_SERVER['PHP_SELF'] = '/index.php';
define( 'WP_INSTALLING', true ); // Skip plugin loading; we only need $wpdb and options.
require ABSPATH . 'wp-settings.php';

if ( file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
	echo "SKIP: database drop-in present (this race test needs MySQL/MariaDB).\n";
	exit( 0 );
}

require dirname( __DIR__, 2 ) . '/src/Core/Lock.php';

const PROCESSES = 8;
const ROUNDS    = 10;

/**
 * Runs one race round. Returns the number of winners.
 *
 * @param callable $prepare Prepares the lock row before the race.
 */
function agentporter_race( callable $prepare ): int {
	global $wpdb;
	$name = 'race';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'agentporter_lock_' . $name ) );
	$prepare( $wpdb, 'agentporter_lock_' . $name );

	$start = microtime( true ) + 0.4;
	$pids  = array();
	for ( $i = 0; $i < PROCESSES; $i++ ) {
		$pid = pcntl_fork();
		if ( -1 === $pid ) {
			fwrite( STDERR, "fork failed\n" );
			exit( 2 );
		}
		if ( 0 === $pid ) {
			// Child: its own connection, then wait for the shared start instant.
			$GLOBALS['wpdb'] = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$GLOBALS['wpdb']->set_prefix( $GLOBALS['table_prefix'] );
			wp_cache_init();
			while ( microtime( true ) < $start ) {
				usleep( 200 );
			}
			try {
				$won = ( new \AgentPorter\Core\Lock( $GLOBALS['wpdb'], $name, 600 ) )->acquire();
			} catch ( \Throwable $e ) {
				exit( 2 );
			}
			exit( $won ? 0 : 1 );
		}
		$pids[] = $pid;
	}

	// The parent's connection is shared with the children after fork: reconnect.
	$GLOBALS['wpdb'] = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$GLOBALS['wpdb']->set_prefix( $GLOBALS['table_prefix'] );

	$winners = 0;
	foreach ( $pids as $pid ) {
		pcntl_waitpid( $pid, $status );
		$code = pcntl_wexitstatus( $status );
		if ( 2 === $code ) {
			fwrite( STDERR, "a child errored\n" );
			exit( 1 );
		}
		$winners += 0 === $code ? 1 : 0;
	}
	return $winners;
}

$failures = 0;
$scenarios = array(
	'fresh lock'     => static function ( $wpdb, $option ) {},
	'stale takeover' => static function ( $wpdb, $option ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $option, 'option_value' => ( time() - 10 ) . ':crashed-holder', 'autoload' => 'off' ) );
	},
);
foreach ( $scenarios as $label => $prepare ) {
	for ( $round = 1; $round <= ROUNDS; $round++ ) {
		$winners = agentporter_race( $prepare );
		if ( 1 !== $winners ) {
			fwrite( STDERR, "FAIL: {$label}, round {$round}: {$winners} winners\n" );
			++$failures;
		}
	}
	echo "{$label}: " . ROUNDS . ' rounds x ' . PROCESSES . " processes\n";
}

global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'agentporter_lock_race' ) );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "PASS: exactly one winner in every round.\n";
