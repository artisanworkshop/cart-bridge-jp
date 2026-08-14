<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalOrder;
use RuntimeException;

/**
 * `GET /v1/sales.json` `GET /v1/sales/{id}.json` の1要素を `CanonicalOrder` へ変換する。
 * 受注インポートの詳細仕様は `docs/03-design-decisions.md` §5（D10）。
 *
 * `sale` レスポンスは `payment_id`/`delivery_id` のみを持ち名称を含まないため、
 * `/v1/payments.json` `/v1/deliveries.json` から取得した名称マップをコンストラクタで受け取る
 * （呼び出し側=F1-5のColorMeAdapterが注入する）。
 */
final class OrderTransformer {

	/**
	 * @param array<int,string> $payment_names payment_id => name。
	 * @param array<int,string> $delivery_names delivery_id => name。
	 */
	public function __construct(
		private readonly array $payment_names = [],
		private readonly array $delivery_names = []
	) {}

	/**
	 * @param array<string,mixed> $raw `sales[]` の1要素、または `sale` 単体。
	 */
	public function transform( array $raw ): CanonicalOrder {
		$number = Cast::to_string_or_null( $raw['id'] ?? null );

		if ( null === $number ) {
			// `number` は `remote_id()` としてmappingsのUNIQUEキーに使われる。空文字のまま
			// 通すと欠損IDの受注が全て同一remote_idに衝突するため、ここで必須として弾く。
			throw new RuntimeException( 'ColorMe sale is missing id; cannot determine order number.' );
		}

		$placed_at = Cast::unix_to_iso( $raw['make_date'] ?? null );

		if ( null === $placed_at ) {
			throw new RuntimeException( 'ColorMe sale is missing make_date; cannot determine placed_at.' );
		}

		$customer = $raw['customer'] ?? null;

		return new CanonicalOrder(
			$number,
			$this->status( $raw ),
			is_array( $customer ) ? Cast::to_string_or_null( $customer['id'] ?? null ) : null,
			$this->line_items( $raw ),
			$this->shipping( $raw ),
			$this->payment( $raw ),
			$this->totals( $raw ),
			$placed_at,
			Cast::to_string_or_null( $raw['memo'] ?? null ),
			$this->extras( $raw )
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function status( array $raw ): string {
		if ( true === ( $raw['canceled'] ?? null ) ) {
			return 'cancelled';
		}

		if ( true === ( $raw['delivered'] ?? null ) ) {
			return 'completed';
		}

		if ( true === ( $raw['paid'] ?? null ) ) {
			return 'processing';
		}

		return 'pending';
	}

	/**
	 * `SampleSelector`（`includes/Sync/SampleSelector.php`）が `remote_product_id` キーのみを読む。
	 * ここに設定する値は `ProductTransformer` の `extras['remote_id']` とバイト一致していなければ
	 * ならない（無料版のサンプル商品ID指定取得が空振りする）。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function line_items( array $raw ): array {
		$details = $raw['details'] ?? [];

		if ( ! is_array( $details ) ) {
			return [];
		}

		$result = [];

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			$result[] = [
				'remote_detail_id'    => Cast::to_string_or_null( $detail['id'] ?? null ),
				'remote_product_id'   => Cast::to_string_or_null( $detail['product_id'] ?? null ),
				'sku'                 => Cast::to_string_or_null( $detail['product_model_number'] ?? null ),
				'name'                => Cast::first_non_empty( $detail['pristine_product_full_name'] ?? null, $detail['product_name'] ?? null ),
				'price'               => Cast::money( $detail['price_with_tax'] ?? null ),
				'unit_price_excl_tax' => Cast::money( $detail['price'] ?? null ),
				'quantity'            => Cast::to_int_or_null( $detail['product_num'] ?? null ) ?? 1,
				'subtotal'            => Cast::money( $detail['subtotal_price'] ?? null ),
				'option1_value'       => Cast::to_string_or_null( $detail['option1_value'] ?? null ),
				'option2_value'       => Cast::to_string_or_null( $detail['option2_value'] ?? null ),
				'tax_reduced'         => Cast::to_bool_or_null( $detail['tax_reduced'] ?? null ),
				// 刻印文字等、購入者が入力したカスタマイズ内容。案文構造がASP固有のため生のまま退避する。
				'customizations'      => is_array( $detail['customizations'] ?? null ) ? $detail['customizations'] : [],
			];
		}

		return $result;
	}

	/**
	 * 送料は `sale.delivery_total_charge` を使う（`sale_deliveries[0].delivery_charge` は
	 * 複数配送先の受注では1件分の値でしかない）。配送先住所は先頭の配送を代表として使い、
	 * 全配送は `extras['sale_deliveries']` に退避する。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function shipping( array $raw ): array {
		$deliveries = $raw['sale_deliveries'] ?? [];
		$first      = ( is_array( $deliveries ) && is_array( $deliveries[0] ?? null ) ) ? $deliveries[0] : [];

		$delivery_id   = Cast::to_int_or_null( $first['delivery_id'] ?? null );
		$delivery_name = null !== $delivery_id ? ( $this->delivery_names[ $delivery_id ] ?? null ) : null;

		return [
			'method_id'    => Cast::to_string_or_null( $first['delivery_id'] ?? null ),
			'method_name'  => $delivery_name,
			'fee'          => Cast::money( $raw['delivery_total_charge'] ?? null ),
			'name'         => Cast::to_string_or_null( $first['name'] ?? null ),
			'postal'       => Cast::to_string_or_null( $first['postal'] ?? null ),
			'pref_name'    => Cast::to_string_or_null( $first['pref_name'] ?? null ),
			'address1'     => Cast::to_string_or_null( $first['address1'] ?? null ),
			'address2'     => Cast::to_string_or_null( $first['address2'] ?? null ),
			'tel'          => Cast::to_string_or_null( $first['tel'] ?? null ),
			'slip_number'  => Cast::to_string_or_null( $first['slip_number'] ?? null ),
			'tracking_url' => Cast::to_string_or_null( $first['tracking_url'] ?? null ),
		];
	}

	/**
	 * `payments.json` の `fee` ではなく、実際にその受注へ課金された `sale.fee` を使う。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function payment( array $raw ): array {
		$payment_id   = Cast::to_int_or_null( $raw['payment_id'] ?? null );
		$payment_name = null !== $payment_id ? ( $this->payment_names[ $payment_id ] ?? null ) : null;

		return [
			'method_id'   => Cast::to_string_or_null( $raw['payment_id'] ?? null ),
			'method_name' => $payment_name,
			'fee'         => Cast::money( $raw['fee'] ?? null ),
		];
	}

	/**
	 * 実測（`sale_bank_detail.json` / `sale_daibiki_detail.json`）で確認した恒等式:
	 * `total_price == product_total_price + delivery_total_charge + fee
	 *              + noshi_total_charge + card_total_charge + wrapping_total_charge
	 *              - point_discount - gmo_point_discount - other_discount`
	 *
	 * `sale.tax` は商品分のみで送料分の税を含まないため、注文全体の税額には
	 * `sale.totals.normal_tax_amount + reduced_tax_amount` を使う（欠損時は `sale.tax` にフォールバック）。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function totals( array $raw ): array {
		$subtotal       = Cast::to_int_or_null( $raw['product_total_price'] ?? null ) ?? 0;
		$shipping       = Cast::to_int_or_null( $raw['delivery_total_charge'] ?? null ) ?? 0;
		$fee            = Cast::to_int_or_null( $raw['fee'] ?? null ) ?? 0;
		$noshi          = Cast::to_int_or_null( $raw['noshi_total_charge'] ?? null ) ?? 0;
		$card           = Cast::to_int_or_null( $raw['card_total_charge'] ?? null ) ?? 0;
		$wrapping       = Cast::to_int_or_null( $raw['wrapping_total_charge'] ?? null ) ?? 0;
		$discount_point = Cast::to_int_or_null( $raw['point_discount'] ?? null ) ?? 0;
		$discount_gmo   = Cast::to_int_or_null( $raw['gmo_point_discount'] ?? null ) ?? 0;
		$discount_other = Cast::to_int_or_null( $raw['other_discount'] ?? null ) ?? 0;
		$total          = Cast::to_int_or_null( $raw['total_price'] ?? null ) ?? 0;

		$gift_charges = $noshi + $card + $wrapping;
		$discount     = $discount_point + $discount_gmo + $discount_other;
		$expected     = $subtotal + $shipping + $fee + $gift_charges - $discount;

		[ $tax, $tax_normal, $tax_reduced, $tax_source ] = $this->tax( $raw );

		$totals = [
			'subtotal'       => Cast::money( $subtotal ),
			'shipping'       => Cast::money( $shipping ),
			'fee'            => Cast::money( $fee ),
			'gift_charges'   => Cast::money( $gift_charges ),
			'discount'       => Cast::money( $discount ),
			'discount_point' => Cast::money( $discount_point ),
			'discount_gmo'   => Cast::money( $discount_gmo ),
			'discount_other' => Cast::money( $discount_other ),
			'tax'            => Cast::money( $tax ),
			'tax_normal'     => Cast::money( $tax_normal ),
			'tax_reduced'    => Cast::money( $tax_reduced ),
			'tax_source'     => $tax_source,
			'total'          => Cast::money( $total ),
		];

		if ( $expected !== $total ) {
			// APIに `use_yahoo_points` 相当の割引フィールドが存在しないなど、恒等式が
			// 崩れるケースがある。差額を残し、F1-6のdry-run警告で使う。
			$totals['residual'] = Cast::money( $expected - $total );
		}

		return $totals;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{0:int,1:int,2:int,3:string}
	 */
	private function tax( array $raw ): array {
		$order_totals = $raw['totals'] ?? null;

		if ( is_array( $order_totals ) && isset( $order_totals['normal_tax_amount'] ) ) {
			$normal  = Cast::to_int_or_null( $order_totals['normal_tax_amount'] ) ?? 0;
			$reduced = Cast::to_int_or_null( $order_totals['reduced_tax_amount'] ?? null ) ?? 0;

			return [ $normal + $reduced, $normal, $reduced, 'sale.totals' ];
		}

		$product_tax = Cast::to_int_or_null( $raw['tax'] ?? null ) ?? 0;

		return [ $product_tax, $product_tax, 0, 'sale.tax' ];
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extras( array $raw ): array {
		return [
			'remote_id'           => Cast::to_string_or_null( $raw['id'] ?? null ),
			'external_order_id'   => Cast::to_string_or_null( $raw['external_order_id'] ?? null ),
			'shop_coupon'         => is_array( $raw['shop_coupon'] ?? null ) ? $raw['shop_coupon'] : null,
			'other_discount_name' => Cast::to_string_or_null( $raw['other_discount_name'] ?? null ),
			'product_tax'         => Cast::to_int_or_null( $raw['tax'] ?? null ),
			'granted_points'      => Cast::to_int_or_null( $raw['granted_points'] ?? null ),
			'use_points'          => Cast::to_int_or_null( $raw['use_points'] ?? null ),
			'paid'                => Cast::to_bool_or_null( $raw['paid'] ?? null ),
			'delivered'           => Cast::to_bool_or_null( $raw['delivered'] ?? null ),
			'canceled'            => Cast::to_bool_or_null( $raw['canceled'] ?? null ),
			'mobile'              => Cast::to_bool_or_null( $raw['mobile'] ?? null ),
			'sale_deliveries'     => is_array( $raw['sale_deliveries'] ?? null ) ? $raw['sale_deliveries'] : [],
			'customer_snapshot'   => $this->customer_snapshot( $raw ),
		];
	}

	/**
	 * 受注時点の購入者情報のスナップショット（D10の「明細は注文時の値を使う」と同じ考え方を
	 * 請求先情報にも適用する）。ゲスト購入・退会済み顧客・プロフィール変更後の突合では、
	 * `customer_ref` 経由で解決した現在の顧客レコードではなく、この値をWoo注文の請求先に使う。
	 *
	 * @param array<string,mixed> $raw
	 * @return ?array<string,mixed>
	 */
	private function customer_snapshot( array $raw ): ?array {
		$customer = $raw['customer'] ?? null;

		if ( ! is_array( $customer ) ) {
			return null;
		}

		return [
			'email'      => Cast::to_string_or_null( $customer['mail'] ?? null ),
			'name'       => Cast::to_string_or_null( $customer['name'] ?? null ),
			'kana'       => Cast::to_string_or_null( $customer['furigana'] ?? null ),
			'company'    => Cast::to_string_or_null( $customer['hojin'] ?? null ),
			'department' => Cast::to_string_or_null( $customer['busho'] ?? null ),
			'phone'      => Cast::first_non_empty( $customer['tel'] ?? null, $customer['tel_mobile'] ?? null ),
			'postal'     => Cast::to_string_or_null( $customer['postal'] ?? null ),
			'pref_id'    => Cast::to_int_or_null( $customer['pref_id'] ?? null ),
			'pref_name'  => Cast::to_string_or_null( $customer['pref_name'] ?? null ),
			'address1'   => Cast::to_string_or_null( $customer['address1'] ?? null ),
			'address2'   => Cast::to_string_or_null( $customer['address2'] ?? null ),
		];
	}
}
