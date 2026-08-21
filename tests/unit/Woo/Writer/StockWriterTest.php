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

	public function test_managed_stock_with_positive_quantity_but_explicit_out_of_stock_flag(): void {
		// `CanonicalStock`はquantityとin_stockを独立フィールドとして持つ。ASP側が
		// 「数量は残っているが個別事情で取り扱い停止」等の理由でin_stock=falseを明示した
		// 場合、quantity>0だけでinstock扱いにすると販売してはいけない商品を売ってしまう。
		// `WC_Product::validate_props()`が在庫管理オンの間stock_statusをstock_quantityから
		// 必ず再計算するため、単純にstock_statusをoutofstockにするだけでは`save()`後に
		// instockへ戻ってしまう。数量自体を0にすることで再計算後もoutofstockが維持される
		// ことを確認する。
		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', '1', $product_id );

		$stock = new CanonicalStock( '1', null, null, 7, false );
		$this->make_writer()->write( $stock, $product_id );

		$updated = wc_get_product( $product_id );
		$this->assertTrue( $updated->get_manage_stock() );
		$this->assertSame( 0, $updated->get_stock_quantity() );
		$this->assertSame( 'outofstock', $updated->get_stock_status() );
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

	public function test_variable_parent_level_stock_is_skipped_without_touching_the_parent(): void {
		// variable商品の親が対象になった場合は実体に触れず、結果レポート上もskippedとして
		// 扱う（ASP側の在庫が何も反映されないため、updatedとして計上すると件数が実態とずれる）。
		// 親のstock_statusはWooCommerce自身が子variationから導出するため、ここでは子由来の
		// `instock`がそのまま保たれることも合わせて確認する。
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable' );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', '1', $product_id );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 1 );
		$variation->set_stock_status( 'instock' );
		$variation->save();
		WC_Product_Variable::sync( $product_id );

		$stock  = new CanonicalStock( '1', null, null, 0, false );
		$result = $this->make_writer()->write( $stock, $product_id );

		$updated = wc_get_product( $product_id );
		$this->assertSame( 'instock', $updated->get_stock_status() );
		$this->assertFalse( $updated->get_manage_stock() );

		// 対象自体は解決できているためlocal_idは親のIDを返す（STOCK_PRODUCT_UNRESOLVEDと
		// 異なり、再実行しても解消し得ない終端状態なのでmapping/checksumは記録させる）。
		$this->assertSame( $product_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		// detailはWoo内部のpost IDではなくASP側remote_id（F1-6のdry-run結果レポートが
		// remote_idで問題箇所を特定する契約のため、STOCK_PRODUCT_UNRESOLVEDと同じ規約）。
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, '1' ), $result->warnings );
	}

	public function test_variable_parent_level_stock_preserves_parent_level_stock_management(): void {
		// WooCommerceはvariable商品の親レベル在庫管理（manage_stock=true＋数量）を許容する。
		// 親へ書き込むと`StockApplier::apply()`の`set_manage_stock(false)`でその設定が失われ、
		// `WC_Product::validate_props()`が数量まで空へ落としてしまうため、店舗が設定した
		// 在庫管理を消さずに見送ることを確認する。
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 50 );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', '1', $product_id );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->save();

		$stock  = new CanonicalStock( '1', null, null, null, true );
		$result = $this->make_writer()->write( $stock, $product_id );

		$updated = wc_get_product( $product_id );
		$this->assertTrue( $updated->get_manage_stock() );
		$this->assertSame( 50, $updated->get_stock_quantity() );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, '1' ), $result->warnings );
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

	public function test_variant_ref_falls_back_to_sku_when_mapping_missing(): void {
		// product_refと同様、variant_refのmapping解決が空振り（未整備・stale）でも
		// SKUで解決できることを確認する（`wc_get_product_id_by_sku()`はvariationも引ける）。
		// `_cbjp_platform`はSKUフォールバックのownershipガード（`PlatformOwnership`）を
		// 通すために必要（`VariationWriter`が通常のsync時に付与するメタを模擬している）。
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable' );
		$product_id = $product->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_sku( 'VAR-SKU-1' );
		$variation->update_meta_data( '_cbjp_platform', 'colorme' );
		$variation_id = $variation->save();

		$stock  = new CanonicalStock( '1', 'v-unmapped', 'VAR-SKU-1', 3, true );
		$result = $this->make_writer()->write( $stock, $variation_id );

		$this->assertSame( $variation_id, $result->local_id );
		$updated = wc_get_product( $variation_id );
		$this->assertSame( 3, $updated->get_stock_quantity() );
	}

	public function test_variant_ref_sku_fallback_resolving_to_parent_is_guarded(): void {
		// variant_refのmappingが未整備/staleで、SKUフォールバックが（親商品自身にも
		// SKUが設定されているケースで）variationではなく親のvariable商品そのものに
		// 解決してしまった場合でも、variant_refの有無に関わらずvariable商品への
		// 直接書込みは弾かれ、親のvariation在庫が壊されないことを確認する。
		// `_cbjp_platform`はSKUフォールバックのownershipガードを通すために必要。
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable' );
		$product->set_sku( 'PARENT-SKU' );
		$product->update_meta_data( '_cbjp_platform', 'colorme' );
		$product_id = $product->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 1 );
		$variation->set_stock_status( 'instock' );
		$variation->save();
		WC_Product_Variable::sync( $product_id );

		$stock  = new CanonicalStock( '1', 'v-unmapped', 'PARENT-SKU', 99, true );
		$result = $this->make_writer()->write( $stock, $product_id );

		$updated = wc_get_product( $product_id );
		// 親には数量99が書かれず、子由来のstock_statusがそのまま保たれる。
		$this->assertNull( $updated->get_stock_quantity() );
		$this->assertSame( 'instock', $updated->get_stock_status() );
		$this->assertFalse( $updated->get_manage_stock() );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, 'v-unmapped' ), $result->warnings );
	}

	public function test_sku_fallback_does_not_overwrite_stock_of_product_from_another_platform(): void {
		// mapping未整備/staleでSKUフォールバックする際、SKUが偶然一致しただけの
		// 別プラットフォーム由来（または店舗が手動作成した）商品には書き込んではならない
		// （`PlatformOwnership`によるownershipガード）。
		$foreign = new WC_Product_Simple();
		$foreign->set_name( 'Foreign' );
		$foreign->set_sku( 'SHARED-SKU' );
		$foreign->set_manage_stock( true );
		$foreign->set_stock_quantity( 42 );
		$foreign->update_meta_data( '_cbjp_platform', 'makeshop' );
		$foreign_id = $foreign->save();

		$stock  = new CanonicalStock( 'unmapped', null, 'SHARED-SKU', 1, true );
		$result = $this->make_writer()->write( $stock, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_UNRESOLVED, 'unmapped' ), $result->warnings );

		$untouched = wc_get_product( $foreign_id );
		$this->assertSame( 42, $untouched->get_stock_quantity() );
	}
}
