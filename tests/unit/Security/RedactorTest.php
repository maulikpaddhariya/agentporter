<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Security\Redactor;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * @covers \AgentPorter\Security\Redactor
 */
final class RedactorTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
	}

	public function test_sensitive_field_names_are_masked_at_any_depth(): void {
		$out = ( new Redactor() )->redact(
			array(
				'query'    => 'shoes',
				'password' => 'hunter2',
				'nested'   => array(
					'api_key'       => 'abc',
					'user_password' => 'x',
					'Authorization' => 'Basic xyz',
				),
			)
		);
		$this->assertSame( 'shoes', $out['query'] );
		$this->assertSame( Redactor::MASK, $out['password'] );
		$this->assertSame( Redactor::MASK, $out['nested']['api_key'] );
		$this->assertSame( Redactor::MASK, $out['nested']['user_password'] );
		$this->assertSame( Redactor::MASK, $out['nested']['Authorization'] );
	}

	public function test_sensitive_values_are_masked_inside_free_text(): void {
		$text = ( new Redactor() )->redact_string(
			'mail jane.doe@example.com call +91 98765 43210 from 203.0.113.9 or 2001:db8::1 key ap_live_ab12cd34_' . str_repeat( 'z', 40 ) . ' auth Bearer eyJhbGciOi.payload.sig hex ' . str_repeat( 'a1', 20 )
		);
		foreach ( array( 'jane.doe', 'example.com', '98765', '203.0.113.9', '2001:db8', 'ap_live_ab12', 'eyJhbGciOi', str_repeat( 'a1', 20 ) ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $text, $leak );
		}
		foreach ( array( '[email]', '[phone]', '[ip]', '[agent-key]', 'Bearer [token]', '[token]' ) as $mask ) {
			$this->assertStringContainsString( $mask, $text, $mask );
		}
	}

	public function test_output_is_valid_json_within_the_byte_limit(): void {
		$huge = array_fill( 0, 500, str_repeat( 'é', 400 ) );
		$json = ( new Redactor() )->to_json( $huge, 4096 );
		$this->assertLessThanOrEqual( 4096, strlen( $json ) );
		$this->assertIsArray( json_decode( $json, true ) );
		$this->assertTrue( json_decode( $json, true )['_truncated'] );
	}

	public function test_depth_items_and_string_length_are_capped(): void {
		$deep = 'x';
		for ( $i = 0; $i < 20; $i++ ) {
			$deep = array( 'a' => $deep );
		}
		$out = ( new Redactor() )->redact( $deep );
		$this->assertStringContainsString( '[depth limit]', (string) json_encode( $out ) );

		$many = ( new Redactor() )->redact( range( 1, 150 ) );
		$this->assertSame( 50, $many['_more'] );

		$long = ( new Redactor() )->redact_string( str_repeat( 'b', 5000 ) );
		$this->assertLessThanOrEqual( 504, strlen( $long ) );
	}

	public function test_filter_can_add_fields_but_cannot_remove_defaults(): void {
		Filters\expectApplied( 'agentporter_redact_fields' )->andReturn( array( 'order_note' ) );
		$out = ( new Redactor() )->redact(
			array(
				'order_note' => 'leave at door',
				'password'   => 'p',
			)
		);
		$this->assertSame( Redactor::MASK, $out['order_note'] );
		$this->assertSame( Redactor::MASK, $out['password'] );
	}

	public function test_invalid_utf8_and_objects_are_handled(): void {
		$json    = ( new Redactor() )->to_json(
			array(
				'bad' => "\xB1\x31",
				'obj' => (object) array( 'token' => 't' ),
				'res' => fopen( 'php://memory', 'r' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test resource.
			)
		);
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( Redactor::MASK, $decoded['obj']['token'] );
		$this->assertSame( '[unsupported]', $decoded['res'] );
	}
}
