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
		// 読むと0円になる。バリエーションの最安価格（1000。`get_variation_price()`は
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
