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

		$attachment_id = ( new MediaImporter( 'colorme' ) )->import( 'https://example.test/image.png', $product_id );

		$this->assertNotNull( $attachment_id );
		$this->assertSame( 'https://example.test/image.png', get_post_meta( $attachment_id, '_cbjp_source_url', true ) );
	}

	public function test_reuses_existing_attachment_for_same_url(): void {
		$this->stub_image_http();

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$importer = new MediaImporter( 'colorme' );
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

		$first  = ( new MediaImporter( 'colorme' ) )->import( 'https://example.test/persisted.png', $product_id );
		$second = ( new MediaImporter( 'colorme' ) )->import( 'https://example.test/persisted.png', $product_id );

		$this->assertNotNull( $first );
		$this->assertSame( $first, $second );
	}

	public function test_does_not_reuse_attachment_imported_by_a_different_platform(): void {
		// URLだけで検索すると、別プラットフォームがたまたま同一URLの画像を取り込んでいた場合に
		// その添付を横取りし、`ProductWriter::apply_images()`が直後に所有プラットフォームを
		// 上書きしてしまう（元の所有プラットフォーム側のギャラリー保持判定が壊れる）。
		$this->stub_image_http();

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$first  = ( new MediaImporter( 'colorme' ) )->import( 'https://example.test/cross-platform.png', $product_id );
		$second = ( new MediaImporter( 'makeshop' ) )->import( 'https://example.test/cross-platform.png', $product_id );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 'colorme', get_post_meta( $first, '_cbjp_platform', true ) );
		$this->assertSame( 'makeshop', get_post_meta( $second, '_cbjp_platform', true ) );
	}

	public function test_imports_image_from_extensionless_url_via_content_detection(): void {
		$this->stub_image_http();

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		// `media_sideload_image()`は拡張子をURLパスの正規表現からしか判定できないため、
		// 拡張子なしURLでは失敗しフォールバック経路（`wp_check_filetype_and_ext()`による
		// ダウンロード済みファイルの内容判定）に入る。
		$attachment_id = ( new MediaImporter( 'colorme' ) )->import( 'https://example.test/image-without-extension', $product_id );

		$this->assertNotNull( $attachment_id );
		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );
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

		$attachment_id = ( new MediaImporter( 'colorme' ) )->import( 'https://example.test/fail.png', $product_id );

		$this->assertNull( $attachment_id );
	}
}
