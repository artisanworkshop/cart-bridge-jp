<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\ProductTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\StockTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use RuntimeException;
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

	public function test_variant_missing_id_is_skipped_instead_of_colliding_on_an_empty_remote_id(): void {
		// idが無いバリエーションを空文字のvariant_refとして通すと、`CanonicalStock::remote_id()`
		// （`variant_ref ?? product_ref`）が別商品の同種バリエーションと衝突しうる。
		$raw                = $this->product_fixture( 192616832 );
		$raw['variants'][0] = array_diff_key( $raw['variants'][0], [ 'id' => true ] );

		$stocks = $this->transformer->transform( $raw );

		$this->assertCount( 1, $stocks );
		$this->assertSame( '1802130613', $stocks[0]->variant_ref );
	}

	public function test_product_missing_id_yields_no_stock_rows(): void {
		// idが無い行を空文字のproduct_refとして通すと、無関係なWoo商品に在庫が誤って
		// 書き込まれたり複数の壊れた行が同一の空remote_idに衝突しうる（variant欠損時と同じ理由）。
		$raw = $this->product_fixture( 192616831 );
		unset( $raw['id'] );

		$stocks = $this->transformer->transform( $raw );

		$this->assertSame( [], $stocks );
	}

	public function test_missing_variants_field_throws_instead_of_yielding_a_product_level_row(): void {
		// `variants`はColorMe APIが常に配列で返すフィールド（バリエーション無しの商品も`[]`）。
		// 欠損・非配列を「バリエーション無し」に丸めて商品レベル1件を返すと、実際は
		// バリエーションを持つ商品の在庫が`StockWriter`に変数商品と判定されて黙ってスキップされ、
		// 在庫が更新されない（`ProductTransformer::variants()`と同じ理由で例外にする）。
		$raw = $this->product_fixture( 192616831 );
		unset( $raw['variants'] );

		$this->expectException( RuntimeException::class );

		$this->transformer->transform( $raw );
	}

	public function test_non_array_variants_field_throws(): void {
		$raw             = $this->product_fixture( 192616831 );
		$raw['variants'] = 'unexpected-string';

		$this->expectException( RuntimeException::class );

		$this->transformer->transform( $raw );
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
