<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Integration\Core;

use AgentPorter\Core\Lock;
use AgentPorter\Core\Migrator;
use AgentPorter\Core\Schema;
use AgentPorter\Core\Uninstaller;

/**
 * @covers \AgentPorter\Core\Uninstaller
 */
final class UninstallerTest extends \WP_UnitTestCase {

	private function migrator(): Migrator {
		global $wpdb;
		return new Migrator( $wpdb, new Schema( $wpdb ), new Lock( $wpdb, 'migrate' ) );
	}

	public function set_up(): void {
		parent::set_up();
		$this->migrator()->migrate( true );
		update_option( 'agentporter_settings', array( 'a' => 1 ) );
		update_option( 'agentporter_secret_test', 'v1:x', false );
	}

	public function test_without_opt_in_data_is_kept(): void {
		$this->assertFalse( Uninstaller::uninstall_site() );
		global $wpdb;
		$this->assertTrue( $this->migrator()->table_exists( $wpdb->prefix . Schema::AUDIT ) );
		$this->assertNotFalse( get_option( 'agentporter_settings' ) );
	}

	public function test_with_opt_in_tables_and_options_are_removed_idempotently(): void {
		update_option( Uninstaller::OPT_IN_OPTION, '1' );
		update_option( 'unrelated_option', 'keep' );
		$this->assertTrue( Uninstaller::uninstall_site() );
		global $wpdb;
		foreach ( Schema::suffixes() as $suffix ) {
			$this->assertFalse( $this->migrator()->table_exists( $wpdb->prefix . $suffix ), $suffix );
		}
		$this->assertFalse( get_option( 'agentporter_settings' ) );
		$this->assertFalse( get_option( 'agentporter_secret_test' ) );
		$this->assertFalse( get_option( Migrator::OPTION ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
		$this->assertFalse( Uninstaller::uninstall_site(), 'Opt-in removed with the rest; second run is a no-op.' );
	}

	public function test_only_options_owned_by_the_free_plugin_are_removed(): void {
		update_option( Uninstaller::OPT_IN_OPTION, '1' );
		update_option( 'agentporter_pro_license', 'keep-for-pro' );
		update_option( 'agentporterx_other', 'keep' );
		Uninstaller::uninstall_site();
		$this->assertSame( 'keep-for-pro', get_option( 'agentporter_pro_license' ) );
		$this->assertSame( 'keep', get_option( 'agentporterx_other' ) );
	}

	public function test_more_options_than_one_pass_are_all_removed(): void {
		update_option( Uninstaller::OPT_IN_OPTION, '1' );
		for ( $i = 0; $i < 620; $i++ ) {
			add_option( 'agentporter_secret_bulk' . $i, 'v', '', false );
		}
		Uninstaller::uninstall_site();
		global $wpdb;
		$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'agentporter_secret_' ) . '%' ) );
		$this->assertSame( 0, $left );
	}

	public function test_every_option_the_code_creates_is_owned(): void {
		$names = array( Migrator::OPTION, Migrator::RETRY_OPTION, ( new Lock( $GLOBALS['wpdb'], 'x' ) )->option_name(), 'agentporter_secret_example', Uninstaller::OPT_IN_OPTION, \AgentPorter\Security\KillSwitch::OPTION, \AgentPorter\Security\AgentGuard::ENABLED_OPTION );
		foreach ( $names as $name ) {
			$owned = false;
			foreach ( Uninstaller::OWNED_PREFIXES as $prefix ) {
				$owned = $owned || str_starts_with( $name, $prefix );
			}
			$this->assertTrue( $owned, $name . ' is not covered by Uninstaller::OWNED_PREFIXES' );
		}
	}

	public function test_network_uninstall_respects_per_site_opt_in(): void {
		global $wpdb;
		if ( ! is_multisite() ) {
			Uninstaller::run();
			$this->assertTrue( $this->migrator()->table_exists( $wpdb->prefix . Schema::AUDIT ), 'No opt-in: kept.' );
			return;
		}
		$opted = self::factory()->blog->create();
		$kept  = self::factory()->blog->create();
		foreach ( array( $opted, $kept ) as $blog ) {
			switch_to_blog( $blog );
			$this->migrator()->migrate( true );
			restore_current_blog();
		}
		switch_to_blog( $opted );
		update_option( Uninstaller::OPT_IN_OPTION, '1' );
		restore_current_blog();

		Uninstaller::run();

		switch_to_blog( $opted );
		$this->assertFalse( $this->migrator()->table_exists( $wpdb->prefix . Schema::AUDIT ) );
		restore_current_blog();
		switch_to_blog( $kept );
		$this->assertTrue( $this->migrator()->table_exists( $wpdb->prefix . Schema::AUDIT ) );
		restore_current_blog();
	}
}
