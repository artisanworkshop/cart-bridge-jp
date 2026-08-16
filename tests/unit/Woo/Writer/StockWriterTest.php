<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\StockWriter;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

final class StockWriterTest extends WooTestCase {

	private function make_writer(): StockWriter {
		return new StockWriter( new ProductResolver( 'colorme', $this->mappings ) );
	}

	public function test_unresolved_product_returns_skipped_with_zero_local_id(): void {
		$stock  = new CanonicalStock( 'missing', null, null, 5, true );
		$result = $this->make_writer()->write( $stock, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_UNRESOLVED, 'missing' ), $result->warnings );
	}

	public function test_updates_managed_stock_for_resolved_product(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', '1', $product_id );

		$stock  = new CanonicalStock( '1', null, null, 7, true );
		$result = $this->make_writer()->write( $stock, $product_id );

		$this->assertSame( $product_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );

		$updated = wc_get_product( $product_id );
		$this->assertTrue( $updated->get_manage_stock() );
		$this->assertSame( 7, $updated->get_stock_quantity() );
		$this->assertSame( 'instock', $updated->get_stock_status() );
	}

	public function test_unmanaged_stock_sets_status_only(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', '1', $product_id );

		$stock = new CanonicalStock( '1', null, null, null, false );
		$this->make_writer()->write( $stock, $product_id );

		$updated = wc_get_product( $product_id );
		$this->assertFalse( $updated->get_manage_stock() );
		$this->assertSame( 'outofstock', $updated->get_stock_status() );
	}

	public function test_variable_parent_level_stock_warns_and_only_updates_status(): void {
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable' );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', '1', $product_id );

		// variable商品は在庫を持つ子variationが無いとWooCommerce自身がstock_statusを
		// outofstockへ強制再計算するため、実運用の前提（先に商品ジョブでvariationが
		// 作成済み）に合わせて在庫ありのvariationを1件用意する。
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 1 );
		$variation->set_stock_status( 'instock' );
		$variation->save();
		WC_Product_Variable::sync( $product_id );

		$stock  = new CanonicalStock( '1', null, null, 5, true );
		$result = $this->make_writer()->write( $stock, $product_id );

		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, (string) $product_id ), $result->warnings );

		$updated = wc_get_product( $product_id );
		$this->assertFalse( $updated->get_manage_stock() );
		$this->assertSame( 'instock', $updated->get_stock_status() );
	}

	public function test_variant_ref_resolves_to_variation(): void {
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable' );
		$product_id = $product->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation_id = $variation->save();
		$this->seed_mapping( 'colorme', 'variant', 'v1', $variation_id );

		$stock  = new CanonicalStock( '1', 'v1', null, 3, true );
		$result = $this->make_writer()->write( $stock, $variation_id );

		$this->assertSame( $variation_id, $result->local_id );
		$updated = wc_get_product( $variation_id );
		$this->assertSame( 3, $updated->get_stock_quantity() );
	}
}
