<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\ProductTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

final class ProductTransformerTest extends WP_UnitTestCase {

	private ProductTransformer $transformer;

	public function set_up(): void {
		parent::set_up();
		$this->transformer = new ProductTransformer();
	}

	public function test_transforms_simple_product_with_model_number_and_description(): void {
		$raw = $this->product_fixture( 192616831 ); // サンプルTシャツ

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'サンプルTシャツ', $product->name );
		$this->assertSame( 'SAMPLE-TSHIRT-001', $product->sku );
		$this->assertSame( '3300', $product->price );
		$this->assertNull( $product->sale_price );
		$this->assertSame( 'テスト用の商品説明です。', $product->description );
		$this->assertSame( 'テスト用簡易説明', $product->extras['short_description'] );
		$this->assertSame( 50, $product->stock );
		$this->assertSame( 'publish', $product->status );
		$this->assertSame( [ '2993030' ], $product->category_refs );
		$this->assertSame( '192616831', $product->extras['remote_id'] );
		$this->assertSame( 3000, $product->extras['list_price'] );
		$this->assertSame( true, $product->extras['stock_managed'] );
	}

	public function test_unmanaged_stock_is_not_treated_as_out_of_stock(): void {
		// `stock_managed: false`の商品は在庫管理をしていないため、rawの`stocks`値は在庫切れ判定に
		// 使わない。stockをnullにし、Importerに「無制限＝在庫あり」と判断させる
		// （`includes/Sync/Importer.php`はstockがnullの商品を在庫ありとして扱う）。
		$raw = $this->product_fixture( 192817398 );

		$this->assertFalse( $raw['stock_managed'] );
		$this->assertSame( 45, $raw['stocks'] );

		$product = $this->transformer->transform( $raw );

		$this->assertNull( $product->stock );
	}

	public function test_unlisted_flag_is_preserved_independently_of_display_state(): void {
		$raw             = $this->product_fixture( 192616831 );
		$raw['unlisted'] = true;

		$product = $this->transformer->transform( $raw );

		// unlistedはdisplay_stateと独立: 「掲載中」のままpublishになるが、フラグはextrasに残す
		// （F1-4がWooのカタログ可視性=hiddenへマッピングできるように）。
		$this->assertSame( 'publish', $product->status );
		$this->assertTrue( $product->extras['unlisted'] );
	}

	public function test_sku_falls_back_to_colorme_prefixed_id_when_model_number_missing(): void {
		// フィクスチャ「フィクスチャ_オプション商品」はmodel_numberがnull。
		$raw = $this->product_fixture( 192817398 );

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'colorme-192817398', $product->sku );
	}

	public function test_hidden_product_is_mapped_to_private_status(): void {
		$raw                  = $this->product_fixture( 192616831 );
		$raw['display_state'] = 'hidden';

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'private', $product->status );
		$this->assertSame( 'hidden', $product->extras['display_state'] );
	}

	public function test_sale_for_members_stays_publicly_listed(): void {
		// swagger: 「掲載状態だが購入は会員のみ可能」。showingと同じく一般公開の掲載状態であり、
		// privateにすると誰にも見えなくなってしまう。
		$raw                  = $this->product_fixture( 192616831 );
		$raw['display_state'] = 'sale_for_members';

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'publish', $product->status );
		$this->assertSame( 'sale_for_members', $product->extras['display_state'] );
	}

	public function test_showing_for_members_is_mapped_to_private_status(): void {
		// swagger: 「会員にのみ掲載」。Wooにネイティブな会員限定掲載機能は無いため、
		// 現状は非公開扱い（private）に丸める（raw display_stateはextrasに残す）。
		$raw                  = $this->product_fixture( 192616831 );
		$raw['display_state'] = 'showing_for_members';

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'private', $product->status );
		$this->assertSame( 'showing_for_members', $product->extras['display_state'] );
	}

	public function test_regular_purchase_product_is_kept_private_regardless_of_display_state(): void {
		// Wooコアにはサブスクリプションの仕組みが無い。通常商品として公開すると一回限りの
		// 購入として売れてしまい、定期収益や継続提供の前提が崩れるため、F1-4がサブスク対応する
		// までprivateに留める。
		$raw                     = $this->product_fixture( 192616831 );
		$raw['regular_purchase'] = true;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'private', $product->status );
		$this->assertTrue( $product->extras['regular_purchase'] );
	}

	public function test_product_outside_its_sale_window_is_kept_private(): void {
		// sale_start_date/sale_end_date（掲載期間）の外側は、display_stateがshowingであっても
		// 今この瞬間は非公開が正しい（ColorMe側でも本来非公開になる想定の時限公開設定）。
		$raw = $this->product_fixture( 192616831 );

		$not_yet_started                    = $raw;
		$not_yet_started['sale_start_date'] = time() + 3600;
		$this->assertSame( 'private', $this->transformer->transform( $not_yet_started )->status );

		$already_ended                  = $raw;
		$already_ended['sale_end_date'] = time() - 3600;
		$this->assertSame( 'private', $this->transformer->transform( $already_ended )->status );

		$within_window                    = $raw;
		$within_window['sale_start_date'] = time() - 3600;
		$within_window['sale_end_date']   = time() + 3600;
		$this->assertSame( 'publish', $this->transformer->transform( $within_window )->status );
	}

	public function test_sold_out_product_hidden_by_shop_setting_is_kept_private(): void {
		// 店舗側が明示的に「売り切れ時は非表示」（soldout_display: false）と設定している場合、
		// 在庫管理中で在庫が0であればWoo側で再露出させず非公開に留める。
		$raw                    = $this->product_fixture( 192616831 );
		$raw['stock_managed']   = true;
		$raw['stocks']          = 0;
		$raw['soldout_display'] = false;
		$raw['display_state']   = 'showing';

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'private', $product->status );
	}

	public function test_sold_out_product_still_shown_when_shop_allows_it(): void {
		// soldout_display: true（売り切れ時も表示）の場合は、既存のdisplay_state判定どおり公開する。
		$raw                    = $this->product_fixture( 192616831 );
		$raw['stock_managed']   = true;
		$raw['stocks']          = 0;
		$raw['soldout_display'] = true;
		$raw['display_state']   = 'showing';

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'publish', $product->status );
	}

	public function test_sold_out_hiding_is_ignored_when_stock_is_not_managed(): void {
		// stock_managed: false の商品はstocksを在庫切れ判定に使わない既存仕様（stock()メソッド）
		// と一貫させ、非表示化にもstocksを使わない。
		$raw                    = $this->product_fixture( 192616831 );
		$raw['stock_managed']   = false;
		$raw['stocks']          = 0;
		$raw['soldout_display'] = false;
		$raw['display_state']   = 'showing';

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'publish', $product->status );
	}

	public function test_product_with_missing_price_is_kept_private_instead_of_free(): void {
		// Cast::money()は解釈できない値を無言で'0'に丸めるため、区別せず通すと本来有料の商品が
		// 無料商品としてWoo側で購入可能になってしまう。
		$raw = $this->product_fixture( 192616831 );
		unset( $raw['sales_price_including_tax'] );

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'private', $product->status );
		$this->assertSame( '0', $product->price );
	}

	public function test_product_with_non_numeric_price_is_kept_private(): void {
		$raw                              = $this->product_fixture( 192616831 );
		$raw['sales_price_including_tax'] = 'invalid';

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'private', $product->status );
	}

	public function test_product_with_genuinely_free_price_still_publishes(): void {
		// 0は正規の無料商品を表す数値であり、欠損・非数値とは区別してpublishのままにする。
		$raw                              = $this->product_fixture( 192616831 );
		$raw['sales_price_including_tax'] = 0;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'publish', $product->status );
		$this->assertSame( '0', $product->price );
	}

	public function test_weight_is_carried_in_grams(): void {
		// swagger: weightはグラム単位。variant側は既にweightを正規モデルに持つため、
		// 商品レベルも同じ単位でCanonicalProduct::weightへ反映する。
		$raw           = $this->product_fixture( 192616831 );
		$raw['weight'] = 250;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 250, $product->weight );
	}

	public function test_null_weight_yields_null(): void {
		$raw = $this->product_fixture( 192616831 );

		$product = $this->transformer->transform( $raw );

		$this->assertNull( $product->weight );
	}

	public function test_product_requires_shipping_by_default(): void {
		$raw = $this->product_fixture( 192616831 );

		$product = $this->transformer->transform( $raw );

		$this->assertTrue( $product->requires_shipping );
	}

	public function test_without_shipping_product_does_not_require_shipping(): void {
		// Wooのネイティブなvirtual商品設定（配送先住所を要求せず送料もかけない）に対応する。
		$raw                     = $this->product_fixture( 192616831 );
		$raw['without_shipping'] = true;

		$product = $this->transformer->transform( $raw );

		$this->assertFalse( $product->requires_shipping );
	}

	public function test_digital_content_product_does_not_require_shipping(): void {
		$raw                    = $this->product_fixture( 192616831 );
		$raw['digital_content'] = true;

		$product = $this->transformer->transform( $raw );

		$this->assertFalse( $product->requires_shipping );
	}

	public function test_unavailable_payment_and_delivery_ids_are_preserved_in_extras(): void {
		$raw                             = $this->product_fixture( 192616831 );
		$raw['unavailable_payment_ids']  = [ 1094475 ];
		$raw['unavailable_delivery_ids'] = [ 640580, 640581 ];

		$product = $this->transformer->transform( $raw );

		$this->assertSame( [ '1094475' ], $product->extras['unavailable_payment_ids'] );
		$this->assertSame( [ '640580', '640581' ], $product->extras['unavailable_delivery_ids'] );
	}

	public function test_sort_order_is_preserved_in_extras(): void {
		$raw         = $this->product_fixture( 192616831 );
		$raw['sort'] = 5;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 5, $product->extras['sort'] );
	}

	public function test_pickups_merchandising_metadata_is_preserved_in_extras(): void {
		$raw            = $this->product_fixture( 192616831 );
		$raw['pickups'] = [
			[
				'pickup_type' => 0,
				'product_id'  => 192616831,
				'order_num'   => 1,
			],
		];

		$product = $this->transformer->transform( $raw );

		$this->assertSame(
			[
				[
					'pickup_type' => 0,
					'order_num'   => 1,
				],
			],
			$product->extras['pickups']
		);
	}

	public function test_pickups_drop_volatile_timestamps_that_would_destabilize_the_checksum(): void {
		// make_date/update_date は実質的な設定変更を伴わずに変動し得る揮発性のタイムスタンプ。
		// 生のまま保持するとCanonicalProduct::checksum()がextras全体をハッシュするため、
		// 意味のない更新のたびに変わっていないWoo商品を毎回書き込み直してしまう。
		$raw            = $this->product_fixture( 192616831 );
		$raw['pickups'] = [
			[
				'pickup_type' => 0,
				'product_id'  => 192616831,
				'account_id'  => 'PA00000001',
				'order_num'   => 1,
				'make_date'   => 1465784944,
				'update_date' => 1494496809,
			],
		];

		$product = $this->transformer->transform( $raw );

		$this->assertSame(
			[
				[
					'pickup_type' => 0,
					'order_num'   => 1,
				],
			],
			$product->extras['pickups']
		);
	}

	public function test_two_axis_variants_use_option_objects_not_title(): void {
		$raw = FixtureLoader::load( 'colorme', 'product_variant_detail' )['product'];

		$product = $this->transformer->transform( $raw );

		$this->assertNotEmpty( $product->variants );
		$first = $product->variants[0];

		$this->assertSame( 'カラー', $first['option1_name'] );
		$this->assertSame( '赤', $first['option1_value'] );
		$this->assertSame( 'サイズ', $first['option2_name'] );
		$this->assertSame( 'S', $first['option2_value'] );
		$this->assertSame( '4950', $first['price'] );
		// このフィクスチャは商品レベルで `stock_managed: false`（在庫管理無効）のため、
		// バリエーションの`stocks`値があっても在庫切れ判定には使わない。
		$this->assertNull( $first['stock'] );
		$this->assertSame( 4950, $first['members_price_including_tax'] );
	}

	public function test_variant_stock_is_preserved_when_stock_management_is_enabled(): void {
		$raw                  = FixtureLoader::load( 'colorme', 'product_variant_detail' )['product'];
		$raw['stock_managed'] = true;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 20, $product->variants[0]['stock'] );
	}

	public function test_variant_specific_overrides_are_preserved_not_just_product_level_values(): void {
		$raw                               = FixtureLoader::load( 'colorme', 'product_variant_detail' )['product'];
		$raw['variants'][0]['few_num']     = 3;
		$raw['variants'][0]['option_cost'] = 1200;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 3, $product->variants[0]['few_num'] );
		$this->assertSame( 1200, $product->variants[0]['cost'] );
	}

	public function test_single_axis_variant_has_null_second_option(): void {
		$raw = FixtureLoader::load( 'colorme', 'product_option_detail' )['product'];

		$product = $this->transformer->transform( $raw );

		$first = $product->variants[0];

		$this->assertSame( 'サイズ', $first['option1_name'] );
		$this->assertNull( $first['option2_name'] );
		$this->assertNull( $first['option2_value'] );
		$this->assertSame(
			[
				[
					'name'   => 'サイズ',
					'values' => [ 'S', 'M', 'L' ],
				],
			],
			$product->options
		);
	}

	public function test_option_value_of_zero_is_not_dropped(): void {
		// コールバック無しのarray_filterは'0'のようなfalsy文字列も落ちてしまう罠の回帰テスト。
		$raw            = $this->product_fixture( 192616831 );
		$raw['options'] = [
			[
				'name'   => 'サイズ',
				'values' => [ '0', 'M', '' ],
			],
		];

		$product = $this->transformer->transform( $raw );

		$this->assertSame( [ '0', 'M' ], $product->options[0]['values'] );
	}

	public function test_group_id_of_zero_is_not_dropped_from_extras(): void {
		$raw              = $this->product_fixture( 192616831 );
		$raw['group_ids'] = [ 0, 3197761 ];

		$product = $this->transformer->transform( $raw );

		$this->assertSame( [ '0', '3197761' ], $product->extras['group_ids'] );
		$this->assertSame( [ '0', '3197761' ], $product->tag_refs );
	}

	public function test_group_ids_are_mapped_to_tag_refs(): void {
		// docs/01-plan-colorme.md の対応表通り group_ids は TagTransformer が作るタグの
		// remote_id一覧としてそのまま使えるため、tag_refs経由でアダプタ非依存のWoo writerに渡す。
		$raw              = $this->product_fixture( 192616831 );
		$raw['group_ids'] = [ 3197760, 3197761 ];

		$product = $this->transformer->transform( $raw );

		$this->assertSame( [ '3197760', '3197761' ], $product->tag_refs );
	}

	public function test_null_group_ids_yields_empty_tag_refs(): void {
		$raw              = $this->product_fixture( 192616831 );
		$raw['group_ids'] = null;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( [], $product->tag_refs );
	}

	public function test_variant_sku_falls_back_when_model_number_is_empty_string(): void {
		$raw = $this->product_fixture( 192616832 ); // サンプルギフトセット, variant model_number: ""

		$product = $this->transformer->transform( $raw );

		$this->assertSame( 'colorme-192616832-1802130612', $product->variants[0]['sku'] );
	}

	public function test_null_category_yields_empty_category_refs(): void {
		$raw             = $this->product_fixture( 192616831 );
		$raw['category'] = null;

		$product = $this->transformer->transform( $raw );

		$this->assertSame( [], $product->category_refs );
	}

	public function test_main_image_and_non_mobile_extra_images_are_kept_mobile_duplicates_dropped(): void {
		$raw              = $this->product_fixture( 192616831 );
		$raw['image_url'] = 'https://img.example.com/main.jpg';
		$raw['images']    = [
			[
				'src'      => 'https://img.example.com/pc-1.jpg',
				'position' => 1,
				'mobile'   => false,
			],
			[
				'src'      => 'https://img.example.com/mobile-1.jpg',
				'position' => 1,
				'mobile'   => true,
			],
		];

		$product = $this->transformer->transform( $raw );

		$this->assertSame(
			[
				[
					'src'      => 'https://img.example.com/main.jpg',
					'position' => 0,
				],
				[
					'src'      => 'https://img.example.com/pc-1.jpg',
					'position' => 1,
				],
			],
			$product->images
		);
	}

	public function test_group_ids_are_stashed_in_extras_not_dropped(): void {
		$raw              = $this->product_fixture( 192616831 );
		$raw['group_ids'] = [ 3197760, 3197761 ];

		$product = $this->transformer->transform( $raw );

		$this->assertSame( [ '3197760', '3197761' ], $product->extras['group_ids'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function product_fixture( int $id ): array {
		foreach ( FixtureLoader::load( 'colorme', 'products' )['products'] as $product ) {
			if ( $id === $product['id'] ) {
				return $product;
			}
		}

		$this->fail( "Fixture product {$id} not found." );
	}
}
