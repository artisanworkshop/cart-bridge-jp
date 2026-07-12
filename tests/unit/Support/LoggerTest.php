<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Support;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\Logger;
use WP_UnitTestCase;

final class LoggerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
	}

	public function test_log_writes_a_row_to_the_logs_table(): void {
		global $wpdb;

		$logger = new Logger();
		$logger->info(
			'test message',
			[
				'entity'    => 'product',
				'remote_id' => '123',
			]
		);

		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}cbjp_logs ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( Logger::LEVEL_INFO, $row['level'] );
		$this->assertSame( 'test message', $row['message'] );

		$context = json_decode( (string) $row['context_json'], true );
		$this->assertSame( '123', $context['remote_id'] );
	}

	public function test_log_stores_null_context_json_when_context_is_empty(): void {
		global $wpdb;

		$logger = new Logger();
		$logger->warning( 'no context here' );

		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}cbjp_logs ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		$this->assertNull( $row['context_json'] );
	}

	public function test_log_stores_null_job_id_as_null_not_zero(): void {
		global $wpdb;

		$logger = new Logger();
		$logger->info( 'no job id here' );

		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}cbjp_logs ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		$this->assertNull( $row['job_id'] );
	}
}
