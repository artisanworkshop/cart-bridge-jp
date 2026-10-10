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
 * （`BETA_*` 定数）。UI は「Beta」表示と既定オフにだけ使い、可否そのもの（`can_push_images`）は従来どおり
 * それぞれの能力が決める。宣言しない外部アダプタの既定は空（ベータなし）。
 *
 * 顧客・受注・クーポンの能力（`can_fetch_customers`・`can_update_customer`・`can_create_order`・`has_coupons`・`can_create_coupon`・
 * 受注のエクスポートのベータ）は R3-6c1 で Pro アドオンの `CommerceCapabilities` へ移した（D27。v1.0 前なので引数の位置の変更は D20 で許容）。
 * 呼び出しは名前付き引数で書く（位置で書くと、引数を外したときに同じ型の能力が黙って入れ替わる）。
 *
 * **`can_push_images` の契約（D24）**: 宣言するアダプタは、実際に画像を送るかを
 * `Support\ExportOptions::push_images_enabled( $this->id() )`（Export タブの「商品画像をアップロードする」。
 * 既定オフ）に従わせること。Export タブの項目・`PUT /settings/export-options`・`Sync\Exporter` の
 * checksum の印は、`can_push_images` が true の全アダプタがこの設定に従う前提で動く。従わないアダプタでは、
 * 設定がオフでも画像が送られ（既定オフが成り立たない）、切り替えるたびに商品が再送されるだけになる
 * （`ColorMeAdapter::should_push_images()` が参照実装）。
 */
final readonly class Capabilities {

	/**
	 * 商品画像のアップロード（`can_push_images`）。
	 */
	public const BETA_IMAGE_PUSH = 'image_push';

	/**
	 * @param mixed $beta_features `BETA_*` 定数の識別子の配列（`array<int,string>`）。外部アダプタ（`cbjp/adapters/register`）
	 *   が返す値は型が実行時に強制されないため、**配列でない値も受け取り**（`array` 型にすると、非配列を渡した
	 *   アダプタの `new Capabilities()` が TypeError で `/connections` ごと落ちる）、`to_array()` が非配列を空配列に、
	 *   配列の中の文字列以外・空文字・重複を落として UI に渡す（原則 8。issue #75）。
	 */
	public function __construct(
		public bool $can_create_category,
		public bool $can_push_images,
		public bool $has_tags,
		public bool $has_reviews,
		public bool $has_variants,
		public int $rate_limit_per_minute,
		public bool $supports_per_variant_stock_management = false,
		public mixed $beta_features = []
	) {}

	/**
	 * @return array<string,bool|int|array<int,string>>
	 */
	public function to_array(): array {
		return [
			'can_create_category'                   => $this->can_create_category,
			'can_push_images'                       => $this->can_push_images,
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
		if ( ! is_array( $this->beta_features ) ) {
			return [];
		}

		$features = [];

		foreach ( $this->beta_features as $feature ) {
			if ( is_string( $feature ) && '' !== $feature ) {
				$features[] = $feature;
			}
		}

		return array_values( array_unique( $features ) );
	}
}
