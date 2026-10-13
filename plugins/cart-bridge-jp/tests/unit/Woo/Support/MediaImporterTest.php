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
		// `media_sideload_image()` と同じく、元の URL（WP の慣習）と URL の名前を残す（R3-5b G3-1 で取得の経路を 1 つにした）。
		$this->assertSame( 'https://example.test/image.png', get_post_meta( $attachment_id, '_source_url', true ) );
		$this->assertStringStartsWith( 'image', wp_basename( (string) get_attached_file( $attachment_id ) ) );
		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );
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

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public static function non_image_cases(): array {
		return [
			// 管理者の Cookie で動く非同期ランナーでは `text/html` が許されたファイルの種類になる。
			'html as an administrator' => [ 'https://example.test/page.html', 'text/html', "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n", true ],
			// WP-Cron（ユーザー 0）でも PDF は許される。
			'pdf as nobody'            => [ 'https://example.test/file.pdf', 'application/pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n", false ],
			// SVG を許すプラグインのあるサイト（下の `upload_mimes`）。`wp_get_image_mime()` は SVG を判定できない（R1-L6）。
			'svg where svg is allowed' => [ 'https://example.test/icon.svg', 'image/svg+xml', "<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1\" height=\"1\"><script>alert(1)</script></svg>\n", true ],
			// 画像の拡張子で中身が HTML（G3-1）。
			'html named as a jpeg'     => [ 'https://example.test/payload.jpg', 'image/jpeg', "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n", true ],
		];
	}

	/**
	 * R3-5b: 画像の URL が画像でなければ保存しない（ASP の応答の URL から HTML をサイトと同じオリジンに置かせない）。
	 *
	 * @dataProvider non_image_cases
	 */
	public function test_does_not_save_a_file_that_is_not_an_image( string $url, string $content_type, string $body, bool $as_admin ): void {
		if ( $as_admin ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		}

		add_filter(
			'upload_mimes',
			static function ( array $mimes ): array {
				$mimes['svg'] = 'image/svg+xml';

				return $mimes;
			}
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $parsed_args ) use ( $content_type, $body ) {
				if ( ! empty( $parsed_args['filename'] ) ) {
					file_put_contents( $parsed_args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テストのHTTPスタブ用一時ファイル書込。
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'headers'  => [ 'content-type' => $content_type ],
					'body'     => empty( $parsed_args['filename'] ) ? $body : '',
				];
			},
			10,
			2
		);

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$before = (int) wp_count_posts( 'attachment' )->inherit;

		$attachment_id = ( new MediaImporter( 'colorme' ) )->import( $url, $product_id );

		$this->assertNull( $attachment_id );
		$this->assertSame( $before, (int) wp_count_posts( 'attachment' )->inherit );
	}

	/**
	 * サイトが `image_sideload_extensions` に SVG を足していても SVG を保存しない（R3-5b G1-1。取得は中身を確かめる経路だけにした。G3-1）。
	 */
	public function test_does_not_save_svg_even_if_the_site_allows_svg_sideloads(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$svg = "<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1\" height=\"1\"><script>alert(1)</script></svg>\n";

		add_filter(
			'upload_mimes',
			static function ( array $mimes ): array {
				$mimes['svg'] = 'image/svg+xml';

				return $mimes;
			}
		);
		add_filter(
			'image_sideload_extensions',
			static function ( array $extensions ): array {
				$extensions[] = 'svg';

				return $extensions;
			}
		);
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $parsed_args ) use ( $svg ) {
				if ( ! empty( $parsed_args['filename'] ) ) {
					file_put_contents( $parsed_args['filename'], $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テストのHTTPスタブ用一時ファイル書込。
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'headers'  => [ 'content-type' => 'image/svg+xml' ],
					'body'     => empty( $parsed_args['filename'] ) ? $svg : '',
				];
			},
			10,
			2
		);

		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$product_id = $product->save();

		$before = (int) wp_count_posts( 'attachment' )->inherit;

		$this->assertNull( ( new MediaImporter( 'colorme' ) )->import( 'https://example.test/icon.svg', $product_id ) );
		$this->assertSame( $before, (int) wp_count_posts( 'attachment' )->inherit );
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
