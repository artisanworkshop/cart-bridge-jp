<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\ProductWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;

final class ProductReaderTest extends WooTestCase {

	private const PLATFORM = 'colorme';

	private function make_reader(): ProductReader {
		return new ProductReader( self::PLATFORM, new MethodMap( self::PLATFORM ), $this->mappings );
	}

	private function make_writer(): ProductWriter {
		return new ProductWriter( self::PLATFORM, $this->mappings, new VariationWriter( self::PLATFORM, $this->mappings ), new MediaImporter( self::PLATFORM ) );
	}

	private function first_item( ProductReader $reader, ?array $only_local_ids = null ): CanonicalProduct {
		$page = $reader->query( Cursor::start(), $only_local_ids );
		$this->assertNotEmpty( $page->items );

		return $page->items[0]->item;
	}

	public function test_reads_simple_product_core_fields(): void {
		$product = new CanonicalProduct(
			'T-Shirt',
			'SKU-1',
			'3300',
			'2900',
			'desc',
			[],
			[],
			[],
			[],
			10,
			'publish',
			[ 'remote_id' => '1' ],
			true,
			[],
			500,
			'reduced-rate'
		);

		$result = $this->make_writer()->write( $product, null );

		$reader    = $this->make_reader();
		$read_page = $reader->query( Cursor::start(), [ $result->local_id ] );

		$this->assertCount( 1, $read_page->items );
		$read_item = $read_page->items[0];
		$this->assertSame( $result->local_id, $read_item->local_id );
		$this->assertTrue( $read_item->fully_resolved );
		$this->assertSame( [], $read_item->warnings );

		$canonical = $read_item->item;
		$this->assertSame( 'T-Shirt', $canonical->name );
		$this->assertSame( 'SKU-1', $canonical->sku );
		$this->assertSame( '3300', $canonical->price );
		$this->assertSame( '2900', $canonical->sale_price );
		$this->assertSame( 'desc', $canonical->description );
		$this->assertSame( 10, $canonical->stock );
		$this->assertSame( 'publish', $canonical->status );
		$this->assertSame( 500, $canonical->weight );
		$this->assertSame( 'reduced-rate', $canonical->tax_class );
		$this->assertSame( [], $canonical->variants );
	}

	public function test_unmanaged_stock_reads_as_null(): void {
		$product = new CanonicalProduct( 'No Stock Mgmt', 'SKU-9', '1000', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '9' ] );
		$result  = $this->make_writer()->write( $product, null );

		$canonical = $this->first_item( $this->make_reader(), [ $result->local_id ] );
		$this->assertNull( $canonical->stock );
	}

	public function test_category_resolved_via_category_map(): void {
		$term    = wp_insert_term( 'Shirts', 'product_cat' );
		$term_id = (int) $term['term_id'];

		update_option(
			'cbjp_settings_' . self::PLATFORM,
			[ 'category_map' => [ (string) $term_id => 'asp-cat-42' ] ]
		);

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Shirt' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_category_ids( [ $term_id ] );
		$local_id = $wc_product->save();

		$canonical = $this->first_item( $this->make_reader(), [ $local_id ] );
		$this->assertSame( [ 'asp-cat-42' ], $canonical->category_refs );
	}

	public function test_unmapped_category_warns_and_marks_unresolved(): void {
		$term    = wp_insert_term( 'Unmapped Category', 'product_cat' );
		$term_id = (int) $term['term_id'];

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Widget' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_category_ids( [ $term_id ] );
		$local_id = $wc_product->save();

		$reader    = $this->make_reader();
		$read_page = $reader->query( Cursor::start(), [ $local_id ] );
		$read_item = $read_page->items[0];

		$this->assertSame( [], $read_item->item->category_refs );
		$this->assertContains( WarningCode::with_detail( WarningCode::CATEGORY_MAP_UNRESOLVED, (string) $term_id ), $read_item->warnings );
		$this->assertFalse( $read_item->fully_resolved );
	}

	public function test_variable_product_reads_variants_with_axis_values(): void {
		$product = new CanonicalProduct(
			'Shirt',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'SKU-V1',
					'price'         => '1000',
					'stock'         => 3,
					'option1_name'  => 'Size',
					'option1_value' => 'M',
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'SKU-V2',
					'price'         => '1200',
					'stock'         => 4,
					'option1_name'  => 'Size',
					'option1_value' => 'L',
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '100' ]
		);

		$result = $this->make_writer()->write( $product, null );

		$canonical = $this->first_item( $this->make_reader(), [ $result->local_id ] );

		$this->assertCount( 2, $canonical->variants );
		$sizes = array_column( $canonical->variants, 'option1_value' );
		sort( $sizes );
		$this->assertSame( [ 'L', 'M' ], $sizes );

		foreach ( $canonical->variants as $variant ) {
			// エクスポートで既にリンク済みのvariant（`cbjp_mappings`のvariant entity）は
			// 既存remote_idを持つ（VariationWriterが作成時にupsertしたもの）。
			$this->assertNotSame( '', $variant['remote_id'] );
			$this->assertSame( 'Size', $variant['option1_name'] );
		}
	}

	public function test_new_variation_without_mapping_reports_empty_remote_id(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Unlinked Variable' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S', 'M' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '500' );
		$variation->save();

		$canonical = $this->first_item( $this->make_reader(), [ $parent_id ] );

		$this->assertCount( 1, $canonical->variants );
		$this->assertSame( '', $canonical->variants[0]['remote_id'] );
		$this->assertSame( 'S', $canonical->variants[0]['option1_value'] );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}
}
