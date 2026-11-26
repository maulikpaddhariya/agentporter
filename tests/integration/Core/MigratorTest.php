<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Integration\Core;

use AgentPorter\Core\Activator;
use AgentPorter\Core\Lock;
use AgentPorter\Core\MigrationSubsystem;
use AgentPorter\Core\Migrator;
use AgentPorter\Core\Schema;
use AgentPorter\Plugin;

/**
 * @covers \AgentPorter\Core\Migrator
 * @covers \AgentPorter\Core\Schema
 * @covers \AgentPorter\Core\Activator
 * @covers \AgentPorter\Core\MigrationSubsystem
 */
final class MigratorTest extends \WP_UnitTestCase {

	private function schema(): Schema {
		global $wpdb;
		return new Schema( $wpdb );
	}

	/**
	 * @param array<int, callable(): void>|null $steps Steps.
	 */
	private function migrator( ?array $steps = null ): Migrator {
		global $wpdb;
		return new Migrator( $wpdb, $this->schema(), new Lock( $wpdb, 'migrate' ), $steps );
	}

	private function reset_schema(): void {
		global $wpdb;
		foreach ( Schema::suffixes() as $suffix ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix ); // phpcs:ignore WordPress.DB
		}
		delete_option( Migrator::OPTION );
	}

	public function set_up(): void {
		parent::set_up();
		$this->reset_schema();
	}

	public function test_shipped_steps_lead_to_current_schema_version(): void {
		$this->assertSame( Schema::VERSION, $this->migrator()->target_version() );
	}

	public function test_fresh_install_creates_every_table_and_records_version(): void {
		$migrator = $this->migrator();
		$this->assertTrue( $migrator->needs_migration() );
		$this->assertSame( Migrator::RESULT_MIGRATED, $migrator->migrate() );
		foreach ( $this->schema()->create_statements() as $table => $sql ) {
			$this->assertTrue( $migrator->table_exists( $table ), $table );
		}
		$this->assertSame( Schema::VERSION, $migrator->installed_version() );
		$this->assertFalse( $migrator->needs_migration() );
	}

	public function test_tables_have_the_documented_columns(): void {
		global $wpdb;
		$this->migrator()->migrate();
		$expected = array(
			Schema::DAILY       => array( 'day', 'kind', 'source_id', 'verified', 'hits', 'bytes' ),
			Schema::PAGE_DAILY  => array( 'day', 'kind', 'source_id', 'object_id', 'path_hash', 'path', 'hits' ),
			Schema::EVENTS      => array( 'id', 'ts', 'kind', 'source_id', 'ip', 'ip_hash', 'path', 'status', 'verdict' ),
			Schema::IP_VERDICTS => array( 'ip', 'bot_id', 'verdict', 'checked_at' ),
			Schema::AUDIT       => array( 'id', 'ts', 'actor_type', 'actor_id', 'ability', 'input_redacted', 'result', 'error_code', 'ip_trunc', 'duration_ms' ),
			Schema::RATE        => array( 'bucket', 'window_start', 'count' ),
		);
		foreach ( $expected as $suffix => $columns ) {
			$actual = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $wpdb->prefix . $suffix ), 0 );
			$this->assertSame( $columns, $actual, $suffix );
		}
	}

	public function test_running_again_is_a_no_op(): void {
		$this->migrator()->migrate();
		$this->assertSame( Migrator::RESULT_UP_TO_DATE, $this->migrator()->migrate() );
	}

	public function test_create_tables_twice_is_idempotent(): void {
		global $wpdb;
		$migrator = $this->migrator();
		$migrator->create_tables();
		$before = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $wpdb->prefix . Schema::AUDIT ), ARRAY_A );
		$migrator->create_tables();
		$after = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $wpdb->prefix . Schema::AUDIT ), ARRAY_A );
		$this->assertCount( count( $before ), $after );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_concurrent_migration_attempt_runs_steps_once(): void {
		$runs     = 0;
		$inner    = null;
		$migrator = $this->migrator(
			array(
				1 => function () use ( &$runs, &$inner ): void {
					++$runs;
					// A second process arrives while the first is mid-migration.
					$inner = $this->migrator(
						array(
							1 => static function () use ( &$runs ): void {
								++$runs;
							},
						)
					)->migrate();
				},
			)
		);
		$this->assertSame( Migrator::RESULT_MIGRATED, $migrator->migrate() );
		$this->assertSame( Migrator::RESULT_LOCKED, $inner );
		$this->assertSame( 1, $runs );
	}

	public function test_process_that_read_a_stale_version_does_no_work_after_lock(): void {
		$runs = 0;
		$step = static function () use ( &$runs ): void {
			++$runs;
		};
		$this->migrator( array( 1 => $step ) )->migrate();
		// Same steps again (as a process that checked the version before the first finished).
		$this->assertSame( Migrator::RESULT_UP_TO_DATE, $this->migrator( array( 1 => $step ) )->migrate() );
		$this->assertSame( 1, $runs );
	}

	public function test_failed_step_releases_lock_and_does_not_advance_version(): void {
		$failing = $this->migrator(
			array(
				1 => static function (): void {},
				2 => static function (): void {
					throw new \RuntimeException( 'step 2 failed' );
				},
			)
		);
		try {
			$failing->migrate();
			$this->fail( 'Expected the step to throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'step 2 failed', $e->getMessage() );
		}
		$this->assertSame( 1, $failing->installed_version() );

		global $wpdb;
		$this->assertTrue( ( new Lock( $wpdb, 'migrate' ) )->acquire(), 'Lock was released.' );
	}

	public function test_newer_installed_schema_is_left_alone(): void {
		update_option( Migrator::OPTION, '99' );
		$runs = 0;
		$this->assertSame(
			Migrator::RESULT_UP_TO_DATE,
			$this->migrator(
				array(
					1 => static function () use ( &$runs ): void {
						++$runs;
					},
				)
			)->migrate()
		);
		$this->assertSame( 0, $runs );
	}

	public function test_unknown_table_suffix_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->schema()->table( 'users' );
	}

	public function test_activation_by_non_admin_does_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		Activator::activate( false );
		$this->assertSame( 0, $this->migrator()->installed_version() );
	}

	public function test_activation_and_reactivation_create_schema_idempotently(): void {
		$this->become_admin();
		Activator::activate( false );
		Activator::activate( false );
		$this->assertSame( Schema::VERSION, $this->migrator()->installed_version() );
		$this->assertSame( array(), Plugin::instance()->faults()->disabled() );
	}

	private function subsystem(): MigrationSubsystem {
		$subsystem = new MigrationSubsystem();
		$container = new \AgentPorter\Core\Container();
		$container->set( \wpdb::class, static fn() => $GLOBALS['wpdb'] );
		$subsystem->register( $container, new \AgentPorter\Core\Hooks( 'migrations-test', Plugin::instance()->faults() ) );
		return $subsystem;
	}

	public function test_migration_callbacks_are_hooked(): void {
		$this->subsystem();
		$this->assertNotFalse( has_action( 'admin_init' ) );
		$this->assertNotFalse( has_action( 'init' ) );
		$this->assertNotFalse( has_action( 'wp_initialize_site' ) );
	}

	public function test_admin_request_migrates_only_for_plugin_managers(): void {
		$subsystem = $this->subsystem();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$subsystem->on_admin_init();
		$this->assertSame( 0, $this->migrator()->installed_version() );

		$this->become_admin();
		$subsystem->on_admin_init();
		$this->assertSame( Schema::VERSION, $this->migrator()->installed_version() );
	}

	public function test_front_end_request_does_not_migrate(): void {
		$this->subsystem()->on_init();
		$this->assertSame( 0, $this->migrator()->installed_version() );
	}

	public function test_cron_request_migrates(): void {
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->subsystem()->on_init();
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( Schema::VERSION, $this->migrator()->installed_version() );
	}

	public function test_initialize_site_ignores_non_site_values(): void {
		$this->subsystem()->on_initialize_site( 'not a site' );
		$this->assertSame( 0, $this->migrator()->installed_version() );
	}

	public function test_new_network_site_gets_tables_when_network_active(): void {
		if ( ! is_multisite() ) {
			$this->assertFalse( MigrationSubsystem::is_network_active() );
			$this->assertNotFalse( has_action( 'wp_initialize_site' ) );
			return;
		}
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( AGENTPORTER_FILE ) => time() ) );
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		$this->assertSame( Schema::VERSION, $this->migrator()->installed_version() );
		global $wpdb;
		$this->assertTrue( $this->migrator()->table_exists( $wpdb->prefix . Schema::DAILY ) );
		restore_current_blog();
	}

	private function become_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
	}
}
