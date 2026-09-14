<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\WarningCode;
use WC_DateTime;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

/**
 * `WC_Order` を `CanonicalOrder` へ変換する（`Woo\Writer\OrderWriter` の読出側対称形）。
 *
 * 決済/配送方法・受注ステータスはWooの生コード（`get_payment_method()`/`get_status()`等）を
 * そのまま`payment`/`shipping`/`status`へ載せ、ASP側コードへの変換はE2-3の`push_order()`
 * （ColorMeアダプタ）へ委ねる（D19の申し送り。`docs/03-design-decisions.md` §10.2）。
 * 配送先・請求先住所も同じ理由でWooネイティブのキーのまま運ぶ（`Woo\Reader\CustomerReader`と
 * 同じ判断）。一方、明細の商品参照（`remote_product_id`）と購入者（`customer_ref`）は
 * `cbjp_mappings`によるプラットフォーム非依存の解決が可能なため、ここで解決する
 * （`Woo\Reader\ProductReader::variants()`が既にvariantのremote_id解決に使っている仕組みと同じ）。
 * ページ内の全注文をスキャンしてから`MappingRepository::find_many_by_local_ids()`で一括解決する
 * （アイテム毎のSELECTを避けるため。`ProductReader::variants()`と同じ理由）。
 */
final class OrderReader implements EntityReader {

	private const PAGE_SIZE = 20;

	/**
	 * @var array<int,array{remote_id:string,checksum:?string}>
	 */
	private array $product_refs = [];

	/**
	 * @var array<int,array{remote_id:string,checksum:?string}>
	 */
	private array $variant_refs = [];

	/**
	 * @var array<int,array{remote_id:string,checksum:?string}>
	 */
	private array $customer_refs = [];

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings
	) {}

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$args = [
			'status'   => $this->exportable_statuses(),
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'objects',
			'paginate' => true,
			'limit'    => self::PAGE_SIZE,
			'page'     => (int) $cursor->get( 'page', 1 ),
		];

		if ( null !== $only_local_ids ) {
			if ( [] === $only_local_ids ) {
				return new ReadPage( [], null, 0 );
			}

			$args['post__in'] = $only_local_ids;
			unset( $args['page'] );
			$args['limit'] = count( $only_local_ids );
		}

		/** @var object{orders:array<int,WC_Order>,total:int,max_num_pages:int} $result */
		$result = wc_get_orders( $args );

		$this->preload_mappings( $result->orders );

		$items = array_values( array_map( fn ( WC_Order $order ): ReadItem => $this->to_read_item( $order ), $result->orders ) );

		$page          = (int) ( $args['page'] ?? 1 );
		$has_next_page = null === $only_local_ids && $page < $result->max_num_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, $result->total );
	}

	/**
	 * ページ内の全注文をスキャンし、商品/バリエーション/顧客のremote_idを1回のクエリずつで
	 * 一括解決する（`ProductReader::variants()`の`find_many_by_local_ids()`一括先読みと同じ理由）。
	 *
	 * @param array<int,WC_Order> $orders
	 */
	private function preload_mappings( array $orders ): void {
		$product_ids   = [];
		$variation_ids = [];
		$customer_ids  = [];

		foreach ( $orders as $order ) {
			$customer_id = $order->get_customer_id();

			if ( 0 !== $customer_id ) {
				$customer_ids[ $customer_id ] = true;
			}

			foreach ( $order->get_items() as $order_item ) {
				if ( ! $order_item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$variation_id = $order_item->get_variation_id();

				if ( 0 !== $variation_id ) {
					$variation_ids[ $variation_id ] = true;
					continue;
				}

				$product_id = $order_item->get_product_id();

				if ( 0 !== $product_id ) {
					$product_ids[ $product_id ] = true;
				}
			}
		}

		$this->product_refs  = $this->mappings->find_many_by_local_ids( $this->platform, 'product', array_keys( $product_ids ) );
		$this->variant_refs  = $this->mappings->find_many_by_local_ids( $this->platform, 'variant', array_keys( $variation_ids ) );
		$this->customer_refs = $this->mappings->find_many_by_local_ids( $this->platform, 'customer', array_keys( $customer_ids ) );
	}

	/**
	 * `wc-checkout-draft`（WooCommerce Blocksのチェックアウト下書き。24時間後に日次cronで完全削除
	 * される）を除く全ステータス（`Sync\ExportSampleSelector::select_and_persist()`と同じ理由・
	 * 同じ式。CLAUDE.md参照）。
	 *
	 * @return array<int,string>
	 */
	private function exportable_statuses(): array {
		return array_values( array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft' ] ) );
	}

	private function to_read_item( WC_Order $order ): ReadItem {
		$warnings = [];

		[ $line_items, $line_item_warnings ] = $this->line_items( $order );
		$warnings                            = array_merge( $warnings, $line_item_warnings );

		[ $customer_ref, $customer_warning ] = $this->customer_ref( $order );

		if ( null !== $customer_warning ) {
			$warnings[] = $customer_warning;
		}

		[ $totals, $totals_warning ] = $this->totals( $order );

		if ( null !== $totals_warning ) {
			$warnings[] = $totals_warning;
		}

		$canonical = new CanonicalOrder(
			$order->get_order_number(),
			$order->get_status(),
			$customer_ref,
			$line_items,
			$this->shipping( $order ),
			$this->payment( $order ),
			$totals,
			$order->get_date_created() instanceof WC_DateTime ? $order->get_date_created()->date( DATE_ATOM ) : '',
			$this->meta_string( $order, '_cbjp_memo' ),
			$this->extras( $order )
		);

		return new ReadItem( $order->get_id(), $canonical, $warnings, ! WarningCode::indicates_unresolved_reference( $warnings ) );
	}

	/**
	 * `Woo\Writer\OrderWriter::apply_addresses()`は請求先住所を`extras['customer_snapshot']`から、
	 * `apply_dates()`は支払済みかを`extras['paid']`から復元する。空の`extras`のままだと
	 * エクスポート→再取込の往復で請求先住所・支払日が毎回失われるため、Wooの現在の請求先住所
	 * （クラスdocblock参照。Wooネイティブのキーのまま運ぶ）と支払済み状態をここへ積む。
	 *
	 * @return array<string,mixed>
	 */
	private function extras( WC_Order $order ): array {
		return [
			'customer_snapshot' => $this->customer_snapshot( $order ),
			'paid'              => null !== $order->get_date_paid(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function customer_snapshot( WC_Order $order ): array {
		$address = $order->get_address( 'billing' );

		return [
			'name'      => trim( (string) ( $address['first_name'] ?? '' ) . ' ' . (string) ( $address['last_name'] ?? '' ) ),
			'email'     => '' !== $order->get_billing_email() ? $order->get_billing_email() : null,
			'phone'     => '' !== $order->get_billing_phone() ? $order->get_billing_phone() : null,
			'company'   => '' !== ( $address['company'] ?? '' ) ? $address['company'] : null,
			'address_1' => '' !== ( $address['address_1'] ?? '' ) ? $address['address_1'] : null,
			'address_2' => '' !== ( $address['address_2'] ?? '' ) ? $address['address_2'] : null,
			'city'      => '' !== ( $address['city'] ?? '' ) ? $address['city'] : null,
			'state'     => '' !== ( $address['state'] ?? '' ) ? $address['state'] : null,
			'postcode'  => '' !== ( $address['postcode'] ?? '' ) ? $address['postcode'] : null,
			'country'   => '' !== ( $address['country'] ?? '' ) ? $address['country'] : null,
		];
	}

	/**
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
	 */
	private function line_items( WC_Order $order ): array {
		$items    = [];
		$warnings = [];

		foreach ( $order->get_items() as $order_item ) {
			if ( ! $order_item instanceof WC_Order_Item_Product ) {
				continue;
			}

			[ $remote_product_id, $line_warning ] = $this->remote_product_id( $order_item );

			if ( null !== $line_warning ) {
				$warnings[] = $line_warning;
			}

			$quantity = $order_item->get_quantity();

			[ $subtotal_excl, $subtotal_tax, $amount_warning ] = $this->line_item_amounts( $order_item, $remote_product_id );

			if ( null !== $amount_warning ) {
				$warnings[] = $amount_warning;
			}

			$unit_price_excl_tax = $quantity > 0 ? wc_format_decimal( $subtotal_excl / $quantity ) : '0';
			$line_total_incl     = wc_format_decimal( $subtotal_excl + $subtotal_tax );
			$unit_price_incl     = $quantity > 0 ? wc_format_decimal( ( $subtotal_excl + $subtotal_tax ) / $quantity ) : '0';

			$items[] = [
				'sku'                 => $this->line_item_sku( $order_item ),
				'remote_product_id'   => $remote_product_id,
				'name'                => $order_item->get_name(),
				'quantity'            => $quantity,
				'price'               => $unit_price_incl,
				'subtotal'            => $line_total_incl,
				'unit_price_excl_tax' => $unit_price_excl_tax,
				'tax_reduced'         => 'reduced-rate' === $order_item->get_tax_class(),
			];
		}

		return [ $items, $warnings ];
	}

	/**
	 * 明細の税抜金額は`get_subtotal()`/`get_subtotal_tax()`（**`get_total()`/`get_total_tax()`
	 * ではない**）を使う。Wooの`total`はWoo自身のクーポン計算（`WC_Order::calculate_totals()`）で
	 * 割引後の金額になるが、`totals['discount']`（`get_discount_total()`）は別フィールドとして
	 * 独立に運ぶ契約（`Woo\Writer\OrderWriter::apply_totals()`が`discount_total`を明細とは無関係な
	 * 注文レベルの控除として`set_discount_total()`する。ColorMeのポイント/GMOポイント値引きは
	 * 明細価格を一切変えずorder-level discountとしてのみ表現される、というimport方向の契約と対称）。
	 * `get_total()`（割引後）を明細価格として使うと、割引が「明細側で織り込み済み」と
	 * 「`totals.discount`で別途控除」の二重に効いてしまい、再取込・E2-3のpush_order()いずれでも
	 * 実際より安い金額として扱われる（Wooネイティブのクーポンを使った注文で顕在化）。
	 *
	 * `_line_subtotal`/`_line_subtotal_tax`postmeta由来のため、`WC_Order_Item_Product::
	 * set_subtotal()`/`set_taxes()`自身は符号・数値妥当性を検証しない（他プラグイン・直接の
	 * メタ編集で壊れうる。`Woo\Reader\ProductReader`の`_regular_price`メタと同じ構造。CLAUDE.md
	 * 参照）。負の明細金額をそのまま次工程へ渡すと実質的な値引き・不正な金額として扱われうる
	 * ため、`Woo\Writer\OrderItemBuilder::split_line_amount()`と同じ基準（数値・0以上）で
	 * フェイルクローズする。
	 *
	 * @return array{0:float,1:float,2:?string}
	 */
	private function line_item_amounts( WC_Order_Item_Product $order_item, ?string $remote_product_id ): array {
		$raw_subtotal     = $order_item->get_subtotal();
		$raw_subtotal_tax = $order_item->get_subtotal_tax();

		if ( ! is_numeric( $raw_subtotal ) || (float) $raw_subtotal < 0.0 || ! is_numeric( $raw_subtotal_tax ) || (float) $raw_subtotal_tax < 0.0 ) {
			return [ 0.0, 0.0, WarningCode::with_detail( WarningCode::ORDER_LINE_AMOUNT_INVALID, $remote_product_id ?? '' ) ];
		}

		return [ (float) $raw_subtotal, (float) $raw_subtotal_tax, null ];
	}

	private function line_item_sku( WC_Order_Item_Product $order_item ): ?string {
		$product = $order_item->get_product();

		if ( false === $product ) {
			return null;
		}

		return '' !== $product->get_sku() ? $product->get_sku() : null;
	}

	/**
	 * `WC_Order_Item_Product::get_product_id()`はバリエーション明細でも常に親商品IDを返すため、
	 * `get_variation_id()`（非0ならバリエーション）を優先してmappingsの`variant`entityで解決する
	 * （`Woo\Reader\ProductReader::variants()`と同じ規約）。`preload_mappings()`が一括先読みした
	 * `$this->variant_refs`/`$this->product_refs`から引く（アイテム毎のSELECTを避けるため）。
	 *
	 * `ORDER_LINE_PRODUCT_NOT_EXPORTED`（再試行可能＝checksumをキャッシュしない）は
	 * 「商品/バリエーションが実在するがまだエクスポートされていない」場合のみ積む。参照先が
	 * 削除済み（`get_product()`が`false`）の場合は再エクスポートを待っても解決しない終端状態
	 * のため警告を積まない（`indicates_unresolved_reference()`のdocblockが定める
	 * 「解決される見込みが無い終端状態は含めない」方針と同じ）。
	 *
	 * @return array{0:?string,1:?string}
	 */
	private function remote_product_id( WC_Order_Item_Product $order_item ): array {
		$variation_id = $order_item->get_variation_id();
		$product_id   = $order_item->get_product_id();
		$exists       = false !== $order_item->get_product();

		if ( 0 !== $variation_id ) {
			$remote_id = $this->variant_refs[ $variation_id ]['remote_id'] ?? null;

			if ( null !== $remote_id ) {
				return [ $remote_id, null ];
			}

			return [ null, $exists ? WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED, (string) $variation_id ) : null ];
		}

		if ( 0 !== $product_id ) {
			$remote_id = $this->product_refs[ $product_id ]['remote_id'] ?? null;

			if ( null !== $remote_id ) {
				return [ $remote_id, null ];
			}

			return [ null, $exists ? WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED, (string) $product_id ) : null ];
		}

		// 商品リンクを持たないカスタム行（削除済み商品の明細等）。解決対象が無いため警告も出さない。
		return [ null, null ];
	}

	/**
	 * `remote_product_id()`と同じ理由: 購入者アカウントが削除済み（`get_userdata()`が`false`）の
	 * 場合は再エクスポートを待っても解決しない終端状態のため警告を積まない。
	 *
	 * @return array{0:?string,1:?string}
	 */
	private function customer_ref( WC_Order $order ): array {
		$customer_id = $order->get_customer_id();

		if ( 0 === $customer_id ) {
			return [ null, null ];
		}

		$remote_id = $this->customer_refs[ $customer_id ]['remote_id'] ?? null;

		if ( null !== $remote_id ) {
			return [ $remote_id, null ];
		}

		if ( false === get_userdata( $customer_id ) ) {
			return [ null, null ];
		}

		return [ null, WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_NOT_EXPORTED, (string) $customer_id ) ];
	}

	/**
	 * Wooネイティブの配送先住所キー＋送料＋Woo生の配送方法ID（クラスdocblock参照）＋
	 * `Woo\Writer\OrderWriter::apply_meta()`が書く`_cbjp_*`配送関連メタの読み戻し。
	 * 複数の配送明細を持つ注文（このプラグイン外で作成された注文等）は先頭の1件を代表として使い、
	 * 送料は全明細の合計にする。
	 *
	 * @return array<string,mixed>
	 */
	private function shipping( WC_Order $order ): array {
		$shipping_items = array_values(
			array_filter(
				$order->get_items( 'shipping' ),
				static fn ( $item ): bool => $item instanceof WC_Order_Item_Shipping
			)
		);

		$fee_total = 0.0;

		foreach ( $shipping_items as $shipping_item ) {
			$fee_total += (float) $shipping_item->get_total();
		}

		/** @var ?WC_Order_Item_Shipping $primary */
		$primary     = $shipping_items[0] ?? null;
		$method_id   = null;
		$method_name = null;

		if ( null !== $primary ) {
			$bare_method_id = $primary->get_method_id();
			$instance_id    = $primary->get_instance_id();
			$method_id      = '' !== $instance_id && '0' !== $instance_id ? "{$bare_method_id}:{$instance_id}" : $bare_method_id;
			$method_name    = $primary->get_method_title();
		}

		$address = $order->get_address( 'shipping' );

		return array_merge(
			[
				'method_id'   => $method_id,
				'method_name' => $method_name,
				'fee'         => wc_format_decimal( $fee_total ),
				'name'        => trim( (string) ( $address['first_name'] ?? '' ) . ' ' . (string) ( $address['last_name'] ?? '' ) ),
				'tel'         => '' !== $order->get_shipping_phone() ? $order->get_shipping_phone() : null,
				'company'     => '' !== ( $address['company'] ?? '' ) ? $address['company'] : null,
				'address_1'   => '' !== ( $address['address_1'] ?? '' ) ? $address['address_1'] : null,
				'address_2'   => '' !== ( $address['address_2'] ?? '' ) ? $address['address_2'] : null,
				'city'        => '' !== ( $address['city'] ?? '' ) ? $address['city'] : null,
				'state'       => '' !== ( $address['state'] ?? '' ) ? $address['state'] : null,
				'postcode'    => '' !== ( $address['postcode'] ?? '' ) ? $address['postcode'] : null,
				'country'     => '' !== ( $address['country'] ?? '' ) ? $address['country'] : null,
			],
			$this->shipping_meta( $order )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function shipping_meta( WC_Order $order ): array {
		return [
			'slip_number'      => $this->meta_string( $order, '_cbjp_slip_number' ),
			'tracking_url'     => $this->meta_string( $order, '_cbjp_tracking_url' ),
			'preferred_date'   => $this->meta_string( $order, '_cbjp_preferred_date' ),
			'preferred_period' => $this->meta_string( $order, '_cbjp_preferred_period' ),
			'noshi_text'       => $this->meta_string( $order, '_cbjp_noshi_text' ),
			'card_name'        => $this->meta_string( $order, '_cbjp_card_name' ),
			'card_text'        => $this->meta_string( $order, '_cbjp_card_text' ),
			'wrapping_name'    => $this->meta_string( $order, '_cbjp_wrapping_name' ),
		];
	}

	/**
	 * `fee`はWooの決済手数料・ギフト包装料等（`WC_Order_Item_Fee`）を区別せず合算した値。
	 * `Woo\Writer\OrderItemBuilder::build_fee_items()`は決済手数料（`payment.fee`）とギフト
	 * 包装料（`totals.gift_charges`）を別名で個別のFee行に組み立てるが、Woo側の汎用Fee明細は
	 * 種別を安定に見分ける手段が無いため、合算して`payment.fee`側にのみ載せる（合計金額の
	 * 整合を優先する簡略化。再取込では1本のFee行に統合される）。
	 *
	 * @return array<string,mixed>
	 */
	private function payment( WC_Order $order ): array {
		return [
			'method_id'   => '' !== $order->get_payment_method() ? $order->get_payment_method() : null,
			'method_name' => '' !== $order->get_payment_method_title() ? $order->get_payment_method_title() : null,
			'fee'         => wc_format_decimal( $this->fee_total( $order ) ),
		];
	}

	private function fee_total( WC_Order $order ): float {
		$total = 0.0;

		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			if ( $fee_item instanceof WC_Order_Item_Fee ) {
				$total += (float) $fee_item->get_total();
			}
		}

		return $total;
	}

	/**
	 * `get_total_tax()`は`cart_tax`/`shipping_tax`の合算（`Woo\Writer\OrderWriter::apply_totals()`が
	 * 常に`shipping_tax='0'`で`totals.tax`全体を`cart_tax`へ寄せるため、この合算で元の値が
	 * そのまま復元できる）。
	 *
	 * `discount_total`/`shipping_total`/`total_tax`/`total`は`WC_Order`の型付きgetterだが、
	 * `set_*()`自身は`line_item_amounts()`と同じ理由で符号・数値妥当性を検証しない（postmeta
	 * 経由で他プラグイン・直接編集により壊れうる）。`Woo\Writer\OrderWriter::validate_totals()`
	 * （インポート方向）と同じ基準（数値・0以上）でフェイルクローズし、既存の
	 * `ORDER_TOTALS_INVALID`警告（書込方向と同じ意味。`indicates_export_blocking()`の対象＝
	 * 壊れた合計のまま注文をpushしない）を積む。
	 *
	 * @return array{0:array<string,mixed>,1:?string}
	 */
	private function totals( WC_Order $order ): array {
		$raw = [
			'discount'     => $order->get_discount_total(),
			'shipping_fee' => $order->get_shipping_total(),
			'tax'          => $order->get_total_tax(),
			'total'        => $order->get_total(),
		];

		foreach ( $raw as $key => $value ) {
			if ( ! is_numeric( $value ) || (float) $value < 0.0 ) {
				return [
					array_merge( array_fill_keys( array_keys( $raw ), '0' ), $this->totals_meta( $order ) ),
					WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, $key ),
				];
			}
		}

		return [ array_merge( $raw, $this->totals_meta( $order ) ), null ];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function totals_meta( WC_Order $order ): array {
		return [
			'discount_point' => $this->meta_string( $order, '_cbjp_discount_point' ),
			'discount_gmo'   => $this->meta_string( $order, '_cbjp_discount_gmo' ),
			'discount_other' => $this->meta_string( $order, '_cbjp_discount_other' ),
		];
	}

	private function meta_string( WC_Order $order, string $meta_key ): ?string {
		$value = $order->get_meta( $meta_key, true );

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
