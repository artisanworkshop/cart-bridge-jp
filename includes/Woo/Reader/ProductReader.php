<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\StockDerivation;
use CartBridgeJP\Woo\Support\TaxInclusivePrice;
use CartBridgeJP\Woo\Support\VariationAxisResolver;
use CartBridgeJP\Woo\Support\WeightUnit;
use CartBridgeJP\Woo\WarningCode;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * `WC_Product`（+バリエーション）を `CanonicalProduct` へ変換する（`Woo\Writer\ProductWriter` の
 * 読出側対称形）。カテゴリはこのプラグインの`cbjp_mappings`（ASP→Woo作成時の紐付け）ではなく
 * `category_map`（Woo側カテゴリID→ASP側カテゴリID。D19）で解決する: カラーミーは
 * カテゴリ作成不可（`can_create_category=false`）なため、エクスポートは常に既存ASPカテゴリへの
 * 紐付けであり、`cbjp_mappings`の`category`entity自体がColorMeでは作られない。
 */
final class ProductReader implements EntityReader {

	private const PAGE_SIZE = 20;

	/**
	 * このReaderが対象にする商品ステータス/タイプ。`Sync\ExportSampleSelector`が
	 * 受注明細から抽出した商品IDをサンプル枠として消費する前に、この条件で絞り込む
	 * 必要がある（合わない商品はこの`query()`のページに現れず、サンプル枠だけを
	 * 消費して結果に反映されない「消えた枠」になるため）。
	 */
	public const EXPORTABLE_STATUSES = [ 'publish', 'private', 'draft' ];
	public const EXPORTABLE_TYPES    = [ 'simple', 'variable' ];

	/**
	 * 商品ごとに積むと結果が埋もれるためインスタンス（=1ページ走査）につき1回だけ
	 * `PRICES_CONVERTED_TO_TAX_INCLUSIVE`を積む。
	 */
	private bool $prices_converted_warned = false;

	public function __construct(
		private readonly string $platform,
		private readonly MethodMap $method_map,
		private readonly MappingRepository $mappings
	) {}

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$args = [
			'status'   => self::EXPORTABLE_STATUSES,
			'type'     => self::EXPORTABLE_TYPES,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'objects',
			'paginate' => true,
			'limit'    => self::PAGE_SIZE,
			'page'     => (int) $cursor->get( 'page', 1 ),
		];

		if ( null !== $only_local_ids ) {
			if ( [] === $only_local_ids ) {
				return new ReadPage( [], null, 0 );
			}

			$args['include'] = $only_local_ids;
			// サンプルモードはID指定取得であり `include` がそのまま対象総数を決めるため、
			// ページングは不要（1ページで確定する）。
			unset( $args['page'] );
			$args['limit'] = count( $only_local_ids );
		}

		/** @var object{products:array<int,WC_Product>,total:int,max_num_pages:int} $result */
		$result = wc_get_products( $args );

		// Wooの商品データストアは復元に失敗した投稿をarray_filterで除外するため、
		// $result->products のキーが飛び番になりうる。array_values() で
		// ReadPage::$items の array<int,ReadItem> 契約（0始まり連番）に揃える。
		$items = array_values( array_map( fn ( WC_Product $product ): ReadItem => $this->to_read_item( $product ), $result->products ) );

		$page          = (int) ( $args['page'] ?? 1 );
		$has_next_page = null === $only_local_ids && $page < $result->max_num_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, $result->total );
	}

	private function to_read_item( WC_Product $product ): ReadItem {
		$warnings          = [];
		$is_variable       = $product instanceof WC_Product_Variable;
		$axis_names        = $is_variable ? $this->variation_axis_attributes( $product, $warnings ) : [];
		$variant_local_ids = [];
		$variants          = $is_variable ? $this->variants( $product, $axis_names, $warnings, $variant_local_ids ) : [];

		if ( $is_variable && [] === $variants ) {
			// Copilot指摘（PR #40）: 全バリエーションが除外された（価格無効・非公開等）ため
			// `variants`が空になった場合、`Woo\Writer\ProductWriter::prepare()`は空の`variants`を
			// 「simple商品」の判定に使う。無警告のままだとE2-3以降の変換先でvariable商品が
			// simpleとして扱われうる。
			$warnings[] = WarningCode::ALL_VARIATIONS_EXCLUDED;
		}

		$images                                  = $this->images( $product );
		$options                                 = $this->options( $product, $axis_names );
		[ $category_refs, $category_warnings ]   = $this->category_refs( $product );
		$warnings                                = array_merge( $warnings, $category_warnings );
		[ $price, $sale_price, $price_warnings ] = $is_variable
			? $this->price_fields_for_variable( $product )
			: $this->price_fields_for_simple( $product );
		$warnings                                = array_merge( $warnings, $price_warnings );

		$weight    = WeightUnit::convert_to_grams( (string) $product->get_weight() );
		$tax_class = $product->get_tax_class();

		// Codex指摘（PR #40, G3）: `get_tax_class()`は`tax_status`（`taxable`/`shipping`/`none`）を
		// 運ばない。`CanonicalProduct`にも`tax_status`を持つフィールドが無いため、送料のみ課税・
		// 非課税の商品を無警告でexportすると変換先で通常課税として扱われうる。
		if ( 'taxable' !== $product->get_tax_status() ) {
			$warnings[] = WarningCode::TAX_STATUS_NOT_TAXABLE;
		}

		$canonical = new CanonicalProduct(
			$product->get_name(),
			'' !== $product->get_sku() ? $product->get_sku() : null,
			$price,
			$sale_price,
			'' !== $product->get_description() ? $product->get_description() : null,
			$images,
			$variants,
			$options,
			$category_refs,
			$is_variable ? null : $this->stock( $product ),
			'publish' === $product->get_status() ? 'publish' : 'private',
			$this->extras( $product ),
			! $product->is_virtual(),
			[],
			$weight,
			'' !== $tax_class ? $tax_class : null
		);

		return new ReadItem(
			$product->get_id(),
			$canonical,
			$warnings,
			! WarningCode::indicates_unresolved_reference( $warnings ),
			$variant_local_ids
		);
	}

	/**
	 * `ProductWriter::prepare()`がインポート時に適用する`extras`のうち、`WC_Product`から
	 * 直接読み戻せるもの（往復のデータ欠損を防ぐ。CanonicalProduct docblock参照）。
	 * ColorMe固有の数値extras（few_num/cost/market_price/members_price_including_tax）は
	 * Woo側に対応する値の置き場が無いためPR-Aでは対象外（`docs/review-backlog.md`参照）。
	 *
	 * @return array<string,mixed>
	 */
	private function extras( WC_Product $product ): array {
		$short_description = $product->get_short_description();

		return [
			'short_description' => '' !== $short_description ? $short_description : null,
			'sort'              => $product->get_menu_order(),
			'unlisted'          => 'hidden' === $product->get_catalog_visibility(),
		];
	}

	/**
	 * 在庫管理対象なのに数量が不明な場合は0（CLAUDE.md: `null`は「在庫管理外」を意味するため、
	 * 誤って「在庫あり」と解釈されないようフェイルクローズする）。数量管理していない商品も
	 * `_stock_status`で明示的に「在庫切れ」にされている場合は0にする（`null`＝在庫ありへの
	 * 誤変換を避ける。手動でstock_statusだけ切り替える運用はmanage_stock=falseのままでも一般的）。
	 */
	private function stock( WC_Product $product ): ?int {
		return StockDerivation::for_product( $product );
	}

	/**
	 * variable親の価格フィールド。個々のバリエーション自身の価格は`variants()`が別途持つ。
	 *
	 * @return array{0:string,1:?string,2:array<int,string>}
	 */
	private function price_fields_for_variable( WC_Product_Variable $product ): array {
		// 親の`_regular_price`/`_sale_price`は`WC_Product_Variable_Data_Store_CPT::sync_price()`が
		// 保存の度に削除する（親は`_price`にバリエーションの価格帯のみ複数値で保持する）ため、
		// 常に空文字列になる（実測確認済み）。無条件に読むと全variable商品が0円でエクスポートされて
		// しまう（金銭的リスク）ため、バリエーションの最安「定価」を代表値として使う。
		// `get_variation_price()`（実効価格＝セール中はセール価格）ではなく
		// `get_variation_regular_price()`を使う: 期間限定セールがASP側に定価として恒久的に
		// 焼き付くのを避けるため（`sale_price`はH3と同じ理由でここでは扱わない＝常にnull）。
		// 公開かつ（設定次第で）在庫ありのバリエーションが1件も無い場合、PHPの
		// `current( [] )`規約（Wooの規約ではない）により**bool `false`**が返る
		// （`WC_Product_Variable_Data_Store_CPT::read_price_data()`の`get_visible_children()`が
		// 空集合になるケース。全バリエーション非公開、または「在庫切れ商品を除外」設定＋
		// 全バリエーション在庫切れ等で起こりうる）。
		// `CanonicalProduct::$price`は`declare(strict_types=1)`下の非nullable `string` のため、
		// この`false`をそのまま渡すと`TypeError`でページ全体（Exporterの1件catchの外側で
		// 発生するため他の商品を含むページ全体）が失敗し、再試行しても同じ商品で永久に
		// 失敗し続ける。単純商品の価格欠損と同じくフェイルクローズし警告を積む。
		$min_price = $product->get_variation_regular_price( 'min', false );

		// Copilot指摘（PR #40）: 空文字列以外なら無条件に受理していたため、破損した
		// `_regular_price`メタ（非数値・負の値）を持つバリエーションが最安値として
		// 選ばれた場合にそのまま親の代表価格へ伝播しうる。`variants()`側の検証と同じ基準
		// （数値・0以上）で受理する。
		if ( ! is_string( $min_price ) || '' === $min_price || ! is_numeric( $min_price ) || (float) $min_price < 0 ) {
			return [ '0', null, [ WarningCode::PRODUCT_PRICE_INVALID ] ];
		}

		// 親の代表値は親商品の税区分で税込へ換算する（バリエーション個別の税区分が親と異なる場合の
		// 代表値の厳密さは対象外。`tax_reduced`も商品単位でしかASPへ運べない）。
		$warnings  = [];
		$inclusive = $this->normalize_price( $product, $min_price, $warnings );

		if ( null === $inclusive ) {
			return [ '0', null, [ WarningCode::PRODUCT_PRICE_INVALID, WarningCode::PRICE_TAX_BASIS_UNRESOLVED ] ];
		}

		return [ $inclusive, null, $warnings ];
	}

	/**
	 * 価格をCanonicalの価格契約（税込）へ正規化する。税抜入力の店舗で換算を適用したときだけ、
	 * ページにつき1回`PRICES_CONVERTED_TO_TAX_INCLUSIVE`を`$warnings`へ積む。
	 *
	 * @param array<int,string> $warnings
	 * @return ?string 税込金額。換算不能（`Woo\Support\TaxInclusivePrice`参照）ならnull。
	 */
	private function normalize_price( WC_Product $product, string $amount, array &$warnings ): ?string {
		[ $inclusive, $converted ] = TaxInclusivePrice::from_product_price( $product, $amount );

		if ( $converted && ! $this->prices_converted_warned ) {
			$warnings[]                    = WarningCode::PRICES_CONVERTED_TO_TAX_INCLUSIVE;
			$this->prices_converted_warned = true;
		}

		return $inclusive;
	}

	/**
	 * セール中の実売価格（換算前の生の値）。`get_sale_price()`は生の`_sale_price`をそのまま返し、
	 * セール開始/終了日程を考慮しない（`is_on_sale()`のみが日程を見る）。`edit`コンテキストで
	 * 表示用フィルターを経由せず判定し、`ProductWriter::resolve_sale_price()`（インポート方向）と
	 * 同じ基準（数値・0より大きい・通常価格未満）で検証する。満たさない場合はセールなし（null）。
	 * 「通常価格未満」は`is_on_sale()`自身も見ており重複する防御だが、「0より大きい」（0円のセール価格は
	 * `is_on_sale()`が真でもセール扱いにしない）と数値検証はこちらだけが担う。
	 *
	 * @param string $regular_price 検証済みの通常価格（換算前の生の値）。
	 */
	private function valid_sale_price( WC_Product $product, string $regular_price ): ?string {
		if ( ! $product->is_on_sale( 'edit' ) ) {
			return null;
		}

		$raw_sale_price = $product->get_sale_price();

		if ( is_numeric( $raw_sale_price ) && (float) $raw_sale_price > 0 && (float) $raw_sale_price < (float) $regular_price ) {
			return $raw_sale_price;
		}

		return null;
	}

	/**
	 * 単純商品の価格フィールド。
	 *
	 * @return array{0:string,1:?string,2:array<int,string>}
	 */
	private function price_fields_for_simple( WC_Product $product ): array {
		$regular_price = $product->get_regular_price();
		$warnings      = [];

		// Copilot指摘（PR #40）: 空文字列以外なら無条件に受理していたため、破損した
		// `_regular_price`メタ（非数値・負の値）がそのまま`push_product()`へ渡されうる。
		// `variants()`/`price_fields_for_variable()`と同じ基準（数値・0以上）で検証する。
		if ( '' === $regular_price || ! is_numeric( $regular_price ) || (float) $regular_price < 0 ) {
			// 価格未設定・不正な単純商品を0円として書き出すと「無料商品」に化ける
			// （CLAUDE.md: 楽観的デフォルトは金銭的リスクに直結する）。
			$warnings[] = WarningCode::PRODUCT_PRICE_INVALID;

			return [ '0', null, $warnings ];
		}

		// 検証は換算前の生の値で行い（`ProductWriter`と同じ基準を維持）、通った値を税込へ換算する。
		$inclusive_regular = $this->normalize_price( $product, $regular_price, $warnings );
		$raw_sale_price    = $this->valid_sale_price( $product, $regular_price );
		$inclusive_sale    = null !== $raw_sale_price ? $this->normalize_price( $product, $raw_sale_price, $warnings ) : null;

		if ( null === $inclusive_regular || ( null !== $raw_sale_price && null === $inclusive_sale ) ) {
			return [ '0', null, array_merge( $warnings, [ WarningCode::PRODUCT_PRICE_INVALID, WarningCode::PRICE_TAX_BASIS_UNRESOLVED ] ) ];
		}

		if ( null !== $inclusive_sale ) {
			$this->warn_if_sale_is_scheduled_to_end( $product, null, $warnings );
		}

		return [ $inclusive_regular, $inclusive_sale, $warnings ];
	}

	/**
	 * セール終了日（`date_on_sale_to`）付きのセールは、`CanonicalProduct`が終了日を運べないため
	 * ASPでは恒久的な販売価格になる（エクスポートは継続同期しない）。情報警告で知らせる。
	 *
	 * @param array<int,string> $warnings
	 * @param ?string           $detail バリエーションID等（単純商品はnull）。
	 */
	private function warn_if_sale_is_scheduled_to_end( WC_Product $product, ?string $detail, array &$warnings ): void {
		if ( null === $product->get_date_on_sale_to( 'edit' ) ) {
			return;
		}

		$warnings[] = null === $detail
			? WarningCode::SALE_END_DATE_NOT_PUSHED
			: WarningCode::with_detail( WarningCode::SALE_END_DATE_NOT_PUSHED, $detail );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function images( WC_Product $product ): array {
		$attachment_ids = array_filter( array_merge( [ $product->get_image_id() ], $product->get_gallery_image_ids() ) );
		$images         = [];

		foreach ( array_values( $attachment_ids ) as $position => $attachment_id ) {
			$src = wp_get_attachment_url( (int) $attachment_id );

			if ( false === $src ) {
				continue;
			}

			$images[] = [
				'src'      => $src,
				'position' => $position,
			];
		}

		return $images;
	}

	/**
	 * variation属性（軸）の名前一覧。`ProductWriter::variation_axis_names()`と同じくキー0=軸1、
	 * キー1=軸2のスロット規約を保持する（詰め直さない）。`CanonicalProduct::$variants`の
	 * option1/2規約は2軸までのため、3軸目以降は警告のうえ切り捨てる（Codex/Copilot指摘,
	 * PR #40: 無警告の切り捨ては異なる3軸目の値を持つバリエーション同士が同じoption1/2の組に
	 * 潰れうる）。
	 *
	 * @param array<int,string> $warnings 呼び出し元と共有する警告配列。
	 * @return array<int,WC_Product_Attribute>
	 */
	private function variation_axis_attributes( WC_Product_Variable $product, array &$warnings ): array {
		return VariationAxisResolver::axis_attributes( $product, $warnings );
	}

	/**
	 * @param array<int,WC_Product_Attribute> $axis_attributes
	 * @param array<int,string>               $warnings 呼び出し元と共有する警告配列（参照渡しの代わりに戻り値で反映）。
	 * @param array<int,int>                  $variant_local_ids 戻り値と同じ順序・同じ要素数になる
	 *   Wooバリエーション投稿ID一覧（`ReadItem::$variant_local_ids`）。呼び出し元の変数へ書き戻す。
	 * @return array<int,array<string,mixed>>
	 */
	private function variants( WC_Product_Variable $product, array $axis_attributes, array &$warnings, array &$variant_local_ids ): array {
		$variation_ids = array_map( 'intval', $product->get_children() );
		// アイテム（variation）毎のSELECTを避けるため一括プリロードする
		// （`VariationWriter::sync()`の逆方向、同じ理由）。
		$existing_remote_ids = $this->mappings->find_many_by_local_ids( $this->platform, 'variant', $variation_ids );

		$variants = [];

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			// Codex/Copilot指摘（PR #40）: `get_children()`は`publish`/`private`両方を含む
			// （`get_visible_children()`と異なる）。非公開バリエーションをそのままpushすると、
			// `CanonicalProduct::$variants`に公開状態を運ぶフィールドが無いため、マーチャントが
			// 意図的に非公開にしたバリエーションがASP側で「販売可能」として復活しうる
			// （金銭的リスク。CLAUDE.mdアーキテクチャ原則9）。
			if ( 'publish' !== $variation->get_status() ) {
				$warnings[] = WarningCode::with_detail( WarningCode::VARIATION_UNPUBLISHED, (string) $variation_id );
				continue;
			}

			$remote_id = $existing_remote_ids[ $variation_id ]['remote_id'] ?? null;

			$price = $variation->get_regular_price();

			// Copilot指摘（PR #40）: `''`以外なら無条件に受理していたため、破損した
			// `_regular_price`メタ（非数値・負の値）がそのまま`push_product()`へ渡されうる。
			// `VariationWriter::sync()`（インポート方向）と同じ基準（数値・0以上）で検証する。
			if ( '' === $price || ! is_numeric( $price ) || (float) $price < 0 ) {
				// 価格未設定・不正な価格のバリエーションを0円等で書き出すと、
				// `VariationWriter::sync()`側の価格検証（`VARIATION_PRICE_INVALID`）に
				// 必ず引っかかるだけでなく、CanonicalProductのchecksum上は不正な状態を
				// 運ぶことになる。読出時点でスキップし、原因をレポートで追跡できるようにする。
				$warnings[] = WarningCode::with_detail( WarningCode::VARIATION_PRICE_INVALID, (string) $variation_id );
				continue;
			}

			// 定価・セール価格とも税込へ換算する（`Woo\Support\TaxInclusivePrice`）。換算不能な
			// バリエーションはスキップし、blockingの`PRICE_TAX_BASIS_UNRESOLVED`で商品全体もpushを
			// 止める（`indicates_export_blocking()`は`:detail`を落として判定する。誤った売価を送らない）。
			$inclusive_price = $this->normalize_price( $variation, $price, $warnings );
			$raw_sale_price  = $this->valid_sale_price( $variation, $price );
			$inclusive_sale  = null !== $raw_sale_price ? $this->normalize_price( $variation, $raw_sale_price, $warnings ) : null;

			if ( null === $inclusive_price || ( null !== $raw_sale_price && null === $inclusive_sale ) ) {
				$warnings[] = WarningCode::with_detail( WarningCode::VARIATION_PRICE_INVALID, (string) $variation_id );
				$warnings[] = WarningCode::with_detail( WarningCode::PRICE_TAX_BASIS_UNRESOLVED, (string) $variation_id );
				continue;
			}

			$variant = [
				'remote_id' => $remote_id ?? '',
				'sku'       => '' !== $variation->get_sku() ? $variation->get_sku() : null,
				'price'     => $inclusive_price,
				'stock'     => $this->variation_stock( $variation, $warnings ),
				'weight'    => WeightUnit::convert_to_grams( (string) $variation->get_weight() ),
			];

			// セール中のバリエーションだけ`sale_price`（税込の実売価格）を運ぶ。セール外はキー自体を
			// 出さない: 全variable商品のchecksumが変わり、次回exportで（多段階リクエストの重い）
			// 全件再pushになるのを避けるため（`CanonicalProduct`のdocblock参照）。
			if ( null !== $inclusive_sale ) {
				$variant['sale_price'] = $inclusive_sale;
				$this->warn_if_sale_is_scheduled_to_end( $variation, (string) $variation_id, $warnings );
			}

			$this->apply_axis_values( $variant, $variation, $axis_attributes );

			$variants[]          = $variant;
			$variant_local_ids[] = $variation_id;
		}

		return $variants;
	}

	/**
	 * @param array<int,string> $warnings 呼び出し元と共有する警告配列。
	 */
	private function variation_stock( WC_Product_Variation $variation, array &$warnings ): ?int {
		$derived = StockDerivation::for_variation( $variation );

		if ( $derived['shared_with_parent'] ) {
			$warnings[] = WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT;
		}

		return $derived['quantity'];
	}

	/**
	 * @param array<int,WC_Product_Attribute> $axis_attributes
	 * @param array<string,mixed>             $variant
	 */
	private function apply_axis_values( array &$variant, WC_Product_Variation $variation, array $axis_attributes ): void {
		$raw_attributes = $variation->get_attributes();

		foreach ( [ 0, 1 ] as $index ) {
			$attribute = $axis_attributes[ $index ] ?? null;
			$name_key  = 'option' . ( $index + 1 ) . '_name';
			$value_key = 'option' . ( $index + 1 ) . '_value';

			if ( null === $attribute ) {
				$variant[ $name_key ]  = null;
				$variant[ $value_key ] = null;
				continue;
			}

			$variant[ $name_key ]  = $this->attribute_label( $attribute );
			$variant[ $value_key ] = VariationAxisResolver::attribute_value( $attribute, $raw_attributes );
		}
	}

	private function attribute_label( WC_Product_Attribute $attribute ): string {
		return VariationAxisResolver::attribute_label( $attribute );
	}

	/**
	 * 非バリエーション属性（`CanonicalProduct::$options`）。軸属性は`ProductWriter`側で
	 * `ATTRIBUTE_NAME_COLLISION`として除外されるため、ここでも軸と同名のものは含めない
	 * （エクスポート→再インポートの往復で無駄な衝突警告を発生させないため）。
	 *
	 * @param array<int,WC_Product_Attribute> $axis_attributes
	 * @return array<int,array<string,mixed>>
	 */
	private function options( WC_Product $product, array $axis_attributes ): array {
		$axis_names = array_map( fn ( WC_Product_Attribute $a ): string => $this->attribute_label( $a ), $axis_attributes );
		$options    = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || $attribute->get_variation() ) {
				continue;
			}

			$name = $this->attribute_label( $attribute );

			if ( in_array( $name, $axis_names, true ) ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$terms  = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
				$values = array_values( array_filter( $terms, 'is_string' ) );
			} else {
				$values = array_values( array_filter( $attribute->get_options(), 'is_string' ) );
			}

			$options[] = [
				'name'   => $name,
				'values' => $values,
			];
		}

		return $options;
	}

	/**
	 * `category_map`（Woo側カテゴリID→ASP側カテゴリID。D19）でASP側カテゴリIDへ解決する。
	 * マッピング未設定のカテゴリは警告付きで除外する（`ProductWriter::resolve_refs()`の
	 * エクスポート版対称形。ただし解決先が`cbjp_mappings`ではなくユーザー設定のため
	 * `MethodMap`を使う）。
	 *
	 * @return array{0:array<int,string>,1:array<int,string>}
	 */
	private function category_refs( WC_Product $product ): array {
		$refs     = [];
		$warnings = [];

		foreach ( $product->get_category_ids() as $term_id ) {
			$asp_category_id = $this->method_map->mapped_asp_category_id( (string) $term_id );

			if ( null === $asp_category_id ) {
				$warnings[] = WarningCode::with_detail( WarningCode::CATEGORY_MAP_UNRESOLVED, (string) $term_id );
				continue;
			}

			$refs[] = $asp_category_id;
		}

		// 異なるWooカテゴリが同じASPカテゴリへマッピングされている場合、重複したrefを
		// そのまま渡すとASP側が拒否・二重登録しうる。
		return [ array_values( array_unique( $refs ) ), $warnings ];
	}
}
