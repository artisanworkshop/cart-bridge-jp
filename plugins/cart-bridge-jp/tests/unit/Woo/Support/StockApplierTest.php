<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\StockApplier;
use WC_Product_Simple;
use WP_UnitTestCase;

final class StockApplierTest extends WP_UnitTestCase {

	public function test_managed_stock_quantity_and_status_are_applied(): void {
		$product = new WC_Product_Simple();

		StockApplier::apply( $product, 5 );

		$this->assertTrue( $product->get_manage_stock() );
		$this->assertSame( 5, $product->get_stock_quantity() );
		$this->assertSame( 'instock', $product->get_stock_status() );
	}

	public function test_unmanaged_stock_uses_in_stock_flag_only(): void {
		$product = new WC_Product_Simple();

		StockApplier::apply( $product, null, false );

		$this->assertFalse( $product->get_manage_stock() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );
	}

	public function test_negative_quantity_is_clamped_to_zero(): void {
		// `wc_stock_amount()`（`WC_Product::set_stock_quantity()`が内部で使う）は符号を検証
		// しないため、ASP側の不正データ（物理的にありえないマイナス在庫）をそのまま渡すと
		// マイナス在庫がwp-admin/REST APIにそのまま公開されてしまう。フェイルクローズで
		// 0（在庫切れ）に丸めることを確認する。
		$product = new WC_Product_Simple();

		StockApplier::apply( $product, -5 );

		$this->assertTrue( $product->get_manage_stock() );
		$this->assertSame( 0, $product->get_stock_quantity() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );
	}

	public function test_explicit_out_of_stock_zeroes_quantity_even_when_positive(): void {
		$product = new WC_Product_Simple();

		StockApplier::apply( $product, 7, false );

		$this->assertSame( 0, $product->get_stock_quantity() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );
	}
}
