<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MediaImporter;
use WC_Product_Simple;

final class MediaImporterTest extends WooTestCase {

	public function test_imports_image_and_records_source_url(): void {
		$this->stub_image_http();

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$attachment_id = ( new MediaImporter() )->import( 'https://example.test/image.png', $product_id );

		$this->assertNotNull( $attachment_id );
		$this->assertSame( 'https://example.test/image.png', get_post_meta( $attachment_id, '_cbjp_source_url', true ) );
	}

	public function test_reuses_existing_attachment_for_same_url(): void {
		$this->stub_image_http();

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$importer = new MediaImporter();
		$first    = $importer->import( 'https://example.test/same.png', $product_id );
		$second   = $importer->import( 'https://example.test/same.png', $product_id );

		$this->assertNotNull( $first );
		$this->assertSame( $first, $second );
	}

	public function test_reuses_existing_attachment_across_instances_via_db_lookup(): void {
		$this->stub_image_http();

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$first  = ( new MediaImporter() )->import( 'https://example.test/persisted.png', $product_id );
		$second = ( new MediaImporter() )->import( 'https://example.test/persisted.png', $product_id );

		$this->assertNotNull( $first );
		$this->assertSame( $first, $second );
	}

	public function test_returns_null_on_http_failure(): void {
		add_filter(
			'pre_http_request',
			static fn () => new \WP_Error( 'http_request_failed', 'boom' ),
			10,
			3
		);

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$attachment_id = ( new MediaImporter() )->import( 'https://example.test/fail.png', $product_id );

		$this->assertNull( $attachment_id );
	}
}
