<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\CouponTransformer;
use WP_UnitTestCase;

/**
 * `shop_coupons.json` の実フィクスチャは未収集のため、`GET /v1/shop_coupons`
 * のswaggerスキーマ例に基づく合成データで検証する（`tests/fixtures/README.md` の
 * 「実APIレスポンスのみを置く」方針を守るため、フィクスチャファイル化はしない）。
 */
final class CouponTransformerTest extends WP_UnitTestCase {

	private CouponTransformer $transformer;

	public function set_up(): void {
		parent::set_up();
		$this->transformer = new CouponTransformer();
	}

	public function test_amount_type_maps_to_fixed(): void {
		$coupon = $this->transformer->transform(
			$this->raw(
				[
					'coupon_type'     => 'amount',
					'discount_amount' => 100,
				]
			)
		);

		$this->assertSame( 'fixed', $coupon->type );
		$this->assertSame( '100', $coupon->amount );
		$this->assertFalse( $coupon->free_shipping );
		$this->assertFalse( $coupon->extras['free_shipping'] );
	}

	public function test_rate_type_maps_to_percent(): void {
		$coupon = $this->transformer->transform(
			$this->raw(
				[
					'coupon_type'     => 'rate',
					'discount_amount' => 10,
				]
			)
		);

		$this->assertSame( 'percent', $coupon->type );
		$this->assertSame( '10', $coupon->amount );
	}

	public function test_delivery_charge_type_maps_to_zero_fixed_with_free_shipping_flag(): void {
		// WooCommerceのクーポンはfree_shippingをネイティブなフラグとして持つため、$0固定割引に
		// 留めず正規モデル側にも反映する（そうしないと送料無料が機能しないクーポンになる）。
		$coupon = $this->transformer->transform( $this->raw( [ 'coupon_type' => 'delivery_charge' ] ) );

		$this->assertSame( 'fixed', $coupon->type );
		$this->assertSame( '0', $coupon->amount );
		$this->assertTrue( $coupon->free_shipping );
		$this->assertTrue( $coupon->extras['free_shipping'] );
	}

	public function test_total_usage_limit_maps_to_usage_limit_not_the_enum_field(): void {
		$coupon = $this->transformer->transform(
			$this->raw(
				[
					'total_usage_limit' => 10,
					'usage_limit'       => 'indisposable',
				]
			)
		);

		// usage_limit は enum 文字列。intキャストすると0になる罠があるため誤用していないか確認する。
		$this->assertSame( 10, $coupon->usage_limit );
		$this->assertSame( 'indisposable', $coupon->extras['per_user_usage_limit'] );
		// indisposable（無制限）はWooのusage_limit_per_userを設定しない（null）。
		$this->assertNull( $coupon->usage_limit_per_user );
	}

	public function test_disposable_usage_limit_maps_to_woo_per_user_limit_of_one(): void {
		// WooCommerceのクーポンはusage_limit_per_userをネイティブなフラグとして持つため、
		// disposable（1人1回）はここに反映しないと同一顧客が繰り返し利用できてしまう。
		$coupon = $this->transformer->transform( $this->raw( [ 'usage_limit' => 'disposable' ] ) );

		$this->assertSame( 1, $coupon->usage_limit_per_user );
	}

	public function test_group_restricted_coupon_is_excluded(): void {
		// WooCommerceのクーポン制限はタグ単位に対応しておらず、Transformer層だけではColorMeの
		// グループ→商品ID一覧の展開もできない。そのまま作ると意図せず全商品対象の割引券になり
		// 金銭的リスクがあるため除外する（group_limit_type/group_idsはextrasに保持済み）。
		$this->assertNull( $this->transformer->transform( $this->raw( [ 'group_limit_type' => 'including' ] ) ) );
		$this->assertNull( $this->transformer->transform( $this->raw( [ 'group_limit_type' => 'excluding' ] ) ) );
	}

	public function test_unavailable_coupon_is_excluded(): void {
		// CanonicalCouponには有効/無効を表すフィールドが無いため、店舗側で手動無効化された
		// クーポンをそのまま変換するとWoo側でコードが再び使用可能になってしまう。
		$coupon = $this->transformer->transform( $this->raw( [ 'status' => 'unavailable' ] ) );

		$this->assertNull( $coupon );
	}

	public function test_group_id_of_zero_is_not_dropped_from_extras(): void {
		// コールバック無しのarray_filterは'0'のようなfalsy文字列も落ちてしまう罠の回帰テスト。
		$coupon = $this->transformer->transform( $this->raw( [ 'group_ids' => [ 0, 1 ] ] ) );

		$this->assertSame( [ '0', '1' ], $coupon->extras['group_ids'] );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function raw( array $overrides ): array {
		return array_merge(
			[
				'id'                => 187239,
				'name'              => '100円割引',
				'code'              => 'discount100',
				'coupon_type'       => 'amount',
				'discount_amount'   => 50,
				'minimum_amount'    => 1000,
				'starts_at'         => 1782864000,
				'ends_at'           => 1785542400,
				'total_usage_limit' => 10,
				'usage_limit'       => 'disposable',
				'group_limit_type'  => 'none',
				'status'            => 'available',
				'group_ids'         => [ 1, 2, 3 ],
			],
			$overrides
		);
	}
}
