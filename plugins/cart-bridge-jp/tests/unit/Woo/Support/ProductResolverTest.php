<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\Writer\ProductWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;

/**
 * SKU で引くのは、整形（`SkuGuard::normalize()`。R3-5b）で変わらない型番だけ（`SkuGuard::lookup_key()`）。
 */
final class ProductResolverTest extends WooTestCase {

	private function import_product( string $remote_id, string $sku ): int {
		$writer = new ProductWriter( 'colorme', $this->mappings, new VariationWriter( 'colorme', $this->mappings ), new MediaImporter( 'colorme' ) );
		$result = $writer->write( new CanonicalProduct( 'Mug', $sku, '1000', null, null, [], [], [], [], 1, 'publish', [ 'remote_id' => $remote_id ] ), null );

		// mapping では引かせない（SKU の経路だけを確かめる）。
		$this->mappings->delete_one( 'colorme', 'product', $remote_id );

		return $result->local_id;
	}

	public function test_a_plain_model_number_finds_the_product_by_sku(): void {
		$product_id = $this->import_product( '91', 'MUG-91' );
		$resolver   = new ProductResolver( 'colorme', $this->mappings );

		$this->assertSame( $product_id, $resolver->resolve_by_sku_or_remote_id( 'MUG-91', null )?->get_id() );
		$this->assertSame( $product_id, $resolver->resolve_stock_target( null, 'missing', 'MUG-91' )?->get_id() );
	}

	/**
	 * 整形で同じ SKU になる別の型番（後ろの空白・タグ）で、別の商品に結び付けない（review-loop R1-L5）。
	 */
	public function test_a_model_number_that_cleaning_changes_does_not_match_another_product(): void {
		$this->import_product( '92', 'AB' );
		$resolver = new ProductResolver( 'colorme', $this->mappings );

		foreach ( [ 'AB ', ' AB', '<b>AB</b>', 'AB%41' ] as $raw ) {
			$this->assertNull( $resolver->resolve_by_sku_or_remote_id( $raw, null ), $raw );
			$this->assertNull( $resolver->resolve_stock_target( null, 'missing', $raw ), $raw );
		}
	}

	/**
	 * WooCommerce は空の SKU でも照合するので、空になる型番では引かない（SKU の無い自前の商品に当たる。review-loop R1-2）。
	 */
	public function test_a_model_number_that_cleans_to_nothing_finds_no_product_without_a_sku(): void {
		$product_id = $this->import_product( '93', '<b></b>' );
		$this->assertSame( '', wc_get_product( $product_id )->get_sku() );

		$resolver = new ProductResolver( 'colorme', $this->mappings );

		foreach ( [ '', '   ', '<b></b>', '<script>x</script>' ] as $raw ) {
			$this->assertNull( $resolver->resolve_by_sku_or_remote_id( $raw, null ), "[{$raw}]" );
			$this->assertNull( $resolver->resolve_stock_target( null, 'missing', $raw ), "[{$raw}]" );
		}
	}
}
