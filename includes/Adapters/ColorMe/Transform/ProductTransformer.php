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
 *
 * `sale`と異なり`product.price`（定価）は税込版フィールドを持たないため、`shop.json`の税設定
 * （`tax_type`/`tax`/`reduce_tax_rate`/`tax_rounding_method`）をコンストラクタで受け取る
 * （呼び出し側=ColorMeAdapterが注入する。`OrderTransformer`の名称マップ注入と同じパターン）。
 */
final class ProductTransformer {

	/**
	 * 全て省略可能（デフォルトnull）。未注入の場合は定価の税込換算を行わず、従来どおり
	 * `price = sales_price_including_tax` / `sale_price = null` にフォールバックする。
	 *
	 * @param ?string $shop_tax_type `shop.tax_type`（'excluded'|'included'）。
	 * @param ?int $shop_tax_rate `shop.tax`（標準税率、%）。
	 * @param ?int $shop_reduce_tax_rate `shop.reduce_tax_rate`（軽減税率、%）。
	 * @param ?string $shop_tax_rounding_method `shop.tax_rounding_method`
	 *   （'round_off'|'round_down'|'round_up'）。
	 */
	public function __construct(
		private readonly ?string $shop_tax_type = null,
		private readonly ?int $shop_tax_rate = null,
		private readonly ?int $shop_reduce_tax_rate = null,
		private readonly ?string $shop_tax_rounding_method = null
	) {}

	/**
	 * @param array<string,mixed> $raw `products[]` の1要素、または `product` 単体。
	 */
	public function transform( array $raw ): CanonicalProduct {
		$remote_id              = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';
		[ $price, $sale_price ] = $this->prices( $raw );

		return new CanonicalProduct(
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
			self::sku( $raw, $remote_id ),
			$price,
			$sale_price,
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
	 * Wooの`regular_price`/`sale_price`を決める。販売価格（税込）が正の値で、かつ定価が
	 * それより高い税込換算値を持つ場合のみ「セール中」として両方を出し分け、それ以外
	 * （定価未設定・定価≦販売価格・税設定未注入や未知のenum値で換算不可）は従来どおり
	 * `price = sales_price_including_tax` / `sale_price = null` にフォールバックする
	 * （03 §9 #16）。販売価格が`0`（正規の無料商品）の場合は定価の大小に関わらず出し分けない。
	 * `ProductWriter::resolve_sale_price()`は`sale_price <= 0`を不正として`regular_price`
	 * （＝この場合定価）を有効価格に採用するため、出し分けると無料商品が定価の有料商品に
	 * 化けてしまう（レビュー指摘: PR #24）。
	 *
	 * @param array<string,mixed> $raw
	 * @return array{0:string,1:?string}
	 */
	private function prices( array $raw ): array {
		$sales_price_including_tax = Cast::money( $raw['sales_price_including_tax'] ?? null );
		$sales_price_int           = (int) $sales_price_including_tax;
		$list_price_including_tax  = $this->list_price_including_tax( $raw );

		if ( $sales_price_int > 0 && null !== $list_price_including_tax && $list_price_including_tax > $sales_price_int ) {
			return [ (string) $list_price_including_tax, $sales_price_including_tax ];
		}

		return [ $sales_price_including_tax, null ];
	}

	/**
	 * 定価（`price`）の税込換算額。`price`はswaggerの税込版フィールドが無く、常に
	 * `sales_price`と同じ税基準（`shop.tax_type=excluded`なら税抜）で返る（実機確認済み、
	 * 03 §9 #16）。既知の許可値のみ肯定形で判定し（CLAUDE.mdアーキテクチャ原則9）、
	 * 未知値・欠損は換算不可としてnullを返す。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function list_price_including_tax( array $raw ): ?int {
		$list_price = Cast::to_int_or_null( $raw['price'] ?? null );

		if ( null === $list_price ) {
			return null;
		}

		if ( 'included' === $this->shop_tax_type ) {
			return $list_price;
		}

		if ( 'excluded' !== $this->shop_tax_type ) {
			return null;
		}

		// `tax_reduced`はswaggerのProductスキーマで必須指定されていないフィールドのため、
		// 欠損・非boolean値は「軽減税率対象ではない」ではなく「対象か不明」を意味する。
		// ここを標準税率にフォールバックすると、実際は軽減税率(8%)対象の商品が
		// 標準税率(10%)で換算され、誤った定価・誤ったセール判定を招きかねない
		// （レビュー指摘: PR #24）。既知の真偽値が確定している場合のみ換算する。
		$tax_reduced = Cast::to_bool_or_null( $raw['tax_reduced'] ?? null );

		if ( null === $tax_reduced ) {
			return null;
		}

		$rate = $tax_reduced ? $this->shop_reduce_tax_rate : $this->shop_tax_rate;

		if ( null === $rate ) {
			return null;
		}

		return $this->round_tax( $list_price * ( 100 + $rate ) );
	}

	/**
	 * `shop.tax_rounding_method`に従って端数処理する。`CanonicalProduct::$price`が浮動小数点
	 * 誤差を避けるため文字列で金額を保持する設計（`docs/`各所参照）に揃え、ここも浮動小数点
	 * 除算（`$amount / 100`）を使わず整数演算のみで丸める。未知の方式（欠損含む）は
	 * どの丸め方が正しいか判定できないため、換算自体を諦めてnullを返す
	 * （呼び出し元が現行フォールバックに倒す）。
	 *
	 * @param int $amount 税抜金額 × 100（パーセント表記の税率をそのまま乗じた値）。
	 */
	private function round_tax( int $amount ): ?int {
		return match ( $this->shop_tax_rounding_method ) {
			'round_off' => intdiv( $amount + 50, 100 ),
			'round_down' => intdiv( $amount, 100 ),
			'round_up' => intdiv( $amount + 99, 100 ),
			default => null,
		};
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
	 * キー欠損・明示的nullは「掲載期間の制限無し」という正当な値（swagger必須ではない）だが、
	 * 値が存在するのに`Cast::to_int_or_null()`でパースできない（配列等の不正値）場合、同じnullに
	 * 丸めて「制限無し」と誤判定すると、本来まだ非公開/既に終了しているはずの商品が公開期間外に
	 * 公開されてしまう。両者を区別し、後者は掲載期間外（private）にフェイルクローズする。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function is_within_sale_window( array $raw ): bool {
		$now = time();

		$raw_start = $raw['sale_start_date'] ?? null;
		$start     = Cast::to_int_or_null( $raw_start );

		if ( null !== $raw_start && null === $start ) {
			return false;
		}

		if ( null !== $start && $start > $now ) {
			return false;
		}

		$raw_end = $raw['sale_end_date'] ?? null;
		$end     = Cast::to_int_or_null( $raw_end );

		if ( null !== $raw_end && null === $end ) {
			return false;
		}

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
				// この行を丸ごと読み飛ばすと`$result`が「元々このバリエーションは無かった」ように
				// 見えてしまい、`VariationWriter::sync()`が対応する既存variationをstale（削除対象）
				// と誤認する。同ライターは`remote_id`が解決できないvariantが1件でもあれば
				// スナップショットを不完全とみなしstale削除自体を中止する安全機構を既に持つため、
				// remote_idを欠く空のプレースホルダーを残してその機構を発動させる。
				$result[] = [];
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
	 * カラーミーの `options[]` はバリエーション軸（`variants[].option1/option2`）の定義そのものであり、
	 * `CanonicalProduct::$options`（`ProductWriter`が「非バリエーション属性」として扱う）とは意味が
	 * 異なる。軸として `variants` 側に既に現れている名前をそのまま `options` にも載せると、
	 * `ProductWriter::build_attributes()` が同名の軸と衝突したとみなし、バリエーション商品すべてに
	 * `attribute_name_collision` 警告が付く（テストショップの実機dry-runで判明）。軸名と一致する
	 * ものは除外し、軸として使われていないオプション（`variants` が空の商品など）だけを残す。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function options( array $raw ): array {
		$options = $raw['options'] ?? [];

		if ( ! is_array( $options ) ) {
			return [];
		}

		$axis_names = self::variant_axis_names( $raw );
		$result     = [];

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$name = Cast::to_string_or_null( $option['name'] ?? null ) ?? '';

			if ( in_array( $name, $axis_names, true ) ) {
				continue;
			}

			$values = $option['values'] ?? [];

			$result[] = [
				'name'   => $name,
				'values' => is_array( $values ) ? Cast::strings( $values ) : [],
			];
		}

		return $result;
	}

	/**
	 * `variants[].option1.name` / `option2.name` に現れる軸名の一覧（重複なし・出現順）。
	 * 非配列の行は軸名の収集対象に含めない（`variants()` はその行を空のプレースホルダーとして残し
	 * `VariationWriter` にスナップショット不完全と判定させるが、軸名には寄与しないため、ここでは単に飛ばす）。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<int,string>
	 */
	private static function variant_axis_names( array $raw ): array {
		$variants = $raw['variants'] ?? null;

		if ( ! is_array( $variants ) ) {
			return [];
		}

		$names = [];

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			foreach ( [ 'option1', 'option2' ] as $key ) {
				$name = Cast::to_string_or_null( $variant[ $key ]['name'] ?? null );

				if ( null !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}

		return $names;
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
			// 定価の生値（税抜/税込どちらかは店舗の税設定依存）。税込換算した値は`prices()`が
			// 正規モデルの本体価格/セール価格フィールドへ反映するため、ここは往復移行用の生値保持のみが目的。
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
