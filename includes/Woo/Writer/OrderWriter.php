<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\AddressMapper;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WC_Order;

/**
 * `CanonicalOrder` をWooの受注として書き込む（`docs/03-design-decisions.md` §5 D10）。
 * HPOS対応のためWC_Order CRUD（`wc_create_order()`/`wc_get_order()`）のみを使い、
 * `wp_posts`直接操作は行わない。合計はASP側の値をそのまま設定し、`calculate_totals()`等の
 * Woo再計算は一切呼ばない。
 */
final class OrderWriter implements EntityWriter {

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings,
		private readonly OrderItemBuilder $items,
		private readonly MethodMap $methods
	) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalOrder ) {
			throw new RuntimeException( 'OrderWriter received an unsupported Canonical model.' );
		}

		$order = null !== $existing_local_id ? wc_get_order( $existing_local_id ) : wc_create_order( [ 'status' => 'pending' ] );

		if ( ! $order instanceof WC_Order ) {
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [] );
		}

		$operation = null === $existing_local_id ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		// 再実行時は明細を作り直す（冪等）。
		$order->remove_order_items();

		$warnings = $this->add_line_items( $order, $item->line_items );
		$warnings = array_merge( $warnings, $this->add_shipping_and_fees( $order, $item ) );

		$this->apply_totals( $order, $item->totals );
		$this->apply_currency_and_tax_settings( $order, $warnings );
		$warnings = array_merge( $warnings, $this->apply_status( $order, $item->status ) );
		$warnings = array_merge( $warnings, $this->apply_customer( $order, $item->customer_ref ) );
		$this->apply_addresses( $order, $item );
		$warnings = array_merge( $warnings, $this->apply_payment_method( $order, $item->payment ) );
		$this->apply_dates( $order, $item );
		$warnings = array_merge( $warnings, $this->totals_warnings( $item->totals ) );

		$this->apply_meta( $order, $item, $warnings );

		$order_id = $order->save();

		return new WriteResult( $order_id, $operation, $warnings );
	}

	/**
	 * @param array<int,array<string,mixed>> $line_items
	 * @return array<int,string>
	 */
	private function add_line_items( WC_Order $order, array $line_items ): array {
		$warnings = [];

		foreach ( $line_items as $line_item ) {
			$built = $this->items->build_line_item( $line_item );
			$order->add_item( $built['item'] );
			$warnings = array_merge( $warnings, $built['warnings'] );
		}

		return $warnings;
	}

	/**
	 * @return array<int,string>
	 */
	private function add_shipping_and_fees( WC_Order $order, CanonicalOrder $item ): array {
		$warnings           = [];
		$shipping_method_id = Value::string( $item->shipping['method_id'] ?? null );
		$mapped_method_id   = null !== $shipping_method_id ? $this->methods->mapped_shipping_method_id( $shipping_method_id ) : null;
		$mapped_title       = null !== $mapped_method_id ? $this->methods->shipping_method_title( $mapped_method_id ) : null;
		$shipping_built     = $this->items->build_shipping_item( $item->shipping, $mapped_method_id, $mapped_title );

		$order->add_item( $shipping_built['item'] );

		if ( null !== $shipping_method_id && null === $mapped_method_id ) {
			$warnings[] = WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, $shipping_method_id );
		}

		foreach ( $this->items->build_fee_items( $item->payment, $item->totals ) as $fee_item ) {
			$order->add_item( $fee_item );
		}

		return $warnings;
	}

	/**
	 * 割引はWC_Order_Item_Couponを作らずorder-levelのdiscount_totalとして設定する。
	 * ColorMeの割引はポイント利用・GMOポイント・その他が混在しクーポンではなく、
	 * クーポン行を作るとクーポン利用回数集計が壊れるため（`extras['shop_coupon']`はメタへ退避）。
	 *
	 * @param array<string,mixed> $totals
	 */
	private function apply_totals( WC_Order $order, array $totals ): void {
		$order->set_discount_total( Value::string( $totals['discount'] ?? null ) ?? '0' );
		$order->set_discount_tax( '0' );
		$order->set_shipping_total( Value::string( $totals['shipping_fee'] ?? null ) ?? '0' );

		// `set_total_tax()`はprotectedのため、public な `set_cart_tax()`/`set_shipping_tax()` 経由で
		// 入れる。ASP側は送料・商品の税を分けて持たないケースがあるため、注文全体の税額
		// （totals.tax）を丸ごとcart_taxに寄せる（get_total_tax()の合計値が一致すればよく、
		// cart/shipping間の内訳はWoo管理画面の表示上のバケット分けでしかないため）。
		$order->set_cart_tax( Value::string( $totals['tax'] ?? null ) ?? '0' );
		$order->set_shipping_tax( '0' );

		$order->set_total( Value::string( $totals['total'] ?? null ) ?? '0' );
	}

	/**
	 * @param array<int,string> $warnings
	 */
	private function apply_currency_and_tax_settings( WC_Order $order, array &$warnings ): void {
		$currency = get_woocommerce_currency();
		$order->set_currency( $currency );

		if ( 'JPY' !== $currency ) {
			$warnings[] = WarningCode::CURRENCY_MISMATCH;
		}

		$order->set_prices_include_tax( wc_prices_include_tax() );
	}

	/**
	 * @return array<int,string>
	 */
	private function apply_status( WC_Order $order, string $canonical_status ): array {
		$warnings = [];
		$mapped   = $this->methods->order_status( $canonical_status );
		// `ltrim($status, 'wc-')` は文字クラスとして扱われ 'completed' の先頭 'c' まで
		// 削ってしまう罠があるため、リテラルプレフィックスとして厳密に判定する。
		$status = str_starts_with( $mapped, 'wc-' ) ? substr( $mapped, 3 ) : $mapped;
		$known  = wc_get_order_statuses();

		if ( ! array_key_exists( "wc-{$status}", $known ) ) {
			$warnings[] = WarningCode::with_detail( WarningCode::ORDER_STATUS_UNKNOWN, $status );
		}

		$order->set_status( $status );

		// D10 #6: ASP側で既に確定済みの受注に対してWoo標準の在庫増減を再度走らせない
		// （SideEffectGuardのフィルターに加え、履歴として正しいフラグを明示する）。
		// completed/processingはASP側で既に減算済みという履歴として true にし、
		// falseのままだとcancelled等への遷移時に在庫が「戻されて」増えてしまう。
		$order->set_order_stock_reduced( in_array( $status, [ 'completed', 'processing' ], true ) );
		$order->set_new_order_email_sent( true );
		$order->set_recorded_sales( true );
		$order->set_recorded_coupon_usage_counts( true );
		$order->set_download_permissions_granted( true );

		return $warnings;
	}

	/**
	 * @return array<int,string>
	 */
	private function apply_customer( WC_Order $order, ?string $customer_ref ): array {
		if ( null === $customer_ref ) {
			$order->set_customer_id( 0 );

			return [];
		}

		$local_id = $this->mappings->find_local_id( $this->platform, 'customer', $customer_ref );

		if ( null === $local_id ) {
			$order->set_customer_id( 0 );

			return [ WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, $customer_ref ) ];
		}

		$order->set_customer_id( $local_id );

		return [];
	}

	private function apply_addresses( WC_Order $order, CanonicalOrder $item ): void {
		// 請求先は`customer_snapshot`（注文時の値）を使う。ゲスト購入・退会済み顧客では
		// これが唯一の情報源（D10。現在の会員プロフィールを参照すると注文時の情報とずれる）。
		$snapshot     = Value::array_or_null( $item->extras['customer_snapshot'] ?? null ) ?? [];
		$billing_name = Value::string( $snapshot['name'] ?? null ) ?? '';

		$order->set_billing_address(
			AddressMapper::to_woo(
				$snapshot,
				$billing_name,
				Value::string( $snapshot['email'] ?? null ) ?? '',
				Value::string( $snapshot['phone'] ?? null ),
				Value::string( $snapshot['company'] ?? null )
			)
		);

		$shipping_name = Value::string( $item->shipping['name'] ?? null ) ?? $billing_name;

		$order->set_shipping_address(
			AddressMapper::to_woo(
				$item->shipping,
				$shipping_name,
				'',
				Value::string( $item->shipping['tel'] ?? null ),
				null
			)
		);
	}

	/**
	 * @param array<string,mixed> $payment
	 * @return array<int,string>
	 */
	private function apply_payment_method( WC_Order $order, array $payment ): array {
		$method_id   = Value::string( $payment['method_id'] ?? null );
		$method_name = Value::string( $payment['method_name'] ?? null );
		$mapped_id   = null !== $method_id ? $this->methods->mapped_payment_gateway_id( $method_id ) : null;

		if ( null !== $mapped_id ) {
			$order->set_payment_method( $mapped_id );
			$order->set_payment_method_title( $this->methods->payment_gateway_title( $mapped_id ) );

			return [];
		}

		// 未マッピング: Wooの決済ゲートウェイとして実在しないASP側の生ID/名称を
		// `payment_method`（ゲートウェイID）へ設定すると、決済連携プラグイン等の
		// ゲートウェイ判定処理が誤動作しうるため空にし、元の名称はタイトルにのみ保持する。
		$order->set_payment_method( '' );
		$order->set_payment_method_title( $method_name ?? '' );

		if ( null !== $method_id ) {
			return [ WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, $method_id ) ];
		}

		return [];
	}

	private function apply_dates( WC_Order $order, CanonicalOrder $item ): void {
		$order->set_date_created( $item->placed_at );

		if ( true === Value::bool( $item->extras['paid'] ?? null ) ) {
			$order->set_date_paid( $item->placed_at );
		}
	}

	/**
	 * @param array<string,mixed> $totals
	 * @return array<int,string>
	 */
	private function totals_warnings( array $totals ): array {
		$warnings = [];

		if ( isset( $totals['residual'] ) ) {
			$warnings[] = WarningCode::with_detail( WarningCode::ORDER_TOTAL_RESIDUAL, (string) $totals['residual'] );
		}

		if ( 'unavailable_for_split_order' === ( $totals['tax_source'] ?? null ) ) {
			$warnings[] = WarningCode::ORDER_SPLIT_TAX_UNKNOWN;
		}

		return $warnings;
	}

	/**
	 * @param array<int,string> $warnings
	 */
	private function apply_meta( WC_Order $order, CanonicalOrder $item, array $warnings ): void {
		$order->update_meta_data( '_cbjp_platform', $this->platform );
		$order->update_meta_data( '_cbjp_remote_order_number', $item->number );
		$order->update_meta_data( '_cbjp_remote_order_id', Value::string( $item->extras['remote_id'] ?? null ) ?? $item->number );

		$this->set_or_delete_meta( $order, '_cbjp_external_order_id', Value::string( $item->extras['external_order_id'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_memo', $item->note );

		$this->set_or_delete_meta( $order, '_cbjp_slip_number', Value::string( $item->shipping['slip_number'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_tracking_url', Value::string( $item->shipping['tracking_url'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_preferred_date', Value::string( $item->shipping['preferred_date'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_preferred_period', Value::string( $item->shipping['preferred_period'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_noshi_text', Value::string( $item->shipping['noshi_text'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_card_name', Value::string( $item->shipping['card_name'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_card_text', Value::string( $item->shipping['card_text'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_wrapping_name', Value::string( $item->shipping['wrapping_name'] ?? null ) );

		foreach ( [ 'point_state', 'granted_points', 'use_points', 'gmo_point_state', 'granted_gmo_points', 'use_gmo_points', 'yahoo_point_state', 'granted_yahoo_points', 'use_yahoo_points' ] as $key ) {
			$value = $item->extras[ $key ] ?? null;

			if ( null !== $value ) {
				$order->update_meta_data( "_cbjp_{$key}", $value );
			}
		}

		$sale_deliveries = Value::array_or_null( $item->extras['sale_deliveries'] ?? null );

		if ( null !== $sale_deliveries ) {
			$order->update_meta_data( '_cbjp_sale_deliveries', wp_json_encode( $sale_deliveries ) );
		}

		$segment = Value::array_or_null( $item->extras['segment'] ?? null );

		if ( null !== $segment ) {
			$order->update_meta_data( '_cbjp_segment', wp_json_encode( $segment ) );
		}

		$shop_coupon = Value::array_or_null( $item->extras['shop_coupon'] ?? null );

		if ( null !== $shop_coupon ) {
			$order->update_meta_data( '_cbjp_shop_coupon', wp_json_encode( $shop_coupon ) );
		}

		foreach ( [ 'discount_point', 'discount_gmo', 'discount_other' ] as $key ) {
			$value = Value::string( $item->totals[ $key ] ?? null );

			if ( null !== $value ) {
				$order->update_meta_data( "_cbjp_{$key}", $value );
			}
		}

		$order->update_meta_data( '_cbjp_import_warnings', wp_json_encode( array_values( array_unique( $warnings ) ) ) );
	}

	private function set_or_delete_meta( WC_Order $order, string $meta_key, ?string $value ): void {
		if ( null === $value ) {
			$order->delete_meta_data( $meta_key );

			return;
		}

		$order->update_meta_data( $meta_key, $value );
	}
}
