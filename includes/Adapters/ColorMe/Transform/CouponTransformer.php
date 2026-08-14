<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalCoupon;

/**
 * `GET /v1/shop_coupons.json` の1要素を `CanonicalCoupon` へ変換する（読取専用。Woo側に再作成）。
 */
final class CouponTransformer {

	/**
	 * @param array<string,mixed> $raw `shop_coupons[]` の1要素。
	 */
	public function transform( array $raw ): CanonicalCoupon {
		$remote_id   = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';
		$coupon_type = Cast::to_string_or_null( $raw['coupon_type'] ?? null );

		$is_free_shipping = 'delivery_charge' === $coupon_type;

		return new CanonicalCoupon(
			Cast::to_string_or_null( $raw['code'] ?? null ) ?? '',
			'rate' === $coupon_type ? 'percent' : 'fixed',
			$is_free_shipping ? '0' : Cast::money( $raw['discount_amount'] ?? null ),
			Cast::to_string_or_null( $raw['minimum_amount'] ?? null ),
			Cast::unix_to_iso( $raw['ends_at'] ?? null ),
			// `total_usage_limit` が発行総数（int）。ColorMeの `usage_limit` フィールドは
			// `indisposable`/`disposable` の1ユーザーあたり上限を表す列挙文字列であり別物
			// （`(int) 'indisposable' === 0` になる罠のため混同しないこと）。
			Cast::to_int_or_null( $raw['total_usage_limit'] ?? null ),
			$this->extras( $raw, $remote_id, $is_free_shipping )
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extras( array $raw, string $remote_id, bool $is_free_shipping ): array {
		return [
			'remote_id'            => $remote_id,
			'name'                 => Cast::to_string_or_null( $raw['name'] ?? null ),
			'free_shipping'        => $is_free_shipping,
			'per_user_usage_limit' => Cast::to_string_or_null( $raw['usage_limit'] ?? null ),
			'starts_at'            => Cast::unix_to_iso( $raw['starts_at'] ?? null ),
			'status'               => Cast::to_string_or_null( $raw['status'] ?? null ),
			'group_limit_type'     => Cast::to_string_or_null( $raw['group_limit_type'] ?? null ),
			'group_ids'            => array_values( array_filter( array_map( [ Cast::class, 'to_string_or_null' ], is_array( $raw['group_ids'] ?? null ) ? $raw['group_ids'] : [] ) ) ),
		];
	}
}
