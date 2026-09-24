<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\ProductWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;

final class ProductReaderTest extends WooTestCase {

	private const PLATFORM = 'colorme';

	private function make_reader(): ProductReader {
		return new ProductReader( self::PLATFORM, new MethodMap( self::PLATFORM ), $this->mappings );
	}

	private function make_writer(): ProductWriter {
		return new ProductWriter( self::PLATFORM, $this->mappings, new VariationWriter( self::PLATFORM, $this->mappings ), new MediaImporter( self::PLATFORM ) );
	}

	private function first_item( ProductReader $reader, ?array $only_local_ids = null ): CanonicalProduct {
		$page = $reader->query( Cursor::start(), $only_local_ids );
		$this->assertNotEmpty( $page->items );

		return $page->items[0]->item;
	}

	public function test_reads_simple_product_core_fields(): void {
		// このテスト環境の既定は`woocommerce_calc_taxes=no`（`wc_prices_include_tax()`が
		// 常にfalse）であり、そのままだと本テストの意図と無関係な`PRICES_INCLUDE_TAX_DISABLED`
		// 警告が付いてしまう（CLAUDE.md「検証環境では税計算ONと税率登録まで行うこと」参照）。
		// 税計算ONを明示し、警告カバレッジ自体は`test_prices_excluding_tax_warns()`に譲る。
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );

		// WooCommerceは明示的なカテゴリを持たない商品作成時に`default_product_cat`
		// （通常「未分類」）を自動付与する（`WC_Product_Data_Store_CPT::update_terms()`の
		// `empty($categories)`ガード。実測確認済み）。何もマッピングしないままだと
		// `CATEGORY_MAP_UNRESOLVED`警告が必ず付き`fully_resolved`がfalseになり、この
		// テストの意図（コアフィールドが過不足なく読めること）と無関係な失敗要因になるため、
		// 明示的にマッピング済みカテゴリを1つ割り当てて「未分類」の自動付与を避ける。
		// 商品作成（`ProductWriter`）はASP→Woo方向の`cbjp_mappings`（category entity）で
		// カテゴリ参照を解決するため、これを用意しないと`asp-cat-1`が解決できず
		// `set_category_ids([])`経由でむしろ「未分類」の自動付与を誘発してしまう
		// （`category_map`設定は逆方向＝`ProductReader`の読出専用で、`ProductWriter`は見ない）。
		$term = wp_insert_term( 'Shirts', 'product_cat' );
		$this->seed_mapping( self::PLATFORM, 'category', 'asp-cat-1', (int) $term['term_id'] );
		update_option( 'cbjp_settings_' . self::PLATFORM, [ 'category_map' => [ (string) $term['term_id'] => 'asp-cat-1' ] ] );

		$product = new CanonicalProduct(
			'T-Shirt',
			'SKU-1',
			'3300',
			'2900',
			'desc',
			[],
			[],
			[],
			[ 'asp-cat-1' ],
			10,
			'publish',
			[ 'remote_id' => '1' ],
			true,
			[],
			500,
			'reduced-rate'
		);

		$result = $this->make_writer()->write( $product, null );

		$reader    = $this->make_reader();
		$read_page = $reader->query( Cursor::start(), [ $result->local_id ] );

		$this->assertCount( 1, $read_page->items );
		$read_item = $read_page->items[0];
		$this->assertSame( $result->local_id, $read_item->local_id );
		$this->assertTrue( $read_item->fully_resolved );
		$this->assertSame( [], $read_item->warnings );

		$canonical = $read_item->item;
		$this->assertSame( 'T-Shirt', $canonical->name );
		$this->assertSame( 'SKU-1', $canonical->sku );
		$this->assertSame( '3300', $canonical->price );
		$this->assertSame( '2900', $canonical->sale_price );
		$this->assertSame( 'desc', $canonical->description );
		$this->assertSame( 10, $canonical->stock );
		$this->assertSame( 'publish', $canonical->status );
		$this->assertSame( 500, $canonical->weight );
		$this->assertSame( 'reduced-rate', $canonical->tax_class );
		$this->assertSame( [], $canonical->variants );
	}

	/**
	 * カテゴリを明示的に指定しない商品は、WooCommerceが自動的に`default_product_cat`
	 * （通常「未分類」）へ割り当てる。これも他のカテゴリと同様`category_map`の対象になるため、
	 * マッピングしない限り`CATEGORY_MAP_UNRESOLVED`警告が付き`fully_resolved`はfalseになる
	 * （H5: 以前はテスト実行順序に依存する形でこの挙動を見落としていた）。
	 * `default_product_cat`オプションの状態はテストスイート全体の実行順序に依存しうる
	 * （他クラスがタームを全削除するとオプションが有効な参照を失う）ため、このテスト自身が
	 * 有効なタームを指すよう明示的にセットアップし、実際に付与されたことも確認する。
	 */
	public function test_default_uncategorized_category_without_mapping_marks_unresolved(): void {
		// WooCommerce自身が有効化時に「未分類」を作成済みのため、単純に`wp_insert_term()`すると
		// 名前重複で`WP_Error`になる（実測: 単体実行時。フルスイートでは他クラスがタームを
		// 全削除しているため新規作成に成功してしまい、この差だけで挙動が変わっていた＝H5）。
		// 既存のタームを再利用し、無ければ作成する。
		$existing_term = get_term_by( 'name', 'Uncategorized', 'product_cat' );
		$term_id       = $existing_term instanceof \WP_Term ? $existing_term->term_id : (int) wp_insert_term( 'Uncategorized', 'product_cat' )['term_id'];
		update_option( 'default_product_cat', $term_id );

		$product = new CanonicalProduct( 'No Category', 'SKU-NC', '1000', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '2' ] );
		$result  = $this->make_writer()->write( $product, null );

		$this->assertNotEmpty( wc_get_product( $result->local_id )->get_category_ids(), '前提: デフォルトカテゴリが実際に付与されていること' );

		$read_page = $this->make_reader()->query( Cursor::start(), [ $result->local_id ] );
		$read_item = $read_page->items[0];

		$this->assertFalse( $read_item->fully_resolved );
		$this->assertNotEmpty(
			array_filter(
				$read_item->warnings,
				static fn ( string $w ): bool => WarningCode::CATEGORY_MAP_UNRESOLVED === WarningCode::split( $w )[0]
			)
		);
	}

	public function test_unmanaged_stock_reads_as_null(): void {
		$product = new CanonicalProduct( 'No Stock Mgmt', 'SKU-9', '1000', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '9' ] );
		$result  = $this->make_writer()->write( $product, null );

		$canonical = $this->first_item( $this->make_reader(), [ $result->local_id ] );
		$this->assertNull( $canonical->stock );
	}

	/**
	 * 数量管理していない商品でも`_stock_status`が明示的に「在庫切れ」の場合、`null`
	 * （在庫管理外＝在庫あり）と誤変換すると、ASP側で常に購入可能として扱われてしまう
	 * （H4: 数量管理せず在庫状態だけ手動切替する運用はWooCommerceで一般的）。
	 */
	public function test_unmanaged_out_of_stock_reads_as_zero(): void {
		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Manually Out of Stock' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_manage_stock( false );
		$wc_product->set_stock_status( 'outofstock' );
		$local_id = $wc_product->save();

		$canonical = $this->first_item( $this->make_reader(), [ $local_id ] );
		$this->assertSame( 0, $canonical->stock );
	}

	/**
	 * `get_sale_price()`は日程を考慮しないため、開始日が未来のセール価格をそのまま
	 * エクスポートすると割引が早期に有効化されてしまう（H3）。
	 */
	public function test_scheduled_future_sale_price_is_not_exported_early(): void {
		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Scheduled Sale' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_sale_price( '800' );
		$wc_product->set_date_on_sale_from( time() + DAY_IN_SECONDS );
		$local_id = $wc_product->save();

		$canonical = $this->first_item( $this->make_reader(), [ $local_id ] );
		$this->assertNull( $canonical->sale_price );
	}

	/**
	 * `sale_price >= regular_price`のような不正な組み合わせもセール扱いにしない
	 * （`ProductWriter::resolve_sale_price()`と同じ基準。H3）。
	 */
	public function test_sale_price_not_lower_than_regular_price_is_ignored(): void {
		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Bad Sale' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_sale_price( '1000' );
		$local_id = $wc_product->save();

		$canonical = $this->first_item( $this->make_reader(), [ $local_id ] );
		$this->assertNull( $canonical->sale_price );
	}

	/**
	 * 価格未設定の単純商品を0円として書き出すと無料商品に化ける（H2/CLAUDE.md）。
	 */
	public function test_missing_price_warns_instead_of_defaulting_silently(): void {
		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'No Price' );
		$local_id = $wc_product->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $local_id ] );
		$read_item = $read_page->items[0];

		$this->assertSame( '0', $read_item->item->price );
		$this->assertContains( WarningCode::PRODUCT_PRICE_INVALID, $read_item->warnings );
	}

	/**
	 * 税計算ON・税抜入力（`woocommerce_prices_include_tax=no`）・基準所在地JPの店舗にし、
	 * 国全体の税率を登録する（`Woo\Support\TaxInclusivePrice`は店舗の基準所在地の税率で換算する）。
	 */
	private function tax_exclusive_store( string $country = 'JP', string $rate = '10.0000' ): void {
		update_option( 'woocommerce_currency', 'JPY' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		update_option( 'woocommerce_default_country', 'JP' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );

		\WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => $country,
				'tax_rate_state'    => '',
				'tax_rate'          => $rate,
				'tax_rate_name'     => 'Test',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			]
		);
	}

	/**
	 * `woocommerce_prices_include_tax=no`かつ税計算ONの店舗では`get_regular_price()`が税抜金額を返すが、
	 * Canonicalの価格契約は税込（消費者の支払額）。店舗の基準所在地の税率で税込へ換算して運び、
	 * 換算したことを情報警告（非blocking）で知らせる（issue #59。旧仕様は換算せず警告のみ）。
	 */
	public function test_tax_exclusive_prices_are_converted_to_tax_inclusive(): void {
		$this->tax_exclusive_store();

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Tax Exclusive' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_sale_price( '800' );
		$local_id = $wc_product->save();

		$read_item = $this->make_reader()->query( Cursor::start(), [ $local_id ] )->items[0];

		$this->assertSame( '1100', $read_item->item->price );
		$this->assertSame( '880', $read_item->item->sale_price );
		$this->assertContains( WarningCode::PRICES_CONVERTED_TO_TAX_INCLUSIVE, $read_item->warnings );
		$this->assertNotContains( WarningCode::PRICES_INCLUDE_TAX_DISABLED, $read_item->warnings );
		$this->assertFalse( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * 税計算OFF（フレッシュなWCの既定）では入力価格がそのまま消費者の支払額＝税込のため、換算も警告も不要
	 * （旧仕様の`PRICES_INCLUDE_TAX_DISABLED`はこの既定環境でも発火する誤検知だった）。
	 */
	public function test_tax_calculation_disabled_reads_prices_unchanged_without_warning(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'No Tax' );
		$wc_product->set_regular_price( '1000' );
		$local_id = $wc_product->save();

		$read_item = $this->make_reader()->query( Cursor::start(), [ $local_id ] )->items[0];

		$this->assertSame( '1000', $read_item->item->price );
		$this->assertNotContains( WarningCode::PRICES_CONVERTED_TO_TAX_INCLUSIVE, $read_item->warnings );
		$this->assertNotContains( WarningCode::PRICES_INCLUDE_TAX_DISABLED, $read_item->warnings );
	}

	/**
	 * 税率は登録済みだが基準所在地に合致しない（どの税率で課税されるか決められない）場合、税抜のまま
	 * 税込として送ると売価が税分だけ低くなる。価格不正と同じく`0`にフェイルクローズし、blockingにする。
	 */
	public function test_tax_exclusive_price_with_unresolvable_tax_basis_is_blocking(): void {
		$this->tax_exclusive_store( 'US' );

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Unresolvable' );
		$wc_product->set_regular_price( '1000' );
		$local_id = $wc_product->save();

		$read_item = $this->make_reader()->query( Cursor::start(), [ $local_id ] )->items[0];

		$this->assertSame( '0', $read_item->item->price );
		$this->assertContains( WarningCode::PRODUCT_PRICE_INVALID, $read_item->warnings );
		$this->assertContains( WarningCode::PRICE_TAX_BASIS_UNRESOLVED, $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * 換算情報の警告は商品ごとに積むと結果が埋もれるため、Readerインスタンス（=1ページ）につき1回だけ。
	 */
	public function test_conversion_warning_is_reported_once_per_page(): void {
		$this->tax_exclusive_store();

		$ids = [];

		foreach ( [ 'A', 'B' ] as $name ) {
			$wc_product = new \WC_Product_Simple();
			$wc_product->set_name( $name );
			$wc_product->set_regular_price( '1000' );
			$ids[] = $wc_product->save();
		}

		$items = $this->make_reader()->query( Cursor::start(), $ids )->items;

		$this->assertCount( 2, $items );
		$reported = array_sum(
			array_map(
				static fn ( $item ): int => in_array( WarningCode::PRICES_CONVERTED_TO_TAX_INCLUSIVE, $item->warnings, true ) ? 1 : 0,
				$items
			)
		);
		$this->assertSame( 1, $reported );
	}

	/**
	 * 税抜入力の店舗のvariable商品: 親の代表値（最安定価）・各バリエーションの定価をすべて税込へ換算し、
	 * セール中のバリエーションだけが税込の`sale_price`を運ぶ（セール外はキー自体を出さない。
	 * 全variable商品のchecksumを不必要に変えないため）。
	 */
	public function test_variable_product_prices_are_converted_and_only_on_sale_variants_carry_sale_price(): void {
		$this->tax_exclusive_store();

		$variation_ids = $this->write_two_variants( '1000', '1200' );

		$large = wc_get_product( $variation_ids['L'] );
		$large->set_sale_price( '900' );
		$large->save();

		$canonical = $this->first_item( $this->make_reader(), [ wc_get_product( $variation_ids['L'] )->get_parent_id() ] );
		$variants  = array_column( $canonical->variants, null, 'option1_value' );

		$this->assertSame( '1100', $canonical->price );
		$this->assertSame( '1100', $variants['M']['price'] );
		$this->assertArrayNotHasKey( 'sale_price', $variants['M'] );
		$this->assertSame( '1320', $variants['L']['price'] );
		$this->assertSame( '990', $variants['L']['sale_price'] );
	}

	public function test_scheduled_future_variation_sale_price_is_not_carried(): void {
		$this->tax_exclusive_store();

		$variation_ids = $this->write_two_variants( '1000', '1200' );

		$large = wc_get_product( $variation_ids['L'] );
		$large->set_sale_price( '900' );
		$large->set_date_on_sale_from( time() + WEEK_IN_SECONDS );
		$large->save();

		$canonical = $this->first_item( $this->make_reader(), [ wc_get_product( $variation_ids['L'] )->get_parent_id() ] );
		$variants  = array_column( $canonical->variants, null, 'option1_value' );

		$this->assertArrayNotHasKey( 'sale_price', $variants['L'] );
	}

	public function test_variation_sale_price_not_lower_than_regular_price_is_ignored(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );

		$variation_ids = $this->write_two_variants( '1000', '1200' );

		$medium = wc_get_product( $variation_ids['M'] );
		$medium->set_sale_price( '1000' );
		$medium->save();

		$canonical = $this->first_item( $this->make_reader(), [ wc_get_product( $variation_ids['M'] )->get_parent_id() ] );
		$variants  = array_column( $canonical->variants, null, 'option1_value' );

		$this->assertArrayNotHasKey( 'sale_price', $variants['M'] );
	}

	/**
	 * 換算できないバリエーションは価格不正と同じ理由で除外し、全バリエーションが除外された商品は
	 * blockingになる（誤った売価を本番へ送らない）。
	 */
	public function test_variations_with_unresolvable_tax_basis_are_excluded_and_block_the_product(): void {
		$this->tax_exclusive_store( 'US' );

		$variation_ids = $this->write_two_variants( '1000', '1200' );

		$read_item = $this->make_reader()->query( Cursor::start(), [ wc_get_product( $variation_ids['M'] )->get_parent_id() ] )->items[0];

		$this->assertSame( [], $read_item->item->variants );
		$this->assertContains( WarningCode::ALL_VARIATIONS_EXCLUDED, $read_item->warnings );
		$this->assertContains( WarningCode::with_detail( WarningCode::PRICE_TAX_BASIS_UNRESOLVED, (string) $variation_ids['M'] ), $read_item->warnings );
		$this->assertContains( WarningCode::PRICE_TAX_BASIS_UNRESOLVED, $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * Size軸（M/L）の2バリエーションを持つvariable商品を`ProductWriter`で作り、
	 * `['M' => variation_id, 'L' => variation_id]`を返す。
	 *
	 * @return array{M:int,L:int}
	 */
	private function write_two_variants( string $medium_price, string $large_price ): array {
		$product = new CanonicalProduct(
			'Shirt',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'SKU-V1',
					'price'         => $medium_price,
					'stock'         => 3,
					'option1_name'  => 'Size',
					'option1_value' => 'M',
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'SKU-V2',
					'price'         => $large_price,
					'stock'         => 4,
					'option1_name'  => 'Size',
					'option1_value' => 'L',
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '100' ]
		);

		$result = $this->make_writer()->write( $product, null );
		$ids    = [];

		foreach ( wc_get_product( $result->local_id )->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			$ids[ 'SKU-V1' === $variation->get_sku() ? 'M' : 'L' ] = (int) $child_id;
		}

		return $ids;
	}

	/**
	 * `get_tax_class()`は`tax_status`（`taxable`/`shipping`/`none`）を運ばない。
	 * `CanonicalProduct`にも`tax_status`を持つフィールドが無いため、非課税商品を無警告で
	 * exportすると変換先で通常課税として扱われうる（Codex指摘, PR #40 G3）。
	 */
	public function test_non_taxable_product_warns(): void {
		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Non Taxable' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_tax_status( 'none' );
		$local_id = $wc_product->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $local_id ] );
		$read_item = $read_page->items[0];

		$this->assertContains( WarningCode::TAX_STATUS_NOT_TAXABLE, $read_item->warnings );
	}

	public function test_category_resolved_via_category_map(): void {
		$term    = wp_insert_term( 'Shirts', 'product_cat' );
		$term_id = (int) $term['term_id'];

		update_option(
			'cbjp_settings_' . self::PLATFORM,
			[ 'category_map' => [ (string) $term_id => 'asp-cat-42' ] ]
		);

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Shirt' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_category_ids( [ $term_id ] );
		$local_id = $wc_product->save();

		$canonical = $this->first_item( $this->make_reader(), [ $local_id ] );
		$this->assertSame( [ 'asp-cat-42' ], $canonical->category_refs );
	}

	public function test_unmapped_category_warns_and_marks_unresolved(): void {
		$term    = wp_insert_term( 'Unmapped Category', 'product_cat' );
		$term_id = (int) $term['term_id'];

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Widget' );
		$wc_product->set_regular_price( '1000' );
		$wc_product->set_category_ids( [ $term_id ] );
		$local_id = $wc_product->save();

		$reader    = $this->make_reader();
		$read_page = $reader->query( Cursor::start(), [ $local_id ] );
		$read_item = $read_page->items[0];

		$this->assertSame( [], $read_item->item->category_refs );
		$this->assertContains( WarningCode::with_detail( WarningCode::CATEGORY_MAP_UNRESOLVED, (string) $term_id ), $read_item->warnings );
		$this->assertFalse( $read_item->fully_resolved );
	}

	public function test_variable_product_reads_variants_with_axis_values(): void {
		$product = new CanonicalProduct(
			'Shirt',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'SKU-V1',
					'price'         => '1000',
					'stock'         => 3,
					'option1_name'  => 'Size',
					'option1_value' => 'M',
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'SKU-V2',
					'price'         => '1200',
					'stock'         => 4,
					'option1_name'  => 'Size',
					'option1_value' => 'L',
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '100' ]
		);

		$result = $this->make_writer()->write( $product, null );

		$canonical = $this->first_item( $this->make_reader(), [ $result->local_id ] );

		$this->assertCount( 2, $canonical->variants );
		// variable親の_regular_price/_sale_priceは保存の度に削除される（H2）ため、無条件に
		// 読むと0円になる。バリエーションの最安「定価」（1000。`get_variation_regular_price()`は
		// ストアの価格小数桁数設定でフォーマットするため文字列の完全一致ではなく数値で比較する）
		// を代表値として使う。
		$this->assertIsNumeric( $canonical->price );
		$this->assertSame( 1000.0, (float) $canonical->price );
		$sizes = array_column( $canonical->variants, 'option1_value' );
		sort( $sizes );
		$this->assertSame( [ 'L', 'M' ], $sizes );

		foreach ( $canonical->variants as $variant ) {
			// エクスポートで既にリンク済みのvariant（`cbjp_mappings`のvariant entity）は
			// 既存remote_idを持つ（VariationWriterが作成時にupsertしたもの）。
			$this->assertNotSame( '', $variant['remote_id'] );
			$this->assertSame( 'Size', $variant['option1_name'] );
		}
	}

	/**
	 * `WC_Product_Variable::get_variation_regular_price()`は、公開かつ（設定次第で）在庫ありの
	 * バリエーションが1件も無い場合（`get_visible_children()`が空集合）、PHPの`current([])`規約に
	 * より**bool `false`**を返す（`declare(strict_types=1)`下の`CanonicalProduct::$price`
	 * （非nullable string）にそのまま渡すと`TypeError`になり、Exporterの1件tryの外側で
	 * 発生するためページ全体・ジョブ全体が恒久的に失敗する）。全バリエーションを非公開に
	 * することでこの状態を再現し、TypeErrorにならず警告付きで0にフェイルクローズすることを確認する。
	 *
	 * 非公開バリエーションは`variants()`自体からも除外される（Codex/Copilot指摘, PR #40:
	 * `get_children()`は`publish`/`private`両方を含むため、除外しないとASP側で「販売可能」として
	 * 復活しうる）ため、`variants`は空配列になり`ALL_VARIATIONS_EXCLUDED`も付く。
	 */
	public function test_variable_product_with_no_visible_variations_does_not_crash(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'No Visible Variations' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '500' );
		// 非公開にすることで`get_visible_children()`の対象から外す。
		$variation->set_status( 'private' );
		$variation_id = $variation->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$read_item = $read_page->items[0];

		$this->assertCount( 0, $read_item->item->variants );
		$this->assertSame( '0', $read_item->item->price );
		$this->assertContains( WarningCode::PRODUCT_PRICE_INVALID, $read_item->warnings );
		$this->assertContains( WarningCode::with_detail( WarningCode::VARIATION_UNPUBLISHED, (string) $variation_id ), $read_item->warnings );
		$this->assertContains( WarningCode::ALL_VARIATIONS_EXCLUDED, $read_item->warnings );
	}

	/**
	 * 一部のバリエーションだけが非公開・価格不正な場合、そのバリエーションのみ除外され
	 * 兄弟バリエーションは正常にエクスポートされること（全滅判定`ALL_VARIATIONS_EXCLUDED`と
	 * 混同していないことのピン留め）。
	 */
	public function test_invalid_variation_is_excluded_but_valid_siblings_remain(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Mixed Validity Variations' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S', 'M', 'L' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$good = new \WC_Product_Variation();
		$good->set_parent_id( $parent_id );
		$good->set_attributes( [ 'size' => 'S' ] );
		$good->set_regular_price( '500' );
		$good->save();

		$unpublished = new \WC_Product_Variation();
		$unpublished->set_parent_id( $parent_id );
		$unpublished->set_attributes( [ 'size' => 'M' ] );
		$unpublished->set_regular_price( '600' );
		$unpublished->set_status( 'private' );
		$unpublished_id = $unpublished->save();

		$invalid_price = new \WC_Product_Variation();
		$invalid_price->set_parent_id( $parent_id );
		$invalid_price->set_attributes( [ 'size' => 'L' ] );
		$invalid_price->set_regular_price( '-100' );
		$invalid_price_id = $invalid_price->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$read_item = $read_page->items[0];

		$this->assertCount( 1, $read_item->item->variants );
		$this->assertSame( 'S', $read_item->item->variants[0]['option1_value'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::VARIATION_UNPUBLISHED, (string) $unpublished_id ), $read_item->warnings );
		$this->assertContains( WarningCode::with_detail( WarningCode::VARIATION_PRICE_INVALID, (string) $invalid_price_id ), $read_item->warnings );
		$this->assertNotContains( WarningCode::ALL_VARIATIONS_EXCLUDED, $read_item->warnings );
	}

	/**
	 * 3軸目以降のバリエーション属性は`CanonicalProduct::$variants`のoption1/2規約により
	 * 先頭2軸のみに切り詰められる。無警告のままだと異なる3軸目の値を持つバリエーション同士が
	 * 同じoption1/2の組に潰れうるため、`VARIATION_AXIS_LIMIT_EXCEEDED`で警告する
	 * （Codex/Copilot指摘, PR #40）。
	 */
	public function test_third_variation_axis_is_truncated_with_warning(): void {
		$make_axis = static function ( string $name, array $options ): \WC_Product_Attribute {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( $options );
			$attribute->set_position( 0 );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			return $attribute;
		};

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Three Axes' );
		$parent->set_attributes(
			[
				$make_axis( 'Size', [ 'S' ] ),
				$make_axis( 'Color', [ 'Red' ] ),
				$make_axis( 'Material', [ 'Cotton' ] ),
			]
		);
		$parent_id = $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes(
			[
				'size'     => 'S',
				'color'    => 'Red',
				'material' => 'Cotton',
			]
		);
		$variation->set_regular_price( '500' );
		$variation->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$read_item = $read_page->items[0];

		$this->assertContains( WarningCode::VARIATION_AXIS_LIMIT_EXCEEDED, $read_item->warnings );
		$this->assertCount( 1, $read_item->item->variants );
		$this->assertSame( 'Size', $read_item->item->variants[0]['option1_name'] );
		$this->assertSame( 'Color', $read_item->item->variants[0]['option2_name'] );
		$this->assertArrayNotHasKey( 'option3_name', $read_item->item->variants[0] );
	}

	/**
	 * 破損した`_regular_price`メタ（負の値）を持つ単純商品を0円等で書き出すと「無料商品」に
	 * 化ける。`WC_Product::set_regular_price()`は負の数値文字列をそのまま通す（`wc_format_decimal()`
	 * は符号を検証しない。実測確認済み）ため、通常のWC APIだけでも到達しうる状態
	 * （Copilot指摘, PR #40）。
	 */
	public function test_negative_regular_price_is_treated_as_invalid(): void {
		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Negative Price' );
		$wc_product->set_regular_price( '-100' );
		$local_id = $wc_product->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $local_id ] );
		$read_item = $read_page->items[0];

		$this->assertSame( '0', $read_item->item->price );
		$this->assertContains( WarningCode::PRODUCT_PRICE_INVALID, $read_item->warnings );
	}

	/**
	 * variable商品の全バリエーションが負の価格の場合、`price_fields_for_variable()`が読む
	 * `get_variation_regular_price('min', false)`自体が負の数値文字列を返す（`false`にはならない）。
	 * `variants()`側の除外とは独立した検証経路のため、こちらも数値・0以上を満たさなければ
	 * フェイルクローズすることを確認する。
	 */
	public function test_variable_product_with_only_negative_price_variation_falls_back_to_zero(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Only Negative Price Variation' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '-100' );
		$variation_id = $variation->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$read_item = $read_page->items[0];

		$this->assertCount( 0, $read_item->item->variants );
		$this->assertSame( '0', $read_item->item->price );
		$this->assertContains( WarningCode::PRODUCT_PRICE_INVALID, $read_item->warnings );
		$this->assertContains( WarningCode::with_detail( WarningCode::VARIATION_PRICE_INVALID, (string) $variation_id ), $read_item->warnings );
		$this->assertContains( WarningCode::ALL_VARIATIONS_EXCLUDED, $read_item->warnings );
	}

	public function test_new_variation_without_mapping_reports_empty_remote_id(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Unlinked Variable' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S', 'M' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '500' );
		$variation->save();

		$canonical = $this->first_item( $this->make_reader(), [ $parent_id ] );

		$this->assertCount( 1, $canonical->variants );
		$this->assertSame( '', $canonical->variants[0]['remote_id'] );
		$this->assertSame( 'S', $canonical->variants[0]['option1_value'] );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}

	/**
	 * 親レベルで一括管理される在庫（`get_manage_stock()`が`'parent'`を返す）を各バリエーションへ
	 * そのまま複製すると、実在庫のバリエーション数倍を販売可能数量として申告してしまう（M1）。
	 */
	public function test_variation_stock_shared_with_parent_fails_closed_with_warning(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Shared Stock' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S', 'M' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 10 );
		$parent_id = $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '500' );
		$variation->set_manage_stock( false );
		$variation->save();

		$read_page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$read_item = $read_page->items[0];

		$this->assertSame( 0, $read_item->item->variants[0]['stock'] );
		$this->assertContains( WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT, $read_item->warnings );
	}

	/**
	 * ページサイズ（`ProductReader::PAGE_SIZE`＝20）を超える件数でカーソルが正しく進み、
	 * 最終ページで`null`になること（M7）。
	 */
	public function test_query_walks_multiple_pages(): void {
		$ids = [];

		for ( $i = 0; $i < 22; $i++ ) {
			$wc_product = new \WC_Product_Simple();
			$wc_product->set_name( "Product {$i}" );
			$wc_product->set_regular_price( '1000' );
			$ids[] = $wc_product->save();
		}

		$reader     = $this->make_reader();
		$first_page = $reader->query( Cursor::start(), null );

		$this->assertCount( 20, $first_page->items );
		$this->assertSame( 22, $first_page->total );
		$this->assertNotNull( $first_page->next_cursor );

		$second_page = $reader->query( $first_page->next_cursor, null );

		$this->assertCount( 2, $second_page->items );
		$this->assertNull( $second_page->next_cursor );

		$all_ids = array_merge(
			array_map( static fn ( $read_item ) => $read_item->local_id, $first_page->items ),
			array_map( static fn ( $read_item ) => $read_item->local_id, $second_page->items )
		);
		sort( $all_ids );
		sort( $ids );
		$this->assertSame( $ids, $all_ids );
	}
}
