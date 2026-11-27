<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Security;

use AgentPorter\Core\Schema;
use AgentPorter\Security\AuditLog;
use AgentPorter\Security\Redactor;
use AgentPorter\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Fixtures/WordPressDoubles.php';

/**
 * @covers \AgentPorter\Security\AuditLog
 */
final class AuditLogTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
		$this->wpdb = new \wpdb();
	}

	private function log(): AuditLog {
		return new AuditLog( $this->wpdb, new Schema( $this->wpdb ), new Redactor() );
	}

	public function test_failed_write_throws(): void {
		$this->wpdb->fail = true;
		$this->expectException( \RuntimeException::class );
		$this->log()->record( 'user', 1, 'agentporter/site-info', array(), AuditLog::RESULT_DENIED, 'x', '', 1 );
	}

	public function test_inputs_are_redacted_and_bounded(): void {
		$this->log()->record(
			'app_password',
			7,
			'agentporter/search-content',
			array(
				'query' => 'contact jane@example.com',
				'token' => 'abc',
				'blob'  => str_repeat( 'x', 20000 ),
			),
			AuditLog::RESULT_ALLOWED,
			'',
			'203.0.113.77',
			12 
		);
		$row = $this->wpdb->inserted[0][1];
		$this->assertStringNotContainsString( 'jane@example.com', $row['input_redacted'] );
		$this->assertStringNotContainsString( '"abc"', $row['input_redacted'] );
		$this->assertLessThanOrEqual( 4096, strlen( $row['input_redacted'] ) );
	}

	public function test_ips_are_truncated_never_stored_whole(): void {
		$this->assertSame( inet_pton( '203.0.113.0' ), AuditLog::truncate_ip( '203.0.113.77' ) );
		$this->assertSame( inet_pton( '2001:db8:abcd::' ), AuditLog::truncate_ip( '2001:db8:abcd:12:34::9' ) );
		$this->assertNull( AuditLog::truncate_ip( 'not-an-ip' ) );
		$this->assertNull( AuditLog::truncate_ip( '' ) );
	}

	public function test_untrusted_fields_are_normalised(): void {
		$this->log()->record( "evil'type", -5, 'agentporter/<script>' . str_repeat( 'a', 200 ), null, 99, 'Bad Code!<>', 'x', -1 );
		$row = $this->wpdb->inserted[0][1];
		$this->assertSame( 'unknown', $row['actor_type'] );
		$this->assertSame( 0, $row['actor_id'] );
		$this->assertStringNotContainsString( '<', $row['ability'] );
		$this->assertLessThanOrEqual( 100, strlen( $row['ability'] ) );
		$this->assertSame( AuditLog::RESULT_ERROR, $row['result'] );
		$this->assertSame( 'badcode', $row['error_code'] );
		$this->assertNull( $row['ip_trunc'] );
		$this->assertSame( 0, $row['duration_ms'] );
	}

	public function test_entry_goes_to_audit_table_with_formats(): void {
		$this->log()->record( 'cli', 3, 'agentporter/site-info', array(), AuditLog::RESULT_ALLOWED, '', '', 5 );
		[ $table, $row, $formats ] = $this->wpdb->inserted[0];
		$this->assertSame( 'wp_agentporter_audit', $table );
		$this->assertCount( count( $row ), $formats );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['ts'] );
	}
}
