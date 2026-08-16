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
