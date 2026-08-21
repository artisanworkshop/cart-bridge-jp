<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\ProductTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\StockTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

final class StockTransformerTest extends WP_UnitTestCase {

	private StockTransformer $transformer;

	public function set_up(): void {
		parent::set_up();
		$this->transformer = new StockTransformer();
	}

	public function test_product_without_variants_yields_a_single_stock_row(): void {
		$raw = $this->product_fixture( 192616831 ); // サンプルTシャツ（在庫管理中、在庫数50）

		$stocks = $this->transformer->transform( $raw );

		$this->assertCount( 1, $stocks );
		$this->assertSame( '192616831', $stocks[0]->product_ref );
		$this->assertNull( $stocks[0]->variant_ref );
		$this->assertSame( ProductTransformer::sku( $raw, '192616831' ), $stocks[0]->sku );
		$this->assertSame( 50, $stocks[0]->quantity );
		$this->assertTrue( $stocks[0]->in_stock );
	}

	public function test_managed_variant_with_missing_stock_count_fails_closed_to_zero(): void {
		$raw = $this->product_fixture( 192616832 ); // サンプルギフトセット（在庫管理中だがバリエーション在庫数は欠損）

		$stocks = $this->transformer->transform( $raw );

		$this->assertCount( 2, $stocks );

		$first = $stocks[0];
		$this->assertSame( '192616832', $first->product_ref );
		$this->assertSame( '1802130612', $first->variant_ref );
		$this->assertSame( 'colorme-192616832-1802130612', $first->sku );
		$this->assertSame( 0, $first->quantity );
		$this->assertFalse( $first->in_stock );
	}

	public function test_unmanaged_variant_has_null_quantity_and_is_treated_as_in_stock(): void {
		$raw = $this->product_fixture( 192817398 ); // フィクスチャ_オプション商品（在庫管理外）

		$stocks = $this->transformer->transform( $raw );

		$this->assertCount( 3, $stocks );

		foreach ( $stocks as $stock ) {
			$this->assertNull( $stock->quantity );
			$this->assertTrue( $stock->in_stock );
		}
	}

	public function test_sku_derivation_matches_product_transformer(): void {
		$raw = $this->product_fixture( 192616832 );

		$stocks           = $this->transformer->transform( $raw );
		$product_variants = ( new ProductTransformer() )->transform( $raw )->variants;

		foreach ( $stocks as $index => $stock ) {
			$this->assertSame( $product_variants[ $index ]['sku'], $stock->sku );
		}
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
