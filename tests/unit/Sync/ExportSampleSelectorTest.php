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
}
