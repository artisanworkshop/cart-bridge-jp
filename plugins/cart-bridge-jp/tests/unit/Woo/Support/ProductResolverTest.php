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
 * SKU で引く側も、保存した SKU と同じ形（`SkuGuard::normalize()`。R3-5b）で引く。
 */
final class ProductResolverTest extends WooTestCase {

	private function import_product( string $remote_id, string $sku ): int {
		$writer = new ProductWriter( 'colorme', $this->mappings, new VariationWriter( 'colorme', $this->mappings ), new MediaImporter( 'colorme' ) );
		$result = $writer->write( new CanonicalProduct( 'Mug', $sku, '1000', null, null, [], [], [], [], 1, 'publish', [ 'remote_id' => $remote_id ] ), null );

		// mapping では引かせない（SKU の経路だけを確かめる）。
		$this->mappings->delete_one( 'colorme', 'product', $remote_id );

		return $result->local_id;
	}

	public function test_an_order_line_finds_the_product_by_the_raw_model_number(): void {
		$product_id = $this->import_product( '91', '  <b>MUG-91</b> ' );
		$resolver   = new ProductResolver( 'colorme', $this->mappings );

		$found = $resolver->resolve_by_sku_or_remote_id( '  <b>MUG-91</b> ', null );

		$this->assertNotNull( $found );
		$this->assertSame( $product_id, $found->get_id() );
	}

	public function test_a_stock_row_finds_the_product_by_the_raw_model_number(): void {
		$product_id = $this->import_product( '92', '<i>MUG-92</i>' );
		$resolver   = new ProductResolver( 'colorme', $this->mappings );

		$found = $resolver->resolve_stock_target( null, 'missing', '<i>MUG-92</i>' );

		$this->assertNotNull( $found );
		$this->assertSame( $product_id, $found->get_id() );
	}

	public function test_a_model_number_that_cleans_to_nothing_finds_nothing(): void {
		$resolver = new ProductResolver( 'colorme', $this->mappings );

		$this->assertNull( $resolver->resolve_by_sku_or_remote_id( '<script>x</script>', null ) );
		$this->assertNull( $resolver->resolve_stock_target( null, 'missing', '   ' ) );
	}
}
