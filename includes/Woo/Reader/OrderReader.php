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
 */
final class OrderReader implements EntityReader {

	private const PAGE_SIZE = 20;

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

		$items = array_values( array_map( fn ( WC_Order $order ): ReadItem => $this->to_read_item( $order ), $result->orders ) );

		$page          = (int) ( $args['page'] ?? 1 );
		$has_next_page = null === $only_local_ids && $page < $result->max_num_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, $result->total );
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

		$canonical = new CanonicalOrder(
			$order->get_order_number(),
			$order->get_status(),
			$customer_ref,
			$line_items,
			$this->shipping( $order ),
			$this->payment( $order ),
			$this->totals( $order ),
			$order->get_date_created() instanceof WC_DateTime ? $order->get_date_created()->date( DATE_ATOM ) : '',
			$this->meta_string( $order, '_cbjp_memo' ),
			[]
		);

		return new ReadItem( $order->get_id(), $canonical, $warnings, ! WarningCode::indicates_unresolved_reference( $warnings ) );
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

			$quantity  = $order_item->get_quantity();
			$total     = (float) $order_item->get_total();
			$total_tax = (float) $order_item->get_total_tax();

			$unit_price_excl_tax = $quantity > 0 ? wc_format_decimal( $total / $quantity ) : '0';
			$line_total_incl     = wc_format_decimal( $total + $total_tax );
			$unit_price_incl     = $quantity > 0 ? wc_format_decimal( ( $total + $total_tax ) / $quantity ) : '0';

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
	 * （`Woo\Reader\ProductReader::variants()`と同じ規約）。
	 *
	 * @return array{0:?string,1:?string}
	 */
	private function remote_product_id( WC_Order_Item_Product $order_item ): array {
		$variation_id = $order_item->get_variation_id();
		$product_id   = $order_item->get_product_id();

		if ( 0 !== $variation_id ) {
			$remote_id = $this->mappings->find_remote_id( $this->platform, 'variant', $variation_id );

			if ( null !== $remote_id ) {
				return [ $remote_id, null ];
			}

			return [ null, WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED, (string) $variation_id ) ];
		}

		if ( 0 !== $product_id ) {
			$remote_id = $this->mappings->find_remote_id( $this->platform, 'product', $product_id );

			if ( null !== $remote_id ) {
				return [ $remote_id, null ];
			}

			return [ null, WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED, (string) $product_id ) ];
		}

		// 商品リンクを持たないカスタム行（削除済み商品の明細等）。解決対象が無いため警告も出さない。
		return [ null, null ];
	}

	/**
	 * @return array{0:?string,1:?string}
	 */
	private function customer_ref( WC_Order $order ): array {
		$customer_id = $order->get_customer_id();

		if ( 0 === $customer_id ) {
			return [ null, null ];
		}

		$remote_id = $this->mappings->find_remote_id( $this->platform, 'customer', $customer_id );

		if ( null !== $remote_id ) {
			return [ $remote_id, null ];
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
	 * @return array<string,mixed>
	 */
	private function payment( WC_Order $order ): array {
		return [
			'method_id'   => '' !== $order->get_payment_method() ? $order->get_payment_method() : null,
			'method_name' => '' !== $order->get_payment_method_title() ? $order->get_payment_method_title() : null,
		];
	}

	/**
	 * `get_total_tax()`は`cart_tax`/`shipping_tax`の合算（`Woo\Writer\OrderWriter::apply_totals()`が
	 * 常に`shipping_tax='0'`で`totals.tax`全体を`cart_tax`へ寄せるため、この合算で元の値が
	 * そのまま復元できる）。
	 *
	 * @return array<string,mixed>
	 */
	private function totals( WC_Order $order ): array {
		return [
			'discount'       => $order->get_discount_total(),
			'shipping_fee'   => $order->get_shipping_total(),
			'tax'            => $order->get_total_tax(),
			'total'          => $order->get_total(),
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
