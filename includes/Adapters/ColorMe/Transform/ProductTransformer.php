<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalProduct;
use RuntimeException;

/**
 * `GET /v1/products.json` `GET /v1/products/{id}.json` の1要素を `CanonicalProduct` へ変換する。
 * マッピングの詳細は `docs/01-plan-colorme.md` §4。
 */
final class ProductTransformer {

	/**
	 * @param array<string,mixed> $raw `products[]` の1要素、または `product` 単体。
	 */
	public function transform( array $raw ): CanonicalProduct {
		$remote_id = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';

		return new CanonicalProduct(
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
			self::sku( $raw, $remote_id ),
			Cast::money( $raw['sales_price_including_tax'] ?? null ),
			null,
			Cast::sanitize_html( $raw['expl'] ?? null ),
			$this->images( $raw ),
			$this->variants( $raw, $remote_id ),
			$this->options( $raw ),
			$this->category_refs( $raw ),
			$this->stock( $raw ),
			$this->status( $raw ),
			$this->extras( $raw, $remote_id ),
			$this->requires_shipping( $raw ),
			$this->tag_refs( $raw ),
			Cast::to_int_or_null( $raw['weight'] ?? null ),
			$this->tax_class( $raw )
		);
	}

	/**
	 * `display_state` の4値のうち `sale_for_members`（掲載状態だが購入は会員のみ可能）は
	 * `showing` と同じく一般公開の掲載状態であり、`private` にすると誰にも見えなくなってしまう
	 * （swagger: 「掲載状態だが購入は会員のみ可能」）。購入制限自体はWoo標準機能で表現できないため
	 * extras の生の `display_state` に委ね、ここではWooの掲載可否のみを判定する。
	 *
	 * 一方、以下の2つは「今この瞬間に一般公開すべきでない」ケースであり、sale_for_membersとは異なり
	 * privateにしても元のASP側の意図を損なわない（誰に対しても非公開が正しい）ため、display_stateに
	 * 優先してprivateにする:
	 * - `regular_purchase`（定期購入商品）: Wooコアにはサブスクリプションの仕組みが無く、通常商品として
	 *   公開すると一回限りの購入として売れてしまい、定期収益や継続提供の前提が崩れる。F1-4がサブスク
	 *   対応を実装するまでの暫定処置
	 * - `sale_start_date`/`sale_end_date`（掲載期間）: 期間外は本来ColorMe側でも非公開になる想定の
	 *   時限公開設定
	 * - `soldout_display: false`（売り切れ時非表示）かつ在庫管理中で在庫が0: 店舗側が明示的に
	 *   「売り切れたら表示しない」と設定している以上、privateにしても元のASP側の意図を損なわない
	 * - `sales_price_including_tax`が欠損／非数値: `Cast::money()`は解釈できない値を無言で`'0'`に
	 *   丸めるため、区別なく通すと本来有料の商品が無料商品としてWoo側で購入可能になってしまう
	 * - `digital_content`（デジタルコンテンツ）: `requires_shipping()`でWooのvirtual商品設定には
	 *   反映するが、`CanonicalProduct`にはダウンロード対象ファイル自体を運ぶフィールドが無い。
	 *   このまま公開すると、購入者が代金を支払ってもダウンロード可能な実体が届かない通常の
	 *   virtual商品として売れてしまうため、ダウンロード資産の移行手段が整うまでprivateに留める
	 *
	 * @param array<string,mixed> $raw
	 */
	private function status( array $raw ): string {
		if ( true === ( $raw['regular_purchase'] ?? null ) ) {
			return 'private';
		}

		if ( true === ( $raw['digital_content'] ?? null ) ) {
			return 'private';
		}

		if ( ! $this->is_within_sale_window( $raw ) ) {
			return 'private';
		}

		if ( $this->is_hidden_while_sold_out( $raw ) ) {
			return 'private';
		}

		if ( $this->has_unparseable_price( $raw ) ) {
			return 'private';
		}

		$display_state = $raw['display_state'] ?? null;

		return in_array( $display_state, [ 'showing', 'sale_for_members' ], true ) ? 'publish' : 'private';
	}

	/**
	 * `sales_price_including_tax: 0`（正規の無料商品）と、欠損・非数値値が`Cast::money()`で
	 * 丸められた結果の`'0'`は下流で見分けが付かない。数値として解釈できない場合のみtrueを返す。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function has_unparseable_price( array $raw ): bool {
		return null === Cast::to_int_or_null( $raw['sales_price_including_tax'] ?? null );
	}

	/**
	 * `soldout_display`はswaggerで必須のbooleanフィールド（デフォルト値はドキュメント上）だが、
	 * 値が欠損している場合は挙動を変えないよう `false`（非表示設定）と明示された場合のみ扱う。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function is_hidden_while_sold_out( array $raw ): bool {
		if ( ! self::is_stock_managed( $raw ) ) {
			return false;
		}

		if ( false !== ( $raw['soldout_display'] ?? null ) ) {
			return false;
		}

		$stocks = Cast::to_int_or_null( $raw['stocks'] ?? null );

		return null !== $stocks && $stocks <= 0;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function is_within_sale_window( array $raw ): bool {
		$now = time();

		$start = Cast::to_int_or_null( $raw['sale_start_date'] ?? null );

		if ( null !== $start && $start > $now ) {
			return false;
		}

		$end = Cast::to_int_or_null( $raw['sale_end_date'] ?? null );

		if ( null !== $end && $end < $now ) {
			return false;
		}

		return true;
	}

	/**
	 * `without_shipping`（配送不要商品）・`digital_content`（デジタルコンテンツ）はWooのネイティブな
	 * virtual商品設定（配送先住所を要求せず送料もかけない）に対応する。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function requires_shipping( array $raw ): bool {
		return true !== ( $raw['without_shipping'] ?? null ) && true !== ( $raw['digital_content'] ?? null );
	}

	/**
	 * `StockTransformer`（F1-5）も同じ規則でSKUを導出する必要があるため公開する
	 * （在庫を別商品に誤って当てないよう、SKU導出ルールを1箇所にまとめる）。
	 *
	 * @param array<string,mixed> $raw
	 */
	public static function sku( array $raw, string $remote_id ): string {
		$model_number = Cast::to_string_or_null( $raw['model_number'] ?? null );

		return null !== $model_number ? $model_number : "colorme-{$remote_id}";
	}

	/**
	 * `stock_managed: false`（在庫管理しない設定）の場合、`stocks`の値は在庫切れ判定に使わない。
	 * `Importer::run_sample_stock_page()`はstockがnullの商品を「在庫あり」として扱うため
	 * （`includes/Sync/Importer.php`参照）、管理外の商品に `stocks: 0` 等をそのまま渡すと
	 * 購入可能な商品を誤って在庫切れにしてしまう。
	 *
	 * 逆に `stock_managed: true`（在庫管理する設定）で `stocks` が欠損・非数値の場合、
	 * `Cast::to_int_or_null()` は `null` を返すが、それをそのまま返すと上記と同じ
	 * `Importer` の「stock=nullは在庫あり」判定に乗ってしまい、実際は売り切れかもしれない
	 * 在庫管理商品を無条件に購入可能にしてしまう。在庫管理対象なのに実数が不明な場合は
	 * `0`（在庫切れ）にフェイルクローズする。
	 *
	 * `StockTransformer`（F1-5）も商品レベルの在庫数導出にこのメソッドを再利用するため公開する。
	 *
	 * @param array<string,mixed> $raw
	 */
	public static function stock( array $raw ): ?int {
		if ( ! self::is_stock_managed( $raw ) ) {
			return null;
		}

		return Cast::to_int_or_null( $raw['stocks'] ?? null ) ?? 0;
	}

	/**
	 * `stock_managed`は商品レベルのみに存在するフラグ（swaggerのvariantスキーマには無い）。
	 * バリエーションの在庫可否も、この商品レベルの設定に従わせる。
	 *
	 * 欠損・非数値の場合は「管理外（在庫あり扱い）」ではなく「管理中（実数不明）」とみなす。
	 * 前者に倒すと`stock()`が`null`を返し、`Importer::run_sample_stock_page()`がそれを
	 * 「在庫あり」と解釈するため、実際は管理対象で売り切れかもしれない商品が無条件に
	 * 購入可能になってしまう。`stock()`側の0（在庫切れ）フェイルクローズに委ねる。
	 *
	 * `StockTransformer`（F1-5）も在庫管理判定にこのメソッドを再利用するため公開する。
	 *
	 * @param array<string,mixed> $raw
	 */
	public static function is_stock_managed( array $raw ): bool {
		return false !== ( $raw['stock_managed'] ?? null );
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function images( array $raw ): array {
		$images = [];

		$main_image = Cast::to_string_or_null( $raw['image_url'] ?? null );

		if ( null !== $main_image ) {
			$images[] = [
				'src'      => $main_image,
				'position' => 0,
			];
		}

		$extra_images = $raw['images'] ?? [];

		if ( is_array( $extra_images ) ) {
			foreach ( $extra_images as $image ) {
				// `mobile: true` の項目はPC用画像と同一内容を指すモバイル向けの重複エントリのため除外する。
				if ( ! is_array( $image ) || true === ( $image['mobile'] ?? null ) ) {
					continue;
				}

				$src = Cast::to_string_or_null( $image['src'] ?? null );

				if ( null === $src ) {
					continue;
				}

				$images[] = [
					'src'      => $src,
					'position' => Cast::to_int_or_null( $image['position'] ?? null ),
				];
			}
		}

		return $images;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function variants( array $raw, string $product_remote_id ): array {
		$variants = $raw['variants'] ?? null;

		// `variants`はColorMe APIが常に配列で返すフィールド（バリエーション無しの商品も`[]`）。
		// 欠損・非配列は正当な「バリエーション無し」ではなくスキーマ崩壊等の不完全なレスポンスであり、
		// ここで`[]`にフォールバックすると`ProductWriter`が「variable→simpleへの意図的な変更」と
		// 誤認し、既存商品のバリエーションを破壊的に全削除してしまう（CLAUDE.md 破壊的操作の禁止）。
		// 例外を投げてこの行の変換自体を失敗させ、`ColorMeAdapter::transform_rows_flat()`の
		// per-row catchでこの行だけをスキップさせる（Woo側の既存データには一切触れない）。
		if ( ! is_array( $variants ) ) {
			throw new RuntimeException( 'ColorMe product row is missing a valid "variants" array.' );
		}

		$product_price = Cast::money( $raw['sales_price_including_tax'] ?? null );
		$stock_managed = self::is_stock_managed( $raw );
		$result        = [];

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$variant_remote_id = self::variant_remote_id( $variant ) ?? '';
			$price             = Cast::to_string_or_null( $variant['option_price_including_tax'] ?? null );

			$result[] = [
				'remote_id'                   => $variant_remote_id,
				'sku'                         => self::variant_sku( $variant, $product_remote_id ),
				'option1_name'                => Cast::to_string_or_null( $variant['option1']['name'] ?? null ),
				'option1_value'               => Cast::to_string_or_null( $variant['option1']['value'] ?? $variant['option1_value'] ?? null ),
				'option2_name'                => Cast::to_string_or_null( $variant['option2']['name'] ?? null ),
				'option2_value'               => Cast::to_string_or_null( $variant['option2']['value'] ?? $variant['option2_value'] ?? null ),
				'price'                       => null !== $price ? $price : $product_price,
				// 在庫管理中で欠損・非数値の場合は`stock()`と同じ理由で0（在庫切れ）にフェイルクローズする
				// （Woo writerはnullを「在庫管理外＝在庫あり」と解釈するため）。
				'stock'                       => self::variant_stock( $variant, $stock_managed ),
				'weight'                      => Cast::to_int_or_null( $variant['weight'] ?? null ),
				// バリエーション別の上書き値。商品レベルの同種項目はextrasに退避済み（few_num/cost/
				// members_price_including_tax）だが、ここではバリエーションごとの値をそのまま保持する。
				'few_num'                     => Cast::to_int_or_null( $variant['few_num'] ?? null ),
				'cost'                        => Cast::to_int_or_null( $variant['option_cost'] ?? null ),
				'members_price_including_tax' => Cast::to_int_or_null( $variant['option_members_price_including_tax'] ?? null ),
				'market_price'                => Cast::to_int_or_null( $variant['option_market_price'] ?? null ),
			];
		}

		return $result;
	}

	/**
	 * `StockTransformer`（F1-5）も同じ規則でバリエーションSKUを導出する必要があるため公開する。
	 *
	 * @param array<string,mixed> $variant
	 */
	public static function variant_sku( array $variant, string $product_remote_id ): string {
		$model_number      = Cast::to_string_or_null( $variant['model_number'] ?? null );
		$variant_remote_id = self::variant_remote_id( $variant ) ?? '';

		return null !== $model_number ? $model_number : "colorme-{$product_remote_id}-{$variant_remote_id}";
	}

	/**
	 * `StockTransformer`（F1-5）も同じ規則でバリエーションのremote_idを解決する必要があるため公開する。
	 * `id`欠損時は`''`ではなく`null`を返す。空文字を返すと`CanonicalStock::remote_id()`の
	 * `variant_ref ?? product_ref`フォールバックが効かず（`??`はnullのみを未設定とみなす）、
	 * id欠損の複数バリエーションが同一の空remote_idに衝突してしまう。
	 *
	 * @param array<string,mixed> $variant
	 */
	public static function variant_remote_id( array $variant ): ?string {
		return Cast::to_string_or_null( $variant['id'] ?? null );
	}

	/**
	 * `StockTransformer`（F1-5）も同じ規則でバリエーション在庫数を導出する必要があるため公開する。
	 *
	 * @param array<string,mixed> $variant
	 */
	public static function variant_stock( array $variant, bool $stock_managed ): ?int {
		return $stock_managed ? ( Cast::to_int_or_null( $variant['stocks'] ?? null ) ?? 0 ) : null;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function options( array $raw ): array {
		$options = $raw['options'] ?? [];

		if ( ! is_array( $options ) ) {
			return [];
		}

		$result = [];

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$values = $option['values'] ?? [];

			$result[] = [
				'name'   => Cast::to_string_or_null( $option['name'] ?? null ) ?? '',
				'values' => is_array( $values ) ? Cast::strings( $values ) : [],
			];
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,string>
	 */
	private function category_refs( array $raw ): array {
		$category = $raw['category'] ?? null;

		if ( ! is_array( $category ) ) {
			return [];
		}

		$ref = Cast::category_ref( $category['id_big'] ?? null, $category['id_small'] ?? null );

		return null !== $ref ? [ $ref ] : [];
	}

	/**
	 * `group_ids`は `docs/01-plan-colorme.md` の対応表通り `TagTransformer` が作るタグの
	 * remote_id一覧としてそのまま使える（groupsのidがタグのremote_id）。ここでextrasだけに
	 * 留めると、アダプタ非依存のWoo writerが商品とタグを紐付けられなくなる。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<int,string>
	 */
	private function tag_refs( array $raw ): array {
		return Cast::strings( is_array( $raw['group_ids'] ?? null ) ? $raw['group_ids'] : [] );
	}

	/**
	 * `tax_reduced`（軽減税率対象商品かどうか）をWooのネイティブな税区分（`_tax_class`）に
	 * マッピングする。`reduced-rate`はWooが標準インストール時から用意する追加税区分のスラッグ
	 * （`sanitize_title( __( 'Reduced rate', 'woocommerce' ) )`）。ここでextrasだけに留めると、
	 * アダプタ非依存のWoo writerが軽減税率対象商品にも標準税率を適用してしまい、税額を過大計算しうる。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function tax_class( array $raw ): ?string {
		return true === Cast::to_bool_or_null( $raw['tax_reduced'] ?? null ) ? 'reduced-rate' : null;
	}

	/**
	 * ASP固有フィールドの退避先。`account_id`・`make_date`・`update_date`（ネスト含む）は
	 * checksumを内容ベースに保つため含めない。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extras( array $raw, string $remote_id ): array {
		return [
			'remote_id'                   => $remote_id,
			'display_state'               => Cast::to_string_or_null( $raw['display_state'] ?? null ),
			// `simple_expl`（簡易説明）は `CanonicalProduct` に専用フィールドが無いためextras経由でWoo抜粋に渡す。
			'short_description'           => Cast::sanitize_html( $raw['simple_expl'] ?? null ),
			// `smartphone_expl`（スマートフォン向け説明文）はPC向け`expl`とは別内容になり得るため、
			// 本体の説明文フィールドを上書きせずextras経由で退避する。
			'smartphone_description'      => Cast::sanitize_html( $raw['smartphone_expl'] ?? null ),
			// タグ紐付け自体は `tag_refs()`（正規モデルの `tag_refs`）が担う。ここではASP側の生値の保持のみが目的。
			'group_ids'                   => Cast::strings( is_array( $raw['group_ids'] ?? null ) ? $raw['group_ids'] : [] ),
			// 定価（税抜/税込どちらか不明）。Transformerはショップの税設定を持たないため税込換算を作らずそのまま退避する。
			'list_price'                  => Cast::to_int_or_null( $raw['price'] ?? null ),
			'members_price_including_tax' => Cast::to_int_or_null( $raw['members_price_including_tax'] ?? null ),
			'tax_reduced'                 => Cast::to_bool_or_null( $raw['tax_reduced'] ?? null ),
			'stock_managed'               => Cast::to_bool_or_null( $raw['stock_managed'] ?? null ),
			'few_num'                     => Cast::to_int_or_null( $raw['few_num'] ?? null ),
			'weight'                      => Cast::to_int_or_null( $raw['weight'] ?? null ),
			'unit'                        => Cast::to_string_or_null( $raw['unit'] ?? null ),
			'min_num'                     => Cast::to_int_or_null( $raw['min_num'] ?? null ),
			'max_num'                     => Cast::to_int_or_null( $raw['max_num'] ?? null ),
			// 商品一覧での表示順（数値が小さいほど先頭）。F1-4がWooのmenu_orderへマッピングする想定。
			'sort'                        => Cast::to_int_or_null( $raw['sort'] ?? null ),
			'cost'                        => Cast::to_int_or_null( $raw['cost'] ?? null ),
			'delivery_charge'             => Cast::to_int_or_null( $raw['delivery_charge'] ?? null ),
			'cool_charge'                 => Cast::to_int_or_null( $raw['cool_charge'] ?? null ),
			'unavailable_payment_ids'     => Cast::strings( is_array( $raw['unavailable_payment_ids'] ?? null ) ? $raw['unavailable_payment_ids'] : [] ),
			'unavailable_delivery_ids'    => Cast::strings( is_array( $raw['unavailable_delivery_ids'] ?? null ) ? $raw['unavailable_delivery_ids'] : [] ),
			// おすすめ商品種別・表示順。ストアフロントの特集/おすすめ枠設定。
			'pickups'                     => $this->pickups( $raw ),
			'memo'                        => Cast::to_string_or_null( $raw['memo'] ?? null ),
			'sale_start_date'             => Cast::unix_to_iso( $raw['sale_start_date'] ?? null ),
			'sale_end_date'               => Cast::unix_to_iso( $raw['sale_end_date'] ?? null ),
			'soldout_display'             => Cast::to_bool_or_null( $raw['soldout_display'] ?? null ),
			'without_shipping'            => Cast::to_bool_or_null( $raw['without_shipping'] ?? null ),
			'digital_content'             => Cast::to_bool_or_null( $raw['digital_content'] ?? null ),
			'regular_purchase'            => Cast::to_bool_or_null( $raw['regular_purchase'] ?? null ),
			// 「限定公開」フラグ（display_stateとは独立）。Wooのカタログ表示設定（visible/hidden等）に
			// マッピングする想定でF1-4向けに退避する。
			'unlisted'                    => Cast::to_bool_or_null( $raw['unlisted'] ?? null ),
		];
	}

	/**
	 * `product_id`/`account_id`は変換対象の商品自身を指すだけの冗長情報、`make_date`/`update_date`は
	 * 実質的な設定変更を伴わずに変動し得る揮発性のタイムスタンプ。生のまま保持すると
	 * `CanonicalProduct::checksum()` がextras全体をハッシュするため、意味のない更新のたびに
	 * checksumが変わり、実際は変わっていないWoo商品を毎回書き込み直してしまう。意味のある
	 * フィールド（種別・表示順）のみ抽出する。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function pickups( array $raw ): array {
		$pickups = $raw['pickups'] ?? [];

		if ( ! is_array( $pickups ) ) {
			return [];
		}

		$result = [];

		foreach ( $pickups as $pickup ) {
			if ( ! is_array( $pickup ) ) {
				continue;
			}

			$result[] = [
				'pickup_type' => Cast::to_int_or_null( $pickup['pickup_type'] ?? null ),
				'order_num'   => Cast::to_int_or_null( $pickup['order_num'] ?? null ),
			];
		}

		return $result;
	}
}
