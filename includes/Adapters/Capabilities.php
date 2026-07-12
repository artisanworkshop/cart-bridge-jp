<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * プラットフォーム差異の宣言（`docs/03-design-decisions.md` §2 確定版）。
 * UI・JobManagerは false のエンティティ/操作を選択肢から除外し、
 * アダプタ側も非対応メソッドで UnsupportedOperationException を投げる（防御の二重化）。
 */
final readonly class Capabilities {

	public function __construct(
		public bool $can_create_category,
		public bool $can_create_order,
		public bool $can_fetch_customers,
		public bool $can_update_customer,
		public bool $can_push_images,
		public bool $can_create_coupon,
		public bool $has_coupons,
		public bool $has_tags,
		public bool $has_reviews,
		public bool $has_variants,
		public int $rate_limit_per_minute
	) {}

	/**
	 * @return array<string,bool|int>
	 */
	public function to_array(): array {
		return [
			'can_create_category'   => $this->can_create_category,
			'can_create_order'      => $this->can_create_order,
			'can_fetch_customers'   => $this->can_fetch_customers,
			'can_update_customer'   => $this->can_update_customer,
			'can_push_images'       => $this->can_push_images,
			'can_create_coupon'     => $this->can_create_coupon,
			'has_coupons'           => $this->has_coupons,
			'has_tags'              => $this->has_tags,
			'has_reviews'           => $this->has_reviews,
			'has_variants'          => $this->has_variants,
			'rate_limit_per_minute' => $this->rate_limit_per_minute,
		];
	}
}
