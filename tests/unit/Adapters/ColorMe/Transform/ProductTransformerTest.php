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
		$this->assertSame( 20, $first['stock'] );
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
