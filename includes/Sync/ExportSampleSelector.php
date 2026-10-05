<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Support\EntityOrigin;
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
 * `platform`はエクスポート先ASP（`cbjp_mappings`の複合キーと、下の D25 の除外に使う）。
 *
 * D25（issue #98）: 書き出し先と同じプラットフォームからの取込みで結ばれた受注・商品・顧客（`Woo\Support\EntityOrigin`）は
 * エクスポートされない（`Sync\Exporter`がスキップする）ので、サンプルの起点・明細・補充のどれからも除く。除かないと、
 * 取り込んだ受注（ASP の注文日時を保つので最新側に並ぶ）だけでサンプルが埋まって保存され、クリーンアップするまで選び直せず
 * （D16）、無料版のエクスポートが全件スキップのまま確かめられなくなる。受注の絞り込みは取得してから PHP で判定する
 * （`wc_get_orders()`の`meta_query`はレガシーの保存方式では無視されるため）。既に保存されたサンプル（`load()`）は
 * 選び直さず、そこに残る取込み品は`Exporter`のスキップに任せる。
 */
final class ExportSampleSelector {

	private const SAMPLE_ORDER_LIMIT = 10;
	private const PRODUCT_HARD_CAP   = 50;
	private const CUSTOMER_CAP       = 10;

	/**
	 * D25 の除外で Woo 生まれの実体を探すときの読み方: 新しい順に`SCAN_BATCH`件ずつ、最大`SCAN_MAX_BATCHES`回。取り込んだ受注・
	 * 商品が大量にある店舗で全件を読まないための打ち切りで、見つからない分は従来どおり（受注不足なら商品・顧客一覧から）補う。
	 */
	private const SCAN_BATCH       = 50;
	private const SCAN_MAX_BATCHES = 10;

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
		// `wc-checkout-draft`は日次cronで24時間後に完全削除される一時的な下書き注文
		// （CLAUDE.md参照）のため、サンプルの起点に含めない。
		$statuses  = array_values( array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft' ] ) );
		$order_ids = $this->collect_newest(
			static fn ( int $page ): array => wc_get_orders(
				[
					'limit'   => self::SCAN_BATCH,
					'page'    => $page,
					// 同じ日時の受注があってもページの境目で重複・欠落しないよう、ID を決め手に足す（HPOS・CPT とも空白区切りを受け付ける）。
					'orderby' => 'date ID',
					'order'   => 'DESC',
					'return'  => 'ids',
					'status'  => $statuses,
				]
			),
			static function ( int $order_id ) use ( $platform ): bool {
				$order = wc_get_order( $order_id );

				return $order instanceof WC_Order && ! EntityOrigin::order_linked_by_import( $order, $platform );
			},
			self::SAMPLE_ORDER_LIMIT
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

			// ゲスト購入（customer_id=0）は顧客枠にカウントしない（D15 §10.2 #2と同じ方針）。取込みで結ばれた顧客（D25）も除く。
			if ( 0 !== $customer_id && ! EntityOrigin::user_linked_by_import( $customer_id, $platform ) ) {
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
		$product_id_list  = array_slice( $this->filter_exportable_product_ids( array_keys( $product_ids ), $platform ), 0, self::PRODUCT_HARD_CAP );
		$customer_id_list = array_slice( array_keys( $customer_ids ), 0, self::CUSTOMER_CAP );

		// Copilot指摘（PR #40, G3）: `$used_fallback`（受注件数のみで判定）だけをトップアップの
		// トリガーにすると、受注は10件あっても明細が全て削除済み・除外対象タイプ等で
		// product_id_listが完全に空になるケースを補完できない。この空サンプルが永続化されると
		// `select_or_load()`が既存optionを返し続け二度と再選定されない（`JobManager`がこの
		// 空配列を`only_local_ids`として渡すため実行が常に空ページで完了する）。
		// ただし「受注はあるが対象商品/顧客が1〜数件しかない」（正当に小規模な実データ）まで
		// 10件へ無条件に埋めてしまうと、D15の「実際の直近受注を反映する」意図から外れる
		// （`test_free_tier_export_sample_is_restricted_to_products_in_the_latest_orders`が
		// この意図を固定化している）。トップアップは元々の`$used_fallback`（受注不足）に加え、
		// 「完全に空」の場合のみ発火させ、1件以上ある小規模サンプルはそのまま尊重する。
		if ( $used_fallback || [] === $product_id_list ) {
			$product_id_list = $this->top_up_products( $product_id_list, self::SAMPLE_ORDER_LIMIT, $platform );
		}

		if ( $used_fallback || [] === $customer_id_list ) {
			$customer_id_list = $this->top_up_customers( $customer_id_list, self::SAMPLE_ORDER_LIMIT, $platform );
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
	private function top_up_products( array $existing, int $target, string $platform ): array {
		if ( count( $existing ) >= $target ) {
			return $existing;
		}

		$ids = $this->collect_newest(
			static fn ( int $page ): array => wc_get_products(
				[
					'status'  => ProductReader::EXPORTABLE_STATUSES,
					'type'    => ProductReader::EXPORTABLE_TYPES,
					'orderby' => 'date ID',
					'order'   => 'DESC',
					'return'  => 'ids',
					'limit'   => self::SCAN_BATCH,
					'page'    => $page,
					'exclude' => $existing,
				]
			),
			static fn ( int $product_id ): bool => ! EntityOrigin::post_linked_by_import( $product_id, $platform ),
			$target - count( $existing )
		);

		return array_slice( array_values( array_unique( array_merge( $existing, $ids ) ) ), 0, $target );
	}

	/**
	 * 受注明細から抽出した商品ID一覧を`ProductReader::EXPORTABLE_STATUSES`/`EXPORTABLE_TYPES`に
	 * 絞り込む（このクラスのdocblock・`select_and_persist()`のコメント参照）。
	 *
	 * @param array<int,int> $product_ids
	 * @return array<int,int>
	 */
	private function filter_exportable_product_ids( array $product_ids, string $platform ): array {
		$product_ids = array_values( array_filter( $product_ids, static fn ( int $product_id ): bool => ! EntityOrigin::post_linked_by_import( $product_id, $platform ) ) );

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
	private function top_up_customers( array $existing, int $target, string $platform ): array {
		if ( count( $existing ) >= $target ) {
			return $existing;
		}

		$ids = $this->collect_newest(
			static fn ( int $page ): array => get_users(
				[
					'role'    => 'customer',
					'orderby' => 'registered ID',
					'order'   => 'DESC',
					'number'  => self::SCAN_BATCH,
					'paged'   => $page,
					'exclude' => $existing,
					'fields'  => 'ID',
				]
			),
			static fn ( int $user_id ): bool => ! EntityOrigin::user_linked_by_import( $user_id, $platform ),
			$target - count( $existing )
		);

		return array_slice( array_values( array_unique( array_merge( $existing, $ids ) ) ), 0, $target );
	}

	/**
	 * 新しい順に`SCAN_BATCH`件ずつ読み、`$keep`が真の ID を`$target`件まで集める（D25 の除外。最大`SCAN_MAX_BATCHES`回で打ち切る）。
	 * 同じ ID は 1 回だけ数える（ページの境目で同じ行が 2 回返っても、サンプルに重複を入れない）。
	 *
	 * @param callable(int):array<int,mixed> $fetch 1 始まりのページ番号を受け取り、そのページの ID を返す（`return => ids`。
	 *   WooCommerce の型定義は実体の配列なので、要素は正の整数として読める値だけを ID として扱う）。
	 * @param callable(int):bool             $keep  残す ID なら真。
	 * @return array<int,int>
	 */
	private function collect_newest( callable $fetch, callable $keep, int $target ): array {
		$kept = [];

		for ( $page = 1; $page <= self::SCAN_MAX_BATCHES; $page++ ) {
			$rows = $fetch( $page );

			foreach ( $rows as $row ) {
				if ( count( $kept ) >= $target ) {
					return $kept;
				}

				$id = is_int( $row ) || ( is_string( $row ) && ctype_digit( $row ) ) ? (int) $row : 0;

				if ( 0 < $id && ! in_array( $id, $kept, true ) && $keep( $id ) ) {
					$kept[] = $id;
				}
			}

			if ( count( $kept ) >= $target || count( $rows ) < self::SCAN_BATCH ) {
				return $kept;
			}
		}

		return $kept;
	}

	/**
	 * サンプルセットの保存先 option 名（import側`cbjp_sample_{platform}`とは別名）。
	 */
	public static function option_name_for( string $platform ): string {
		return 'cbjp_export_sample_' . $platform;
	}
}
