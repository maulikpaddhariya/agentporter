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
		foreach ( array( '[email]', '[number]', '[ip]', '[agent-key]', 'Bearer [token]', '[token]' ) as $mask ) {
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

	/**
	 * Secrets and personal data that must never survive redaction.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function leak_corpus(): array {
		// Fake credentials are assembled at runtime so no key-shaped literal is committed (secret scanning).
		$aws_id = 'AKIA' . strtoupper( substr( str_repeat( 'q7zx', 4 ), 0, 16 ) );
		$stripe = 'sk_' . 'live_' . str_repeat( 'Ab1Cd2Ef3', 3 );
		$github = 'ghp' . '_' . str_repeat( 'A1b2C3d4E5', 4 );
		$aws_sk = str_repeat( 'w7Jx/K9q+Z', 4 );
		return array(
			'phone at sentence end'   => array( 'Call me at 9876543210.', '9876543210' ),
			'card at sentence end'    => array( 'card 4111111111111111.', '4111111111111111' ),
			'glued phone'             => array( 'ph9876543210 please', '9876543210' ),
			'indian mobile spaced'    => array( 'mobile +91-98765-43210 ok', '98765-43210' ),
			'aws access key'          => array( 'key ' . $aws_id . ' here', $aws_id ),
			'aws secret key'          => array( 'secret ' . $aws_sk . ' end', substr( $aws_sk, 0, 13 ) ),
			'basic auth plus slash'   => array( 'Authorization: Basic dXNlcjpwYXNz+/w== x', 'dXNlcjpwYXNz' ),
			'password in text'        => array( 'login password=hunter2 now', 'hunter2' ),
			'token in query string'   => array( 'GET /cb?access_token=abcDEF123ghiJKL456&x=1', 'abcDEF123ghiJKL456' ),
			'url userinfo'            => array( 'https://admin:s3cr3t@example.org/x', 's3cr3t' ),
			'short jwt'               => array( 'tok eyJhbGciOiJIUzI1NiJ9.eyJlIjoiYUBiLmMifQ.sig ok', 'eyJlIjoiYUBiLmMifQ' ),
			'wp application password' => array( 'use abcd EFGH 1234 ijkl MNOP 5678 to sign in', 'abcd EFGH 1234' ),
			'stripe key'              => array( $stripe . ' ok', substr( $stripe, 0, 22 ) ),
			'github token'            => array( $github, substr( $github, 0, 12 ) ),
			'idn email'               => array( 'mail müller@bücher.de now', 'müller' ),
		);
	}

	/**
	 * @dataProvider leak_corpus
	 *
	 * @param string $input Input.
	 * @param string $leak  Fragment that must disappear.
	 */
	public function test_corpus_never_leaks( string $input, string $leak ): void {
		$this->assertStringNotContainsString( $leak, ( new Redactor() )->redact_string( $input ) );
	}

	public function test_camel_case_and_extended_keys_are_masked(): void {
		$out = ( new Redactor() )->redact(
			array(
				'accessToken'  => 'a',
				'clientSecret' => 'b',
				'newPassword'  => 'c',
				'privateKey'   => 'd',
				'cardNumber'   => 'e',
				'mobile'       => 'f',
				'otp'          => 'g',
				'credentials'  => 'h',
				'shipping'     => 'keep',
				'query'        => 'keep',
			)
		);
		foreach ( array( 'accessToken', 'clientSecret', 'newPassword', 'privateKey', 'cardNumber', 'mobile', 'otp', 'credentials' ) as $key ) {
			$this->assertSame( Redactor::MASK, $out[ $key ], $key );
		}
		$this->assertSame( 'keep', $out['shipping'] );
		$this->assertSame( 'keep', $out['query'] );
	}

	public function test_array_keys_integers_and_nested_json_are_redacted(): void {
		$json = ( new Redactor() )->to_json(
			array(
				'jane@example.com' => 'x',
				'n'                => 9876543210,
				'small'            => 42,
				'payload'          => '{"password":"hunter2","note":"call 9876543210"}',
			)
		);
		$this->assertStringNotContainsString( 'jane@example.com', $json );
		$this->assertStringNotContainsString( '9876543210', $json );
		$this->assertStringNotContainsString( 'hunter2', $json );
		$this->assertStringContainsString( '42', $json );
	}

	public function test_adversarial_inputs_finish_quickly(): void {
		$inputs = array(
			str_repeat( '1 ', 1000 ),
			str_repeat( 'a@', 1000 ),
			str_repeat( ':', 2000 ),
			str_repeat( 'aaaa ', 400 ),
			str_repeat( 'eyJ.', 500 ),
			str_repeat( '+1(', 666 ),
			'https://' . str_repeat( 'a:', 990 ),
			str_repeat( 'password=', 220 ),
		);
		foreach ( $inputs as $input ) {
			$start = microtime( true );
			( new Redactor() )->redact_string( $input );
			$this->assertLessThan( 0.05, microtime( true ) - $start, 'ReDoS budget (SECURITY-MODEL §8: < 50 ms).' );
		}
	}
}
