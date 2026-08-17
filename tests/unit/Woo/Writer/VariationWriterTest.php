<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Writer\VariationWriter;
use WC_Product_Variable;
use WC_Product_Variation;

final class VariationWriterTest extends WooTestCase {

	private function make_parent(): int {
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable product' );
		$product->set_status( 'publish' );

		return $product->save();
	}

	public function test_syncs_variations_and_records_mapping(): void {
		$product_id = $this->make_parent();
		$writer     = new VariationWriter( 'colorme', $this->mappings );

		$warnings = $writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => null,
				],
			],
			[ 'Size' ]
		);

		$this->assertSame( [], $warnings );

		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		$this->assertNotNull( $variation_id );

		$variation = wc_get_product( $variation_id );
		$this->assertFalse( $variation->get_manage_stock() );
		$this->assertSame( 'instock', $variation->get_stock_status() );
	}

	public function test_removes_stale_variation_no_longer_present(): void {
		$product_id = $this->make_parent();
		$writer     = new VariationWriter( 'colorme', $this->mappings );

		$writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'V2',
					'option1_name'  => 'Size',
					'option1_value' => 'M',
					'price'         => '1000',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);

		$product = wc_get_product( $product_id );
		$this->assertCount( 2, $product->get_children() );

		$warnings = $writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);

		$product = wc_get_product( $product_id );
		$this->assertCount( 1, $product->get_children() );
		$this->assertNotEmpty(
			array_filter( $warnings, static fn ( string $w ): bool => str_starts_with( $w, 'variation_removed' ) )
		);
		$this->assertNull( $this->mappings->find_local_id( 'colorme', 'variant', 'v2' ) );
	}

	public function test_does_not_remove_variation_belonging_to_another_platform(): void {
		$product_id = $this->make_parent();

		// 別プラットフォーム（例: makeshop）由来のvariation。remote_idはcolorme側の
		// 同期セットには含まれ得ないが、platformが異なるため削除対象にしてはならない。
		$foreign = new WC_Product_Variation();
		$foreign->set_parent_id( $product_id );
		$foreign->update_meta_data( '_cbjp_platform', 'makeshop' );
		$foreign->update_meta_data( '_cbjp_remote_id', 'foreign-1' );
		$foreign_id = $foreign->save();

		$writer   = new VariationWriter( 'colorme', $this->mappings );
		$warnings = $writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);

		$this->assertEmpty(
			array_filter( $warnings, static fn ( string $w ): bool => str_starts_with( $w, 'variation_removed' ) )
		);
		$this->assertInstanceOf( \WC_Product::class, wc_get_product( $foreign_id ) );

		$product = wc_get_product( $product_id );
		$this->assertContains( $foreign_id, $product->get_children() );
	}

	public function test_incomplete_response_with_missing_remote_id_does_not_remove_other_variations(): void {
		$product_id = $this->make_parent();
		$writer     = new VariationWriter( 'colorme', $this->mappings );

		$writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
				[
					'remote_id'     => 'v2',
					'sku'           => 'V2',
					'option1_name'  => 'Size',
					'option1_value' => 'M',
					'price'         => '1000',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);

		// 今回のレスポンスにはv1のみ含まれ、v2はremote_id自体が欠落した不正なレコードとして
		// 混入している。これは「v2が本当に消えた」のか「レスポンスが単に壊れているだけ」なのか
		// 区別できないため、stale削除自体を中止しv2を残すべき。
		$warnings = $writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
				[
					'remote_id'     => null,
					'sku'           => 'BROKEN',
					'option1_name'  => 'Size',
					'option1_value' => 'L',
					'price'         => '1000',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);

		$product = wc_get_product( $product_id );
		$this->assertCount( 2, $product->get_children() );
		$this->assertNotNull( $this->mappings->find_local_id( 'colorme', 'variant', 'v2' ) );
		$this->assertContains( 'variation_snapshot_incomplete', $warnings );
		$this->assertEmpty(
			array_filter( $warnings, static fn ( string $w ): bool => str_starts_with( $w, 'variation_removed' ) )
		);
	}

	public function test_invalid_price_skips_variant_without_publishing_free_variation(): void {
		$product_id = $this->make_parent();
		$writer     = new VariationWriter( 'colorme', $this->mappings );

		$warnings = $writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => null,
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);

		$this->assertNull( $this->mappings->find_local_id( 'colorme', 'variant', 'v1' ) );
		$this->assertContains( 'variation_price_invalid:v1', $warnings );
		$this->assertContains( 'variation_snapshot_incomplete', $warnings );

		$product = wc_get_product( $product_id );
		$this->assertCount( 0, $product->get_children() );
	}

	public function test_stale_variation_mapping_falls_back_to_create(): void {
		// mappingsが指すvariation投稿が手動削除等で既に存在しない場合を模擬する。
		// `new WC_Product_Variation($id)`は`wc_get_product_object()`と異なり例外を投げず、
		// 早期リターンで気付けないため、既存IDを信用せず新規作成へフォールバックする
		// ことを確認する（フォールバックしないと再同期しても何も永続化されない）。
		$product_id = $this->make_parent();
		$writer     = new VariationWriter( 'colorme', $this->mappings );

		$writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);
		$stale_variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		wp_delete_post( $stale_variation_id, true );

		$writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1500',
					'stock'         => 3,
				],
			],
			[ 'Size' ]
		);

		$new_variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );
		$this->assertNotNull( $new_variation_id );
		$this->assertNotSame( $stale_variation_id, $new_variation_id );

		$variation = wc_get_product( $new_variation_id );
		$this->assertInstanceOf( \WC_Product_Variation::class, $variation );
		$this->assertSame( '1500', $variation->get_regular_price() );
	}

	public function test_save_failure_does_not_persist_stale_mapping(): void {
		$product_id = $this->make_parent();
		$writer     = new VariationWriter( 'colorme', $this->mappings );

		// `WC_Product_Variation::save()`がID 0のまま失敗するケースを、WP標準の
		// `wp_insert_post_empty_content`フィルターで`product_variation`投稿のみ狙って再現する。
		$block_variation_posts = static function ( $maybe_empty, $postarr ) {
			return 'product_variation' === ( $postarr['post_type'] ?? null ) ? true : $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $block_variation_posts, 10, 2 );

		try {
			$warnings = $writer->sync(
				$product_id,
				[
					[
						'remote_id'     => 'v1',
						'sku'           => 'V1',
						'option1_name'  => 'Size',
						'option1_value' => 'S',
						'price'         => '1000',
						'stock'         => 5,
					],
				],
				[ 'Size' ]
			);
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $block_variation_posts, 10 );
		}

		$this->assertContains( 'variation_save_failed:v1', $warnings );
		// 保存に失敗したため、local_id 0を指すmapping行が残ってはならない
		// （残るとStockWriter/ProductResolverの以後の解決が全て存在しない商品ID 0を指す）。
		$this->assertNull( $this->mappings->find_local_id( 'colorme', 'variant', 'v1' ) );
	}

	public function test_reuses_variation_via_mapping_on_second_sync(): void {
		$product_id = $this->make_parent();
		$writer     = new VariationWriter( 'colorme', $this->mappings );

		$writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);
		$first_variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );

		$writer->sync(
			$product_id,
			[
				[
					'remote_id'     => 'v1',
					'sku'           => 'V1',
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1500',
					'stock'         => 5,
				],
			],
			[ 'Size' ]
		);
		$second_variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v1' );

		$this->assertSame( $first_variation_id, $second_variation_id );
		$this->assertSame( '1500', wc_get_product( $second_variation_id )->get_regular_price() );
	}
}
