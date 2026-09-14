<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Woo\Reader\ProductReader;
use WC_Order;
use WC_Order_Item_Product;

/**
 * 無料版サンプル選定ロジック（エクスポート方向。D15/§10.2 #8）。`SampleSelector`（ASP側起点）の
 * Woo向け対称形: Woo側の最新受注10件（`wc_get_orders` の日付降順）を起点に、明細の商品
 * （バリエーションは親商品で1件）と購入者（ゲスト購入は除く）を抽出する。受注が10件未満の
 * 場合は商品・顧客一覧の先頭ページ（`SampleSelector::top_up_with_first_page()`と同じ方針。
 * Woo側は日付ソートが常に可能なため無条件に新しい順とする）で残り枠を補完する。
 *
 * 永続化キーは `cbjp_export_sample_{platform}`（import用 `cbjp_sample_{platform}` とは別）。
 * `platform`はエクスポート先ASP（`cbjp_mappings`の複合キーに使うだけでWoo側の抽出には
 * 関与しない）。
 */
final class ExportSampleSelector {

	private const SAMPLE_ORDER_LIMIT = 10;
	private const PRODUCT_HARD_CAP   = 50;
	private const CUSTOMER_CAP       = 10;

	public function select_or_load( string $platform ): ExportSampleSet {
		return $this->load( $platform ) ?? $this->select_and_persist( $platform );
	}

	public function load( string $platform ): ?ExportSampleSet {
		$stored = get_option( self::option_name_for( $platform ) );

		return is_array( $stored ) ? ExportSampleSet::from_array( $stored ) : null;
	}

	/**
	 * サンプルセットを破棄する（`Woo\Tools\SampleCleanup`のエクスポート版クリーンアップから
	 * アダプタを介さずに呼べるよう static。`SampleSelector::clear()`と同じ役割）。
	 */
	public static function clear( string $platform ): void {
		delete_option( self::option_name_for( $platform ) );
	}

	private function select_and_persist( string $platform ): ExportSampleSet {
		$order_ids = wc_get_orders(
			[
				'limit'   => self::SAMPLE_ORDER_LIMIT,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
				// `wc-checkout-draft`は日次cronで24時間後に完全削除される一時的な下書き注文
				// （CLAUDE.md参照）のため、サンプルの起点に含めない。
				'status'  => array_values( array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft' ] ) ),
			]
		);

		$product_ids  = [];
		$customer_ids = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				// バリエーションは親商品で1件（`get_product_id()`は常に親product_id。
				// バリエーション固有IDは`get_variation_id()`）。
				$product_id = $item->get_product_id();

				if ( 0 !== $product_id ) {
					$product_ids[ $product_id ] = true;
				}
			}

			$customer_id = $order->get_customer_id();

			// ゲスト購入（customer_id=0）は顧客枠にカウントしない（D15 §10.2 #2と同じ方針）。
			if ( 0 !== $customer_id ) {
				$customer_ids[ $customer_id ] = true;
			}
		}

		$used_fallback = count( $order_ids ) < self::SAMPLE_ORDER_LIMIT;

		// 受注明細由来の商品IDは、ゴミ箱・下書き以外の許容ステータス・タイプ（`ProductReader`が
		// 実際にエクスポート対象とするもの）に絞り込む。絞り込まずにサンプル枠を消費すると、
		// `ProductReader::query()`のページには現れない商品がサンプルの50件枠だけを占有し、
		// 「サンプル選定は完了しているのに実際にエクスポートされる商品が枠より少ない」という
		// 説明できない挙動になる（D16「クリーンアップせずに再選定は不可」のため一度枠を
		// 消費すると取り返しがつかない）。
		$product_id_list  = array_slice( $this->filter_exportable_product_ids( array_keys( $product_ids ) ), 0, self::PRODUCT_HARD_CAP );
		$customer_id_list = array_slice( array_keys( $customer_ids ), 0, self::CUSTOMER_CAP );

		if ( $used_fallback ) {
			$product_id_list  = $this->top_up_products( $product_id_list, self::SAMPLE_ORDER_LIMIT );
			$customer_id_list = $this->top_up_customers( $customer_id_list, self::SAMPLE_ORDER_LIMIT );
		}

		$sample = new ExportSampleSet( array_values( $order_ids ), $product_id_list, $customer_id_list, $used_fallback );

		// `SampleSelector::select_and_persist()`と同じ理由: 全て空の場合は永続化せず、
		// データが入り次第次回実行で再選定できるようにする。
		if ( [] === $sample->order_ids && [] === $sample->product_ids && [] === $sample->customer_ids ) {
			return $sample;
		}

		update_option( self::option_name_for( $platform ), $sample->to_array(), false );

		return $sample;
	}

	/**
	 * @param array<int,int> $existing
	 * @return array<int,int>
	 */
	private function top_up_products( array $existing, int $target ): array {
		if ( count( $existing ) >= $target ) {
			return $existing;
		}

		$ids = wc_get_products(
			[
				'status'  => ProductReader::EXPORTABLE_STATUSES,
				'type'    => ProductReader::EXPORTABLE_TYPES,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
				'limit'   => $target,
				'exclude' => $existing,
			]
		);

		return array_slice( array_values( array_unique( array_merge( $existing, array_map( 'intval', $ids ) ) ) ), 0, $target );
	}

	/**
	 * 受注明細から抽出した商品ID一覧を`ProductReader::EXPORTABLE_STATUSES`/`EXPORTABLE_TYPES`に
	 * 絞り込む（このクラスのdocblock・`select_and_persist()`のコメント参照）。
	 *
	 * @param array<int,int> $product_ids
	 * @return array<int,int>
	 */
	private function filter_exportable_product_ids( array $product_ids ): array {
		if ( [] === $product_ids ) {
			return [];
		}

		$ids = wc_get_products(
			[
				'include' => $product_ids,
				'status'  => ProductReader::EXPORTABLE_STATUSES,
				'type'    => ProductReader::EXPORTABLE_TYPES,
				'limit'   => count( $product_ids ),
				'return'  => 'ids',
			]
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @param array<int,int> $existing
	 * @return array<int,int>
	 */
	private function top_up_customers( array $existing, int $target ): array {
		if ( count( $existing ) >= $target ) {
			return $existing;
		}

		$ids = get_users(
			[
				'role'    => 'customer',
				'orderby' => 'registered',
				'order'   => 'DESC',
				'number'  => $target,
				'exclude' => $existing,
				'fields'  => 'ID',
			]
		);

		return array_slice( array_values( array_unique( array_merge( $existing, array_map( 'intval', $ids ) ) ) ), 0, $target );
	}

	/**
	 * サンプルセットの保存先 option 名（import側`cbjp_sample_{platform}`とは別名）。
	 */
	public static function option_name_for( string $platform ): string {
		return 'cbjp_export_sample_' . $platform;
	}
}
