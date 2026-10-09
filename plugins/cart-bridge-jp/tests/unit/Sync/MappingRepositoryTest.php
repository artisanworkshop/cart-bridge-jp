<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\MappingRepository;
use WP_UnitTestCase;

final class MappingRepositoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
	}

	public function test_upsert_stores_null_checksum_as_null_not_empty_string(): void {
		global $wpdb;

		( new MappingRepository() )->upsert( 'mock', 'product', 'p1', 1, null );

		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}cbjp_mappings WHERE platform = 'mock' AND entity_type = 'product' AND remote_id = 'p1'",
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertNull( $row['checksum'] );
	}

	public function test_upsert_stores_a_non_null_checksum(): void {
		global $wpdb;

		( new MappingRepository() )->upsert( 'mock', 'product', 'p1', 1, 'abc123' );

		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}cbjp_mappings WHERE platform = 'mock' AND entity_type = 'product' AND remote_id = 'p1'",
			ARRAY_A
		);

		$this->assertSame( 'abc123', $row['checksum'] );
	}
}
