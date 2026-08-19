<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\ProductWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;
use WC_Product_Variable;
use WC_Product_Variation;

final class ProductWriterTest extends WooTestCase {

	private function make_writer( string $platform = 'colorme' ): ProductWriter {
		return new ProductWriter( $platform, $this->mappings, new VariationWriter( $platform, $this->mappings ), new MediaImporter() );
	}

	public function test_creates_simple_product_with_core_fields(): void {
		$product = new CanonicalProduct(
			'T-Shirt',
			'SKU-1',
			'3300',
			null,
			'desc',
			[],
			[],
			[],
			[],
			10,
			'publish',
			[
				'remote_id' => '1',
				'sort'      => 5,
				'unlisted'  => true,
			],
			true,
			[],
			500,
			'reduced-rate'
		);

		$result = $this->make_writer()->write( $product, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertSame( 'T-Shirt', $wc_product->get_name() );
		$this->assertSame( 'SKU-1', $wc_product->get_sku() );
		$this->assertSame( '3300', $wc_product->get_regular_price() );
		$this->assertSame( 'publish', $wc_product->get_status() );
		$this->assertSame( 'hidden', $wc_product->get_catalog_visibility() );
		$this->assertSame( 5, $wc_product->get_menu_order() );
		$this->assertSame( 10, $wc_product->get_stock_quantity() );
		$this->assertSame( 'reduced-rate', $wc_product->get_tax_class() );
		$this->assertSame( '1', get_post_meta( $result->local_id, '_cbjp_remote_id', true ) );
		$this->assertSame( 'colorme', get_post_meta( $result->local_id, '_cbjp_platform', true ) );
	}

	public function test_unknown_tax_class_falls_back_and_warns(): void {
		$product = new CanonicalProduct( 'P', 'SKU-2', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '2' ], true, [], null, 'nonexistent-class' );

		$result = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertSame( '', $wc_product->get_tax_class() );
		$this->assertContains( WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, 'nonexistent-class' ), $result->warnings );
	}

	public function test_weight_is_converted_from_grams_to_store_unit(): void {
		update_option( 'woocommerce_weight_unit', 'kg' );

		$product = new CanonicalProduct( 'P', 'SKU-3', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '3' ], true, [], 1500 );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '1.5', $wc_product->get_weight() );
	}

	public function test_sku_conflict_falls_back_to_empty_sku_and_warns(): void {
		$existing = new CanonicalProduct( 'Existing', 'DUP-SKU', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => 'existing' ] );
		$this->make_writer()->write( $existing, null );

		$product = new CanonicalProduct( 'New', 'DUP-SKU', '200', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => 'new' ] );
		$result  = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertSame( '', $wc_product->get_sku() );
		$this->assertSame( 'DUP-SKU', get_post_meta( $result->local_id, '_cbjp_original_sku', true ) );
		$this->assertContains( WarningCode::with_detail( WarningCode::SKU_DUPLICATE, 'DUP-SKU' ), $result->warnings );
	}

	public function test_few_num_maps_to_native_low_stock_amount_and_clears_when_removed(): void {
		// `few_num`はWoo標準の`low_stock_amount`にも反映する。再同期でASP側の設定が
		// 解除された（extrasからfew_numが消えた）場合、古い閾値がpostmetaに残り続けず
		// 明示的にクリアされることを確認する。
		// `WC_Product::validate_props()`は`manage_stock`がfalseの場合`save()`の度に
		// `low_stock_amount`を強制的に`''`へリセットする（`stock_status`の上書きと同じ
		// WooCommerce自身の仕様）ため、この検証には在庫数量ありの管理対象商品が必要。
		$with_few_num = new CanonicalProduct(
			'P',
			'SKU-30',
			'100',
			null,
			null,
			[],
			[],
			[],
			[],
			20,
			'publish',
			[
				'remote_id' => '30',
				'few_num'   => 5,
			]
		);
		$first        = $this->make_writer()->write( $with_few_num, null );

		$product = wc_get_product( $first->local_id );
		$this->assertSame( 5, $product->get_low_stock_amount() );

		$without_few_num = new CanonicalProduct( 'P', 'SKU-30', '100', null, null, [], [], [], [], 20, 'publish', [ 'remote_id' => '30' ] );
		$second          = $this->make_writer()->write( $without_few_num, $first->local_id );

		$updated = wc_get_product( $second->local_id );
		$this->assertSame( '', $updated->get_low_stock_amount() );
	}

	public function test_resolves_categories_and_tags_via_mapping(): void {
		$category_term_id = wp_insert_term( 'Cat', 'product_cat' )['term_id'];
		$tag_term_id      = wp_insert_term( 'Tag', 'product_tag' )['term_id'];
		$this->seed_mapping( 'colorme', 'category', '10', $category_term_id );
		$this->seed_mapping( 'colorme', 'tag', '20', $tag_term_id );

		$product = new CanonicalProduct( 'P', 'SKU-4', '100', null, null, [], [], [], [ '10' ], null, 'publish', [ 'remote_id' => '4' ], true, [ '20' ] );
		$result  = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertContains( $category_term_id, $wc_product->get_category_ids() );
		$this->assertContains( $tag_term_id, $wc_product->get_tag_ids() );
	}

	public function test_unresolved_category_ref_warns(): void {
		$product = new CanonicalProduct( 'P', 'SKU-5', '100', null, null, [], [], [], [ '999' ], null, 'publish', [ 'remote_id' => '5' ] );
		$result  = $this->make_writer()->write( $product, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::CATEGORY_REF_UNRESOLVED, '999' ), $result->warnings );
	}

	public function test_re_run_updates_existing_product_instead_of_duplicating(): void {
		$product = new CanonicalProduct( 'P', 'SKU-6', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '6' ] );
		$first   = $this->make_writer()->write( $product, null );

		$updated = new CanonicalProduct( 'P Updated', 'SKU-6', '200', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '6' ] );
		$second  = $this->make_writer()->write( $updated, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$this->assertSame( WriteResult::OPERATION_UPDATED, $second->operation );

		$wc_product = wc_get_product( $first->local_id );
		$this->assertSame( 'P Updated', $wc_product->get_name() );
		$this->assertSame( '200', $wc_product->get_regular_price() );
	}

	public function test_variable_product_created_with_variations(): void {
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
					'sku'           => 'SHIRT-S',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '2000',
					'stock'         => 5,
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'SHIRT-M',
					'option1_name'  => 'Size',
					'option1_value' => 'M',
					'price'         => '2000',
					'stock'         => 3,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '7' ]
		);

		$result = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertInstanceOf( WC_Product_Variable::class, $wc_product );
		$this->assertCount( 2, $wc_product->get_children() );

		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		$this->assertNotNull( $variation_id );

		$variation = wc_get_product( $variation_id );
		$this->assertSame( 'SHIRT-S', $variation->get_sku() );
		$this->assertSame( '2000', $variation->get_regular_price() );
		$this->assertSame( 5, $variation->get_stock_quantity() );
	}

	public function test_variable_product_with_only_option2_populated_uses_option2_value(): void {
		// ColorMeのoption1/option2は独立フィールドで、全variantでoption1_nameがnullかつ
		// option2_nameのみ存在するケースが構造的にありうる。`variation_axis_names()`が
		// null軸を`array_values()`で詰め直すと、option2がキー0へ繰り上がり、
		// `axis_values()`/`VariationWriter::variation_attributes()`が誤ってoption1_value
		// （常にnull）を読んでしまい、variationが区別できなくなる不具合を防ぐ回帰テスト。
		$product = new CanonicalProduct(
			'Mug',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'MUG-RED',
					'option1_name'  => null,
					'option1_value' => null,
					'option2_name'  => 'Color',
					'option2_value' => 'Red',
					'price'         => '1500',
					'stock'         => 4,
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'MUG-BLUE',
					'option1_name'  => null,
					'option1_value' => null,
					'option2_name'  => 'Color',
					'option2_value' => 'Blue',
					'price'         => '1500',
					'stock'         => 2,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '15' ]
		);

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );
		$this->assertInstanceOf( WC_Product_Variable::class, $wc_product );

		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		$variation    = wc_get_product( $variation_id );

		$attributes = $variation->get_attributes();
		$this->assertSame( [ 'color' => 'Red' ], $attributes );
	}

	public function test_simple_to_variable_type_change_preserves_id(): void {
		$simple = new CanonicalProduct( 'P', 'SKU-8', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '8' ] );
		$first  = $this->make_writer()->write( $simple, null );

		$variable = new CanonicalProduct(
			'P',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'P-A',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'price'         => '100',
					'stock'         => 1,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '8' ]
		);

		$second = $this->make_writer()->write( $variable, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$this->assertInstanceOf( WC_Product_Variable::class, wc_get_product( $second->local_id ) );
	}

	public function test_simple_to_variable_type_change_clears_stale_stock_management(): void {
		// simple商品時代の`manage_stock=true`+実数量がpostmetaに残ったままだと、variation側の
		// 在庫管理と競合する。`WC_Product_Variable::sync()`は`_price`/`_stock_status`は
		// 子から再計算するが`manage_stock`/`_stock`（数量）は対象外のため、ProductWriter側で
		// 明示的にクリアしないと型変更後も残り続けることを確認する。
		$simple = new CanonicalProduct( 'P', 'SKU-9', '100', null, null, [], [], [], [], 7, 'publish', [ 'remote_id' => '9' ] );
		$first  = $this->make_writer()->write( $simple, null );

		$simple_product = wc_get_product( $first->local_id );
		$this->assertTrue( $simple_product->get_manage_stock() );
		$this->assertSame( 7, $simple_product->get_stock_quantity() );

		$variable = new CanonicalProduct(
			'P',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v2',
					'sku'           => 'P-B',
					'option1_name'  => 'Color',
					'option1_value' => 'Blue',
					'price'         => '100',
					'stock'         => 1,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '9' ]
		);

		$second = $this->make_writer()->write( $variable, $first->local_id );

		$variable_product = wc_get_product( $second->local_id );
		$this->assertInstanceOf( WC_Product_Variable::class, $variable_product );
		$this->assertFalse( $variable_product->get_manage_stock() );
	}

	public function test_variable_to_simple_type_change_removes_orphaned_variations(): void {
		$variable     = new CanonicalProduct(
			'P',
			null,
			'0',
			null,
			null,
			[],
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'P-A',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'price'         => '100',
					'stock'         => 1,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '9' ]
		);
		$first        = $this->make_writer()->write( $variable, null );
		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		$this->assertNotNull( $variation_id );

		$simple = new CanonicalProduct( 'P', 'SKU-9', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '9' ] );
		$second = $this->make_writer()->write( $simple, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$product = wc_get_product( $second->local_id );
		$this->assertNotInstanceOf( WC_Product_Variable::class, $product );

		// 旧variationは削除され、mappingも掃除されている。
		// `wc_get_product()`は削除済みvariationに対しても空のオブジェクトを返すことがある
		// （`WC_Product_Variation_Data_Store_CPT::read()`は投稿が無くても例外を投げず
		// 早期リターンするだけのため）、投稿自体の存在有無で判定する。
		$this->assertNull( get_post( $variation_id ) );
		$this->assertNull( $this->mappings->find_local_id( 'colorme', 'variant', 'v1' ) );
	}

	public function test_exception_during_variation_sync_deletes_orphaned_new_product(): void {
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
					'sku'           => 'SHIRT-S',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '2000',
					'stock'         => 5,
				],
			],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '20' ]
		);

		$blow_up_on_variation_save = static function ( $saved_product ) {
			if ( 'variation' === $saved_product->get_type() ) {
				throw new \RuntimeException( 'simulated variation save failure' );
			}
		};
		add_action( 'woocommerce_before_product_object_save', $blow_up_on_variation_save );

		try {
			$threw = false;

			try {
				$this->make_writer()->write( $product, null );
			} catch ( \RuntimeException $e ) {
				$threw = true;
				$this->assertSame( 'simulated variation save failure', $e->getMessage() );
			}

			$this->assertTrue( $threw, 'Expected the simulated exception to propagate.' );
		} finally {
			remove_action( 'woocommerce_before_product_object_save', $blow_up_on_variation_save );
		}

		// 途中で失敗した新規商品の親レコードが削除されており、孤立商品が残っていないことを確認する。
		$this->assertCount( 0, wc_get_products( [ 'limit' => -1 ] ) );
	}

	public function test_category_ref_pointing_to_deleted_term_is_treated_as_unresolved(): void {
		$category_term_id = wp_insert_term( 'Cat', 'product_cat' )['term_id'];
		$this->seed_mapping( 'colorme', 'category', '10', $category_term_id );
		wp_delete_term( $category_term_id, 'product_cat' );

		$product = new CanonicalProduct( 'P', 'SKU-13', '100', null, null, [], [], [], [ '10' ], null, 'publish', [ 'remote_id' => '13' ] );
		$result  = $this->make_writer()->write( $product, null );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertSame( [], $wc_product->get_category_ids() );
		$this->assertContains( WarningCode::with_detail( WarningCode::CATEGORY_REF_UNRESOLVED, '10' ), $result->warnings );
	}

	public function test_sale_price_below_regular_is_applied(): void {
		$product = new CanonicalProduct( 'P', 'SKU-11', '1000', '800', null, [], [], [], [], null, 'publish', [ 'remote_id' => '11' ] );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '800', $wc_product->get_sale_price() );
		$this->assertSame( '800', $wc_product->get_price() );
	}

	public function test_sale_price_not_below_regular_price_is_ignored(): void {
		// `CanonicalProduct::$sale_price`が通常価格以上・非数値の場合、そのまま適用すると
		// 誤って割引が効く/エラーになるリスクがあるため、セールなし扱いにフォールバックする
		// （WooCommerce自身のREST/管理画面バリデーションと同じ振る舞い）。
		$product = new CanonicalProduct( 'P', 'SKU-12', '1000', '1200', null, [], [], [], [], null, 'publish', [ 'remote_id' => '12' ] );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '', $wc_product->get_sale_price() );
		$this->assertSame( '1000', $wc_product->get_price() );
	}

	public function test_negative_sale_price_is_ignored(): void {
		// 負のセール価格は「非数値」「通常価格以上」いずれの既存ガードにも掛からず
		// すり抜けてしまっていた（-10 < 1000 は真のため）。0以下も無効値として扱う。
		$product = new CanonicalProduct( 'P', 'SKU-16', '1000', '-10', null, [], [], [], [], null, 'publish', [ 'remote_id' => '16' ] );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '', $wc_product->get_sale_price() );
		$this->assertSame( '1000', $wc_product->get_price() );
	}

	public function test_negative_sale_price_is_rejected_with_warning(): void {
		// 拒否された値が存在したこと自体が結果レポートから追跡できるよう、警告が
		// 積まれることを確認する（他の同種フェイルクローズ分岐と同じ規約）。
		$product = new CanonicalProduct( 'P', 'SKU-18', '1000', '-10', null, [], [], [], [], null, 'publish', [ 'remote_id' => '18' ] );

		$result = $this->make_writer()->write( $product, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::SALE_PRICE_INVALID, '-10' ), $result->warnings );
	}

	public function test_negative_regular_price_is_not_applied(): void {
		// `wc_format_decimal()`は符号を検証しないため、負の価格をそのまま`set_regular_price()`
		// に渡すとマイナス価格の商品が公開されてしまう金銭的リスクがある。
		$product = new CanonicalProduct( 'P', 'SKU-19', '-100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '19' ] );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '', $wc_product->get_regular_price() );
		$this->assertContains( WarningCode::with_detail( WarningCode::PRODUCT_PRICE_INVALID, '-100' ), $result->warnings );
	}

	public function test_non_numeric_regular_price_is_not_applied(): void {
		$product = new CanonicalProduct( 'P', 'SKU-20', 'not-a-price', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '20' ] );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '', $wc_product->get_regular_price() );
		$this->assertContains( WarningCode::with_detail( WarningCode::PRODUCT_PRICE_INVALID, 'not-a-price' ), $result->warnings );
	}

	public function test_zero_regular_price_is_accepted_as_a_free_product(): void {
		// 0は正規の無料商品として許可する（拒否対象は非数値・負の値のみ）。
		$product = new CanonicalProduct( 'P', 'SKU-21', '0', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '21' ] );

		$result     = $this->make_writer()->write( $product, null );
		$wc_product = wc_get_product( $result->local_id );

		$this->assertSame( '0', $wc_product->get_regular_price() );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::PRODUCT_PRICE_INVALID ) )
		);
	}

	public function test_stale_existing_local_id_falls_back_to_create(): void {
		// mappingsが指す商品IDが手動削除等で既に存在しない場合を模擬する
		// （実在しない商品IDを直接existing_local_idとして渡す）。
		$product = new CanonicalProduct( 'P', 'SKU-10', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '10' ] );

		$result = $this->make_writer()->write( $product, 999999 );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( 999999, $result->local_id );

		$wc_product = wc_get_product( $result->local_id );
		$this->assertInstanceOf( \WC_Product::class, $wc_product );
		$this->assertSame( 'P', $wc_product->get_name() );
	}

	public function test_stale_id_fallback_cleans_up_orphaned_variations_of_the_old_product(): void {
		// 親商品IDが手動削除等で既に存在しない場合でも、その旧IDに紐づいたまま残っていた
		// 孤立variationが掃除されることを確認する。`wp_delete_post()`経由の削除は
		// WooCommerceが子variationを自動カスケード削除するため、それを経由しない削除
		// （直接DB操作・別プラグイン等）を`$wpdb->delete()`で模擬する。
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Old parent' );
		$parent_id = $parent->save();

		$orphaned_variation = new WC_Product_Variation();
		$orphaned_variation->set_parent_id( $parent_id );
		$orphaned_variation->update_meta_data( '_cbjp_platform', 'colorme' );
		$orphaned_variation->update_meta_data( '_cbjp_remote_id', 'v1' );
		$variation_id = $orphaned_variation->save();
		$this->seed_mapping( 'colorme', 'variant', 'v1', $variation_id );

		global $wpdb;
		$wpdb->delete( $wpdb->posts, [ 'ID' => $parent_id ] );
		clean_post_cache( $parent_id );

		$product = new CanonicalProduct( 'P', 'SKU-23', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '23' ] );
		$result  = $this->make_writer()->write( $product, $parent_id );

		$this->assertNotSame( $parent_id, $result->local_id );
		$this->assertNull( get_post( $variation_id ) );
		$this->assertNull( $this->mappings->find_local_id( 'colorme', 'variant', 'v1' ) );
	}

	public function test_save_failure_bails_out_before_touching_variations(): void {
		// 保存がDB障害等で0を返した場合、WCは例外を投げず黙って未設定のIDのまま返す。
		// その状態でバリエーション同期を走らせると親ID 0の孤立バリエーションを作りかねない
		// ため、ここで打ち切ることを確認する。
		$blocker = static function ( $maybe_empty, $postarr ) {
			return 'product' === ( $postarr['post_type'] ?? null ) ? true : $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $blocker, 10, 2 );

		try {
			$product = new CanonicalProduct( 'P', 'SKU-14', '100', null, null, [], [], [], [], null, 'publish', [ 'remote_id' => '14' ] );
			$result  = $this->make_writer()->write( $product, null );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $blocker, 10 );
		}

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::PRODUCT_SAVE_FAILED, $result->warnings );
	}

	public function test_gallery_images_from_another_platform_are_preserved(): void {
		// D16のリンク再構築ツール等で複数プラットフォームが同一Woo商品を共有しうる。
		// `_cbjp_source_url`の有無だけでギャラリー保持を判定すると、別プラットフォームが
		// 過去に取り込んだ画像も「このwriterが取り込んだもの」と誤認され、今回のASP側
		// 画像リストに無いという理由で消されてしまう。プラットフォームでスコープして
		// 他プラットフォームの画像は残ることを確認する。
		$this->stub_image_http();

		$colorme_product = new CanonicalProduct(
			'P',
			'SKU-17',
			'100',
			null,
			null,
			[
				[
					'src'      => 'https://example.test/colorme-main.png',
					'position' => 0,
				],
				[
					'src'      => 'https://example.test/colorme-gallery.png',
					'position' => 1,
				],
			],
			[],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '17' ]
		);
		$first           = $this->make_writer( 'colorme' )->write( $colorme_product, null );

		$preserved_id = $this->find_attachment_by_source_url( 'https://example.test/colorme-gallery.png' );
		$this->assertGreaterThan( 0, $preserved_id );

		$makeshop_product = new CanonicalProduct(
			'P',
			'SKU-17-mk',
			'100',
			null,
			null,
			[
				[
					'src'      => 'https://example.test/makeshop-main.png',
					'position' => 0,
				],
			],
			[],
			[],
			[],
			null,
			'publish',
			[ 'remote_id' => '17-mk' ]
		);
		$this->make_writer( 'makeshop' )->write( $makeshop_product, $first->local_id );

		$wc_product = wc_get_product( $first->local_id );
		$this->assertContains( $preserved_id, $wc_product->get_gallery_image_ids() );
	}

	private function find_attachment_by_source_url( string $url ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テスト専用ヘルパー。テーブル名のみの埋め込みで値はプレースホルダー経由。
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cbjp_source_url' AND meta_value = %s LIMIT 1", $url ) );
	}
}
