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

		return new CanonicalOrder(
			$number,
			$this->status( $raw ),
			$this->customer_ref( $raw ),
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
	 * ゲスト購入（`customer.member === false`）は`SampleSelector`の顧客枠にカウントさせない
	 * ため、`customer_ref` は登録会員のみ設定する（`docs/03-design-decisions.md` §10.2）。
	 * ゲストの請求先情報は `customer_snapshot()` 側で別途保持する。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function customer_ref( array $raw ): ?string {
		$customer = $raw['customer'] ?? null;

		if ( ! is_array( $customer ) || true !== ( $customer['member'] ?? null ) ) {
			return null;
		}

		return Cast::to_string_or_null( $customer['id'] ?? null );
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
	 * 分割受注（`segment.splitted === true`）の場合、商品・送料・熨斗等の合計は
	 * トップレベルの `sale.*`（分割前の全体額）ではなく `segment.*`（この分割分の実額）を
	 * 情報源として使う。分割されていない受注の `segment` は自身の受注IDを指すだけの
	 * 非分割メタ情報のため使わない。`shipping()`/`totals()`で共有する。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function split_amounts( array $raw ): array {
		$segment = $raw['segment'] ?? null;

		return ( $this->is_split( $raw ) && is_array( $segment ) ) ? $segment : $raw;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function is_split( array $raw ): bool {
		$segment = $raw['segment'] ?? null;

		return is_array( $segment ) && true === ( $segment['splitted'] ?? null );
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
				'remote_detail_id'        => Cast::to_string_or_null( $detail['id'] ?? null ),
				'remote_product_id'       => Cast::to_string_or_null( $detail['product_id'] ?? null ),
				// 複数配送先の受注で、この明細がどの配送先に属するかを示す（sale_deliveriesの各要素のidと対応）。
				// 配送方法IDとは別物（配送方法IDはshippingキーのmethod_idを参照）。
				'remote_sale_delivery_id' => Cast::to_string_or_null( $detail['sale_delivery_id'] ?? null ),
				'sku'                     => Cast::to_string_or_null( $detail['product_model_number'] ?? null ),
				'name'                    => Cast::first_non_empty( $detail['pristine_product_full_name'] ?? null, $detail['product_name'] ?? null ),
				'price'                   => Cast::money( $detail['price_with_tax'] ?? null ),
				'unit_price_excl_tax'     => Cast::money( $detail['price'] ?? null ),
				'quantity'                => Cast::to_int_or_null( $detail['product_num'] ?? null ) ?? 1,
				'subtotal'                => Cast::money( $detail['subtotal_price'] ?? null ),
				// swagger: option1_value/option2_valueは「最新の商品情報」であり注文時点の値ではない
				// （オプション名変更後は購入時の選択と食い違いうる）。注文時点の選択を表すのは
				// `name`（pristine_product_full_name）のみのため、これらは変位判定用の参考値として
				// current_ prefixを付けて明示的に区別する（D10: 明細は注文時の値を使う原則）。
				'option1_value_current'   => Cast::to_string_or_null( $detail['option1_value'] ?? null ),
				'option2_value_current'   => Cast::to_string_or_null( $detail['option2_value'] ?? null ),
				'tax_reduced'             => Cast::to_bool_or_null( $detail['tax_reduced'] ?? null ),
				// 刻印文字等、購入者が入力したカスタマイズ内容。案文構造がASP固有のため生のまま退避する。
				'customizations'          => is_array( $detail['customizations'] ?? null ) ? $detail['customizations'] : [],
			];
		}

		return $result;
	}

	/**
	 * 送料は通常 `sale.delivery_total_charge` を使う（`sale_deliveries[0].delivery_charge` は
	 * 複数配送先の受注では1件分の値でしかない）。分割受注（`segment.splitted === true`）の場合は
	 * `totals()` と同じく `segment.delivery_total_charge`（この分割分の実額）を使う
	 * （`split_amounts()`参照。分割前の全体額のままだと `totals['shipping']` と食い違う）。
	 * 配送先住所は先頭の配送を代表として使い、全配送は `extras['sale_deliveries']` に退避する。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function shipping( array $raw ): array {
		$deliveries = $raw['sale_deliveries'] ?? [];
		$first      = ( is_array( $deliveries ) && is_array( $deliveries[0] ?? null ) ) ? $deliveries[0] : [];

		$delivery_id   = Cast::to_int_or_null( $first['delivery_id'] ?? null );
		$delivery_name = null !== $delivery_id ? ( $this->delivery_names[ $delivery_id ] ?? null ) : null;
		$pref_id       = Cast::to_int_or_null( $first['pref_id'] ?? null );

		return [
			'method_id'    => Cast::to_string_or_null( $first['delivery_id'] ?? null ),
			'method_name'  => $delivery_name,
			'fee'          => Cast::money( $this->split_amounts( $raw )['delivery_total_charge'] ?? null ),
			'name'         => Cast::to_string_or_null( $first['name'] ?? null ),
			'postal'       => Cast::to_string_or_null( $first['postal'] ?? null ),
			'pref_id'      => $pref_id,
			'pref_name'    => Cast::to_string_or_null( $first['pref_name'] ?? null ),
			'address1'     => Cast::to_string_or_null( $first['address1'] ?? null ),
			'address2'     => Cast::to_string_or_null( $first['address2'] ?? null ),
			'tel'          => Cast::to_string_or_null( $first['tel'] ?? null ),
			'slip_number'  => Cast::to_string_or_null( $first['slip_number'] ?? null ),
			'tracking_url' => Cast::to_string_or_null( $first['tracking_url'] ?? null ),
			// pref_id=48は「海外」を表す特別値。`saleDelivery`スキーマ自体には明記が無いが、
			// `customer`スキーマで定義された同名フィールドとの一貫性から同じ規約で正規化する
			// （CustomerTransformer::address()参照）。
			'country'      => 48 === $pref_id ? null : 'JP',
		];
	}

	/**
	 * `payments.json` の `fee` ではなく、実際にその受注へ課金された `sale.fee` を使う。
	 * 分割受注では`segment`スキーマに対応フィールドが無く分割単位の実額が不明なため、
	 * `totals()`の`fee`と同じく0にする（`sale.fee`は分割前1回分の額で、複数segmentに
	 * そのまま転記すると重複計上になる）。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function payment( array $raw ): array {
		$payment_id   = Cast::to_int_or_null( $raw['payment_id'] ?? null );
		$payment_name = null !== $payment_id ? ( $this->payment_names[ $payment_id ] ?? null ) : null;
		$fee          = $this->is_split( $raw ) ? 0 : ( Cast::to_int_or_null( $raw['fee'] ?? null ) ?? 0 );

		return [
			'method_id'   => Cast::to_string_or_null( $raw['payment_id'] ?? null ),
			'method_name' => $payment_name,
			'fee'         => Cast::money( $fee ),
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
	 * 分割受注（`segment.splitted === true`）の場合、商品・送料・熨斗等の合計は
	 * `segment.*` がこの分割分の実額であり、トップレベルの `sale.*` は分割前の全体額のまま
	 * のため使えない。`fee`/各種割引・税額（`sale.tax`/`sale.totals`）も同様に`segment`スキーマに
	 * 対応フィールドが無く分割単位の実額を導出できない。この場合`sale.*`の値は「分割前の全体に
	 * かかった1回分の額」であり、複数のsegmentをそれぞれ個別の注文としてインポートすると
	 * 同じfee/割引が重複計上されてしまうため、`segment`が確定できた場合は`sale.*`をそのまま
	 * 使わず明示的に0にする（既存フィクスチャで実測した`segment.total_price`は
	 * `product_total_price + delivery_total_charge + gift_charges`のみで一致し、fee/discount/tax
	 * を含まないことを確認済み。恒等式が崩れる場合は `residual` に表れる）。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function totals( array $raw ): array {
		$is_split = $this->is_split( $raw );
		$amounts  = $this->split_amounts( $raw );

		$subtotal       = Cast::to_int_or_null( $amounts['product_total_price'] ?? null ) ?? 0;
		$shipping       = Cast::to_int_or_null( $amounts['delivery_total_charge'] ?? null ) ?? 0;
		$fee            = $is_split ? 0 : Cast::to_int_or_null( $raw['fee'] ?? null ) ?? 0;
		$noshi          = Cast::to_int_or_null( $amounts['noshi_total_charge'] ?? null ) ?? 0;
		$card           = Cast::to_int_or_null( $amounts['card_total_charge'] ?? null ) ?? 0;
		$wrapping       = Cast::to_int_or_null( $amounts['wrapping_total_charge'] ?? null ) ?? 0;
		$discount_point = $is_split ? 0 : Cast::to_int_or_null( $raw['point_discount'] ?? null ) ?? 0;
		$discount_gmo   = $is_split ? 0 : Cast::to_int_or_null( $raw['gmo_point_discount'] ?? null ) ?? 0;
		$discount_other = $is_split ? 0 : Cast::to_int_or_null( $raw['other_discount'] ?? null ) ?? 0;
		$total          = Cast::to_int_or_null( $amounts['total_price'] ?? null ) ?? 0;

		$gift_charges = $noshi + $card + $wrapping;
		$discount     = $discount_point + $discount_gmo + $discount_other;
		$expected     = $subtotal + $shipping + $fee + $gift_charges - $discount;

		[ $tax, $tax_normal, $tax_reduced, $tax_source ] = $this->tax( $raw, $is_split );

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
	 * `segment`スキーマには税額に対応するフィールドが無いため、分割受注では
	 * 親受注（分割前の全体）の税額をそのまま転記せず、明示的に「不明」として返す。
	 *
	 * @param array<string,mixed> $raw
	 * @return array{0:int,1:int,2:int,3:string}
	 */
	private function tax( array $raw, bool $is_split ): array {
		if ( $is_split ) {
			return [ 0, 0, 0, 'unavailable_for_split_order' ];
		}

		$order_totals = $raw['totals'] ?? null;

		if ( is_array( $order_totals ) && isset( $order_totals['normal_tax_amount'] ) ) {
			$normal  = Cast::to_int_or_null( $order_totals['normal_tax_amount'] ) ?? 0;
			$reduced = Cast::to_int_or_null( $order_totals['reduced_tax_amount'] ?? null ) ?? 0;

			return [ $normal + $reduced, $normal, $reduced, 'sale.totals' ];
		}

		// `sale.totals`（nullable）が欠損している場合の最後の手段。`sale.tax`は商品分のみで
		// 送料分の税を含まないため、送料に課税がある注文では実際より低い税額になる。
		// 実額を捏造せず、欠損していない部分（商品分）だけを保持しつつ `tax_source` で
		// 不完全である旨を明示し、F1-6のdry-run警告で使えるようにする。
		$product_tax = Cast::to_int_or_null( $raw['tax'] ?? null ) ?? 0;

		return [ $product_tax, $product_tax, 0, 'sale.tax_incomplete_excludes_shipping_tax' ];
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extras( array $raw ): array {
		return [
			'remote_id'            => Cast::to_string_or_null( $raw['id'] ?? null ),
			'external_order_id'    => Cast::to_string_or_null( $raw['external_order_id'] ?? null ),
			'shop_coupon'          => is_array( $raw['shop_coupon'] ?? null ) ? $raw['shop_coupon'] : null,
			// 受注分割時の親子関係の紐付けに使う生データ。分割金額の算出元と同一のデータを保持する。
			'segment'              => is_array( $raw['segment'] ?? null ) ? $raw['segment'] : null,
			'other_discount_name'  => Cast::to_string_or_null( $raw['other_discount_name'] ?? null ),
			'product_tax'          => Cast::to_int_or_null( $raw['tax'] ?? null ),
			'point_state'          => Cast::to_string_or_null( $raw['point_state'] ?? null ),
			'granted_points'       => Cast::to_int_or_null( $raw['granted_points'] ?? null ),
			'use_points'           => Cast::to_int_or_null( $raw['use_points'] ?? null ),
			'gmo_point_state'      => Cast::to_string_or_null( $raw['gmo_point_state'] ?? null ),
			'granted_gmo_points'   => Cast::to_int_or_null( $raw['granted_gmo_points'] ?? null ),
			'use_gmo_points'       => Cast::to_int_or_null( $raw['use_gmo_points'] ?? null ),
			'yahoo_point_state'    => Cast::to_string_or_null( $raw['yahoo_point_state'] ?? null ),
			'granted_yahoo_points' => Cast::to_int_or_null( $raw['granted_yahoo_points'] ?? null ),
			'use_yahoo_points'     => Cast::to_int_or_null( $raw['use_yahoo_points'] ?? null ),
			'paid'                 => Cast::to_bool_or_null( $raw['paid'] ?? null ),
			'delivered'            => Cast::to_bool_or_null( $raw['delivered'] ?? null ),
			'canceled'             => Cast::to_bool_or_null( $raw['canceled'] ?? null ),
			'mobile'               => Cast::to_bool_or_null( $raw['mobile'] ?? null ),
			'sale_deliveries'      => is_array( $raw['sale_deliveries'] ?? null ) ? $raw['sale_deliveries'] : [],
			'customer_snapshot'    => $this->customer_snapshot( $raw ),
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
