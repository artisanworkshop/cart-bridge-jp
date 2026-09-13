<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\Exporter;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PlatformWriter;
use CartBridgeJP\Tests\Fixtures\FixedWooReader;
use CartBridgeJP\Tests\Fixtures\InMemoryPlatformWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WP_UnitTestCase;

final class ExporterTest extends WP_UnitTestCase {

	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings = new MappingRepository();
	}

	private function product( string $name = 'P' ): CanonicalProduct {
		return new CanonicalProduct( $name, 'SKU-1', '1000', null, null, [], [], [], [], 5, 'publish' );
	}

	public function test_new_item_is_pushed_as_created_and_mapping_upserted(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 'product', $writer->writes[0]['entity'] );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( '1', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	public function test_existing_mapping_with_changed_data_is_updated(): void {
		$product = $this->product( 'Updated Name' );
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, 'stale-checksum' );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $product ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 'remote-1', $writer->writes[0]['remote_id'] );
		$this->assertSame( $product->checksum(), $this->mappings->find_checksum( 'mock', 'product', 'remote-1' ) );
	}

	public function test_checksum_match_skips_the_writer(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, $product->checksum() );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $product ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
	}

	public function test_free_tier_quota_blocks_new_items_but_allows_updates(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-existing', 999, null );

		$new_item      = new ReadItem( 101, $this->product( 'New' ) );
		$existing_item = new ReadItem( 999, $this->product( 'Existing (changed)' ) );

		$reader       = new FixedWooReader( [ $new_item, $existing_item ] );
		$writer       = new InMemoryPlatformWriter();
		$exporter     = new Exporter( $this->mappings );
		$limit_policy = new LimitPolicy( $this->mappings );

		add_filter( 'cbjp/limits/product', static fn () => 1 );

		try {
			$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		// 残枠は0（上限1件 - 既存1件）のため新規（local_id=101）は作られず、既存（local_id=999）の
		// 更新のみ行われる。
		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 'remote-existing', $writer->writes[0]['remote_id'] );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 1, $result['totals']['skipped'] );
	}

	public function test_dry_run_never_persists_mappings_even_with_a_limit_policy(): void {
		$reader       = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer       = new InMemoryPlatformWriter();
		$exporter     = new Exporter( $this->mappings );
		$limit_policy = new LimitPolicy( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), true, $limit_policy );

		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
	}

	public function test_writer_exception_is_caught_and_does_not_fail_the_page(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ), new ReadItem( 102, $this->product( 'Second' ) ) ] );
		$writer   = new class() implements PlatformWriter {
			private int $next_remote_id = 1;

			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				if ( 'P' === $item->name ) {
					throw new RuntimeException( 'boom' );
				}

				return new PushResult( (string) $this->next_remote_id++, PushResult::OPERATION_CREATED );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNotNull( $this->mappings->find_remote_id( 'mock', 'product', 102 ) );
	}

	public function test_unresolved_reference_from_reader_prevents_checksum_caching(): void {
		$product  = $this->product();
		$reader   = new FixedWooReader(
			[
				new ReadItem( 101, $product, [ WarningCode::with_detail( WarningCode::CATEGORY_MAP_UNRESOLVED, '5' ) ], false ),
			]
		);
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', '1' ) );
	}

	public function test_only_local_ids_restricts_the_reader_to_the_sample(): void {
		$reader   = new FixedWooReader(
			[
				new ReadItem( 101, $this->product( 'A' ) ),
				new ReadItem( 102, $this->product( 'B' ) ),
			]
		);
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, null, [ 102 ] );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 1, $result['totals']['processed'] );
	}
}
