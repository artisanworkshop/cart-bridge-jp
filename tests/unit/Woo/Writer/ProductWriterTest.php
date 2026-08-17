<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\ProductWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;
use WC_Product_Variable;

final class ProductWriterTest extends WooTestCase {

	private function make_writer(): ProductWriter {
		return new ProductWriter( 'colorme', $this->mappings, new VariationWriter( 'colorme', $this->mappings ), new MediaImporter() );
	}

	public function test_creates_simple_product_with_core_fields(): void {
		$product = new CanonicalProduct(
			'T-Shirt',
			'SKU-1',
			'3300',
			null,
			'desc',
			[],
			[],
			[],
			[],
			10,
			'publish',
			[
				'remote_id' => '1',
				'sort'      => 5,
				'unlisted'  => true,
			],
			true,
			[],
			500,
			'reduced-rate'
		);

		$result = $this->make_writer()->write( $product, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertSame( 'T-Shirt', $wc_product->get_name() );
		$this->assertSame( 'SKU-1', $wc_product->get_sku() );
		$this->assertSame( '3300', $wc_product->get_regular_price() );
		$this->assertSame( 'publish', $wc_product->get_status() );
		$this->assertSame( 'hidden', $wc_product->get_catalog_visibility() );
		$this->assertSame( 5, $wc_product->get_menu_order() );
		$this->assertSame( 10, $wc_product->get_stock_quantity() );
		$this->assertSame( 'reduced-rate', $wc_product->get_tax_class() );
		$this->assertSame( '1', get_post_meta( $result->local_id, '_cbjp_remote_id', true ) );
		$this->assertSame( 'colorme', get_post_meta( $result->local_id, '_cbjp_platform', true ) );
	}

	public function test_unknown_tax_class_falls_back_and_warns(): void {
		$product = new CanonicalProduct( 'P', 'SKU-2', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '2' ], true, [], null, 'nonexistent-class' );

		$result = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertSame( '', $wc_product->get_tax_class() );
		$this->assertContains( WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, 'nonexistent-class' ), $result->warnings );
	}

	public function test_weight_is_converted_from_grams_to_store_unit(): void {
		update_option( 'woocommerce_weight_unit', 'kg' );

		$product = new CanonicalProduct( 'P', 'SKU-3', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '3' ], true, [], 1500 );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '1.5', $wc_product->get_weight() );
	}

	public function test_sku_conflict_falls_back_to_empty_sku_and_warns(): void {
		$existing = new CanonicalProduct( 'Existing', 'DUP-SKU', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => 'existing' ] );
		$this->make_writer()->write( $existing, null );

		$product = new CanonicalProduct( 'New', 'DUP-SKU', '200', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => 'new' ] );
		$result  = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertSame( '', $wc_product->get_sku() );
		$this->assertSame( 'DUP-SKU', get_post_meta( $result->local_id, '_cbjp_original_sku', true ) );
		$this->assertContains( WarningCode::with_detail( WarningCode::SKU_DUPLICATE, 'DUP-SKU' ), $result->warnings );
	}

	public function test_resolves_categories_and_tags_via_mapping(): void {
		$category_term_id = wp_insert_term( 'Cat', 'product_cat' )['term_id'];
		$tag_term_id      = wp_insert_term( 'Tag', 'product_tag' )['term_id'];
		$this->seed_mapping( 'colorme', 'category', '10', $category_term_id );
		$this->seed_mapping( 'colorme', 'tag', '20', $tag_term_id );

		$product = new CanonicalProduct( 'P', 'SKU-4', '100', null, null, [], [], [], [ '10' ], null, 'publish', [ 'remote_id' => '4' ], true, [ '20' ] );
		$result  = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertContains( $category_term_id, $wc_product->get_category_ids() );
		$this->assertContains( $tag_term_id, $wc_product->get_tag_ids() );
	}

	public function test_unresolved_category_ref_warns(): void {
		$product = new CanonicalProduct( 'P', 'SKU-5', '100', null, null, [], [], [], [ '999' ], null, 'publish', [ 'remote_id' => '5' ] );
		$result  = $this->make_writer()->write( $product, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::CATEGORY_REF_UNRESOLVED, '999' ), $result->warnings );
	}

	public function test_re_run_updates_existing_product_instead_of_duplicating(): void {
		$product = new CanonicalProduct( 'P', 'SKU-6', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '6' ] );
		$first   = $this->make_writer()->write( $product, null );

		$updated = new CanonicalProduct( 'P Updated', 'SKU-6', '200', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '6' ] );
		$second  = $this->make_writer()->write( $updated, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$this->assertSame( WriteResult::OPERATION_UPDATED, $second->operation );

		$wc_product = wc_get_product( $first->local_id );
		$this->assertSame( 'P Updated', $wc_product->get_name() );
		$this->assertSame( '200', $wc_product->get_regular_price() );
	}

	public function test_variable_product_created_with_variations(): void {
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
					'sku'           => 'SHIRT-S',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '2000',
					'stock'         => 5,
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'SHIRT-M',
					'option1_name'  => 'Size',
					'option1_value' => 'M',
					'price'         => '2000',
					'stock'         => 3,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '7' ]
		);

		$result = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertInstanceOf( WC_Product_Variable::class, $wc_product );
		$this->assertCount( 2, $wc_product->get_children() );

		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		$this->assertNotNull( $variation_id );

		$variation = wc_get_product( $variation_id );
		$this->assertSame( 'SHIRT-S', $variation->get_sku() );
		$this->assertSame( '2000', $variation->get_regular_price() );
		$this->assertSame( 5, $variation->get_stock_quantity() );
	}

	public function test_simple_to_variable_type_change_preserves_id(): void {
		$simple = new CanonicalProduct( 'P', 'SKU-8', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '8' ] );
		$first  = $this->make_writer()->write( $simple, null );

		$variable = new CanonicalProduct(
			'P',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'P-A',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'price'         => '100',
					'stock'         => 1,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '8' ]
		);

		$second = $this->make_writer()->write( $variable, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$this->assertInstanceOf( WC_Product_Variable::class, wc_get_product( $second->local_id ) );
	}

	public function test_variable_to_simple_type_change_removes_orphaned_variations(): void {
		$variable     = new CanonicalProduct(
			'P',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'P-A',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'price'         => '100',
					'stock'         => 1,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '9' ]
		);
		$first        = $this->make_writer()->write( $variable, null );
		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		$this->assertNotNull( $variation_id );

		$simple = new CanonicalProduct( 'P', 'SKU-9', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '9' ] );
		$second = $this->make_writer()->write( $simple, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$product = wc_get_product( $second->local_id );
		$this->assertNotInstanceOf( WC_Product_Variable::class, $product );

		// 旧variationは削除され、mappingも掃除されている。
		// `wc_get_product()`は削除済みvariationに対しても空のオブジェクトを返すことがある
		// （`WC_Product_Variation_Data_Store_CPT::read()`は投稿が無くても例外を投げず
		// 早期リターンするだけのため）、投稿自体の存在有無で判定する。
		$this->assertNull( get_post( $variation_id ) );
		$this->assertNull( $this->mappings->find_local_id( 'colorme', 'variant', 'v1' ) );
	}

	public function test_stale_existing_local_id_falls_back_to_create(): void {
		// mappingsが指す商品IDが手動削除等で既に存在しない場合を模擬する
		// （実在しない商品IDを直接existing_local_idとして渡す）。
		$product = new CanonicalProduct( 'P', 'SKU-10', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '10' ] );

		$result = $this->make_writer()->write( $product, 999999 );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( 999999, $result->local_id );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertInstanceOf( \WC_Product::class, $wc_product );
		$this->assertSame( 'P', $wc_product->get_name() );
	}
}
