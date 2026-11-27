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
}
