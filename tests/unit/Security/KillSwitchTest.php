<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Security\KillSwitch;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \AgentPorter\Security\KillSwitch
 */
final class KillSwitchTest extends TestCase {

	public function test_constant_forces_on_even_if_option_says_off(): void {
		Functions\when( 'get_option' )->justReturn( '0' );
		$switch = new KillSwitch( true );
		$this->assertTrue( $switch->is_on() );
		$this->assertTrue( $switch->is_forced_by_constant() );
	}

	public function test_option_on_turns_it_on(): void {
		Functions\when( 'get_option' )->justReturn( '1' );
		$this->assertTrue( ( new KillSwitch( false ) )->is_on() );
	}

	public function test_unexpected_option_values_fail_closed(): void {
		foreach ( array( 'yes', 'off', array( 'x' ), 2, ' 0' ) as $value ) {
			Functions\when( 'get_option' )->justReturn( $value );
			$this->assertTrue( ( new KillSwitch( false ) )->is_on(), var_export( $value, true ) );
		}
	}

	public function test_missing_or_explicit_off_is_off(): void {
		foreach ( array( null, false, '0', 0 ) as $value ) {
			Functions\when( 'get_option' )->justReturn( $value );
			$this->assertFalse( ( new KillSwitch( false ) )->is_on(), var_export( $value, true ) );
		}
	}

	public function test_default_reads_constant_which_is_undefined_in_tests(): void {
		Functions\when( 'get_option' )->justReturn( null );
		$this->assertFalse( defined( KillSwitch::CONSTANT ) );
		$this->assertFalse( ( new KillSwitch() )->is_on() );
	}

	public function test_set_writes_a_non_autoloaded_flag(): void {
		Functions\expect( 'update_option' )->once()->with( KillSwitch::OPTION, '1', false );
		Functions\expect( 'update_option' )->once()->with( KillSwitch::OPTION, '0', false );
		$switch = new KillSwitch( false );
		$switch->set( true );
		$switch->set( false );
		$this->addToAssertionCount( 2 );
	}

	public function test_empty_string_counts_as_on(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		$this->assertTrue( ( new KillSwitch( false ) )->is_on() );
	}

	public function test_refresh_rereads_past_the_cache(): void {
		Functions\expect( 'wp_cache_delete' )->once()->with( KillSwitch::OPTION, 'options' );
		Functions\when( 'get_option' )->justReturn( '1' );
		$this->assertTrue( ( new KillSwitch( false ) )->refresh() );
	}
}
