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
 *
 * 新しい引数は末尾に既定値付きで追加する（D20。外部アダプタが位置引数で`new Capabilities(...)`を
 * 呼びうるため、位置を動かさない）。`supports_per_variant_stock_management`（D22）の既定は`false`:
 * 宣言しない外部アダプタでも、バリエーションごとの在庫管理が混在する商品を止める安全側になる
 * （`Sync\Exporter`。`WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED`）。
 *
 * `beta_features`（D24）は、宣言済みの能力のうち「実店舗で未検証のベータ機能」に当たるものの識別子
 * （`BETA_*` 定数）。UI は「Beta」表示と既定オフにだけ使い、可否そのもの（`can_create_order` /
 * `can_push_images`）は従来どおりそれぞれの能力が決める。宣言しない外部アダプタの既定は空（ベータなし）。
 */
final readonly class Capabilities {

	/**
	 * 受注のエクスポート（`can_create_order`）。
	 */
	public const BETA_ORDER_EXPORT = 'order_export';

	/**
	 * 商品画像のアップロード（`can_push_images`）。
	 */
	public const BETA_IMAGE_PUSH = 'image_push';

	/**
	 * @param array<int,string> $beta_features `BETA_*` 定数の識別子。外部アダプタの戻り値は実行時に型が強制されない
	 *   ため、`to_array()` が文字列以外・空文字・重複を落として UI に渡す（原則 8）。
	 */
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
		public int $rate_limit_per_minute,
		public bool $supports_per_variant_stock_management = false,
		public array $beta_features = []
	) {}

	/**
	 * @return array<string,bool|int|array<int,string>>
	 */
	public function to_array(): array {
		return [
			'can_create_category'                   => $this->can_create_category,
			'can_create_order'                      => $this->can_create_order,
			'can_fetch_customers'                   => $this->can_fetch_customers,
			'can_update_customer'                   => $this->can_update_customer,
			'can_push_images'                       => $this->can_push_images,
			'can_create_coupon'                     => $this->can_create_coupon,
			'has_coupons'                           => $this->has_coupons,
			'has_tags'                              => $this->has_tags,
			'has_reviews'                           => $this->has_reviews,
			'has_variants'                          => $this->has_variants,
			'rate_limit_per_minute'                 => $this->rate_limit_per_minute,
			'supports_per_variant_stock_management' => $this->supports_per_variant_stock_management,
			'beta_features'                         => $this->normalized_beta_features(),
		];
	}

	/**
	 * `beta_features` を UI に渡せる形（重複のない非空文字列の連番配列）へ正規化する。キーが飛んだ配列のまま
	 * `wp_json_encode()` するとJSON配列ではなくオブジェクトになり、UI 側の `.includes()` が落ちるため、
	 * `array_values()` で詰め直す（`RestController::get_connections()` の `connection_fields` と同じ理由）。
	 *
	 * @return array<int,string>
	 */
	private function normalized_beta_features(): array {
		$features = [];

		foreach ( $this->beta_features as $feature ) {
			if ( is_string( $feature ) && '' !== $feature ) {
				$features[] = $feature;
			}
		}

		return array_values( array_unique( $features ) );
	}
}
