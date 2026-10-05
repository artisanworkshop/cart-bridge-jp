<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Sync\ExportSampleSelector;
use WC_Product_Simple;
use WP_UnitTestCase;

final class ExportSampleSelectorTest extends WP_UnitTestCase {

	public function tear_down(): void {
		ExportSampleSelector::clear( 'mock' );
		parent::tear_down();
	}

	private function create_product( string $name, string $sku ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( '1000' );

		return $product->save();
	}

	/**
	 * Copilot指摘（PR #40、G3）: `$used_fallback`は受注件数のみで判定するため、受注が
	 * `SAMPLE_ORDER_LIMIT`件（10件）あっても明細の商品が全て削除済み等でproduct_id_listだけが
	 * 空になるケースを補完できていなかった。この空サンプルが永続化されると`select_or_load()`が
	 * 既存optionを返し続け、以後どれだけ有効な商品が増えても二度と再選定されない
	 * （`JobManager`がこの空配列を`only_local_ids`として渡すため実行が常に空ページで完了する）。
	 * 受注件数が上限に達していても、抽出された商品IDが0件ならトップアップが働くことを確認する。
	 */
	public function test_products_are_topped_up_even_when_order_count_meets_the_limit_but_yields_no_exportable_product(): void {
		$trashed_id   = $this->create_product( 'Deleted After Order', 'SKU-TRASHED' );
		$topup_target = $this->create_product( 'Available For Top-up', 'SKU-TOPUP' );

		for ( $i = 0; $i < 10; $i++ ) {
			$order = wc_create_order();
			$order->add_product( wc_get_product( $trashed_id ), 1 );
			$order->calculate_totals();
			$order->save();
		}

		// 受注作成後にリモート側で商品が削除された状態を模す（`EXPORTABLE_STATUSES`から外れる）。
		wp_trash_post( $trashed_id );

		$sample = ( new ExportSampleSelector() )->select_or_load( 'mock' );

		$this->assertContains( $topup_target, $sample->product_ids );
	}

	/**
	 * D25 用: 受注を作る。`$imported`なら取込みの writer と同じく`_cbjp_platform`を書く。日時は`$age_seconds`だけ過去にする。
	 */
	private function create_order( int $product_id, int $customer_id, bool $imported, int $age_seconds ): int {
		$order = wc_create_order( [ 'customer_id' => $customer_id ] );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_date_created( time() - $age_seconds );

		if ( $imported ) {
			$order->update_meta_data( '_cbjp_platform', 'mock' );
		}

		$order->calculate_totals();

		return $order->save();
	}

	private function create_customer( bool $imported ): int {
		$user_id = self::factory()->user->create( [ 'role' => 'customer' ] );

		if ( $imported ) {
			update_user_meta( $user_id, '_cbjp_platform', 'mock' );
		}

		return $user_id;
	}

	/**
	 * D25（issue #98）: 書き出し先と同じプラットフォームからの取込みで結ばれた受注・商品・顧客はエクスポートされないので、
	 * サンプルの起点（受注）・明細の商品・購入者・補充のどれにも入れない。取り込んだ受注が最新側に並んでいても、Woo 生まれの
	 * 受注からサンプルを作る。
	 */
	public function test_entities_linked_by_import_are_excluded_from_the_sample(): void {
		$imported_product  = $this->create_product( 'Imported', 'SKU-IMP' );
		$imported_customer = $this->create_customer( true );
		update_post_meta( $imported_product, '_cbjp_platform', 'mock' );

		$woo_product  = $this->create_product( 'Woo born', 'SKU-WOO' );
		$woo_customer = $this->create_customer( false );

		$woo_order = $this->create_order( $woo_product, $woo_customer, false, 3600 );

		// Woo 生まれの受注に取込み品の商品・取込みで結ばれた顧客（取り込んだ会員が Woo で買った）が含まれていても、その商品と顧客は
		// サンプルに入れない（受注は入れる）。
		$mixed_order = $this->create_order( $imported_product, $imported_customer, false, 1800 );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->create_order( $imported_product, $imported_customer, true, $i );
		}

		$sample = ( new ExportSampleSelector() )->select_or_load( 'mock' );

		$this->assertEqualsCanonicalizing( [ $woo_order, $mixed_order ], $sample->order_ids );
		$this->assertTrue( $sample->used_fallback );
		$this->assertContains( $woo_product, $sample->product_ids );
		$this->assertNotContains( $imported_product, $sample->product_ids, 'neither from order lines nor from the top-up' );
		$this->assertContains( $woo_customer, $sample->customer_ids );
		$this->assertNotContains( $imported_customer, $sample->customer_ids, 'neither from orders nor from the top-up' );
	}

	/**
	 * D25: 取り込んだ受注が 1 回に読む件数（50）を超えて最新側に並んでいても、次のページを読んで Woo 生まれの受注を見つける。
	 */
	public function test_woo_born_orders_behind_more_than_one_batch_of_imported_orders_are_found(): void {
		$imported_product = $this->create_product( 'Imported', 'SKU-IMP' );
		update_post_meta( $imported_product, '_cbjp_platform', 'mock' );
		$woo_product = $this->create_product( 'Woo born', 'SKU-WOO' );

		$woo_order = $this->create_order( $woo_product, 0, false, 7200 );

		for ( $i = 0; $i < 55; $i++ ) {
			$this->create_order( $imported_product, 0, true, $i );
		}

		$sample = ( new ExportSampleSelector() )->select_or_load( 'mock' );

		$this->assertSame( [ $woo_order ], $sample->order_ids );
		$this->assertContains( $woo_product, $sample->product_ids );
	}

	/**
	 * D25: 別プラットフォームから取り込んだ実体は、このプラットフォームへ移す対象なので除かない。
	 */
	public function test_entities_imported_from_another_platform_stay_in_the_sample(): void {
		$product = $this->create_product( 'From MakeShop', 'SKU-MS' );
		update_post_meta( $product, '_cbjp_platform', 'makeshop' );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->update_meta_data( '_cbjp_platform', 'makeshop' );
		$order->calculate_totals();
		$order_id = $order->save();

		$sample = ( new ExportSampleSelector() )->select_or_load( 'mock' );

		$this->assertSame( [ $order_id ], $sample->order_ids );
		$this->assertContains( $product, $sample->product_ids );
	}
}
