<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\StockReader;
use CartBridgeJP\Woo\WarningCode;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

final class StockReaderTest extends WooTestCase {

	private const PLATFORM = 'colorme';

	private function make_reader(): StockReader {
		return new StockReader( self::PLATFORM, $this->mappings );
	}

	private function create_simple_product( int $stock_quantity ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product->set_sku( 'WIDGET-1' );
		$product->set_regular_price( '1000' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock_quantity );

		return $product->save();
	}

	public function test_reads_simple_product_stock_with_resolved_mapping(): void {
		$product_id = $this->create_simple_product( 7 );
		$this->seed_mapping( self::PLATFORM, 'product', 'p-100', $product_id );

		$page = $this->make_reader()->query( Cursor::start(), [ $product_id ] );
		$this->assertCount( 1, $page->items );

		$item  = $page->items[0];
		$stock = $item->item;

		$this->assertSame( $product_id, $item->local_id );
		$this->assertSame( [], $item->warnings );
		$this->assertTrue( $item->fully_resolved );
		$this->assertSame( 'p-100', $stock->product_ref );
		$this->assertNull( $stock->variant_ref );
		$this->assertSame( 'WIDGET-1', $stock->sku );
		$this->assertSame( 7, $stock->quantity );
		$this->assertTrue( $stock->in_stock );
	}

	public function test_unmanaged_stock_reads_as_null(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'No Stock Mgmt' );
		$product->set_regular_price( '1000' );
		$product_id = $product->save();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-9', $product_id );

		$page = $this->make_reader()->query( Cursor::start(), [ $product_id ] );
		$this->assertNull( $page->items[0]->item->quantity );
	}

	public function test_unresolved_product_mapping_blocks_export_with_warning(): void {
		$product_id = $this->create_simple_product( 5 );

		$page      = $this->make_reader()->query( Cursor::start(), [ $product_id ] );
		$read_item = $page->items[0];

		$this->assertFalse( $read_item->fully_resolved );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_NOT_EXPORTED, (string) $product_id ), $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	private function make_variable_product_with_variations( array $variation_specs ): array {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( array_column( $variation_specs, 'size' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation_ids = [];

		foreach ( $variation_specs as $spec ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( [ 'size' => $spec['size'] ] );
			$variation->set_regular_price( '1000' );
			$variation->set_sku( $spec['sku'] );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $spec['stock'] );

			if ( isset( $spec['status'] ) ) {
				$variation->set_status( $spec['status'] );
			}

			$variation_ids[ $spec['size'] ] = $variation->save();
		}

		return [ $parent_id, $variation_ids ];
	}

	public function test_variable_product_expands_to_one_item_per_published_variation(): void {
		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
				[
					'size'  => 'M',
					'sku'   => 'SHIRT-M',
					'stock' => 4,
				],
			]
		);

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-m', $variation_ids['M'] );

		$page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$this->assertCount( 2, $page->items );

		$by_variant_ref = [];

		foreach ( $page->items as $item ) {
			$by_variant_ref[ $item->item->variant_ref ] = $item;
		}

		$this->assertSame( 'p-parent', $by_variant_ref['v-s']->item->product_ref );
		$this->assertSame( 3, $by_variant_ref['v-s']->item->quantity );
		$this->assertSame( 4, $by_variant_ref['v-m']->item->quantity );
	}

	public function test_unpublished_variation_is_omitted_entirely(): void {
		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
				[
					'size'   => 'M',
					'sku'    => 'SHIRT-M',
					'stock'  => 4,
					'status' => 'private',
				],
			]
		);

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-m', $variation_ids['M'] );

		$page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );

		$this->assertCount( 1, $page->items );
		$this->assertSame( 'v-s', $page->items[0]->item->variant_ref );
	}

	public function test_variation_stock_shared_with_parent_fails_closed_with_warning(): void {
		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
			]
		);

		// `WC_Product_Variation::get_manage_stock('view')`（既定コンテキスト）は、バリエーション
		// 自身が在庫管理していなくても**親が在庫管理している場合のみ**`'parent'`を返す
		// （`parent_data['manage_stock']`を見る。CLAUDE.md参照）。親側の在庫管理を明示しないと
		// このテストが検証したい「共有プール」状態を再現できない。
		$parent = wc_get_product( $parent_id );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 10 );
		$parent->save();

		$variation = wc_get_product( $variation_ids['S'] );
		$variation->set_manage_stock( false );
		$variation->save();

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );

		$page      = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$read_item = $page->items[0];

		$this->assertSame( 0, $read_item->item->quantity );
		$this->assertContains( WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT, $read_item->warnings );
	}

	public function test_page_total_is_always_null(): void {
		$product_id = $this->create_simple_product( 1 );
		$this->seed_mapping( self::PLATFORM, 'product', 'p-1', $product_id );

		$page = $this->make_reader()->query( Cursor::start(), null );
		$this->assertNull( $page->total );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}
}
