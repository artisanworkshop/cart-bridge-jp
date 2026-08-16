<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\MappingRepository;
use WP_UnitTestCase;

/**
 * Woo書込テストの共通基盤。cbjp_*テーブルの用意とmappingsシードのヘルパーを提供する。
 */
abstract class WooTestCase extends WP_UnitTestCase {

	protected MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings = new MappingRepository();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	protected function seed_mapping( string $platform, string $entity_type, string $remote_id, int $local_id ): void {
		$this->mappings->upsert( $platform, $entity_type, $remote_id, $local_id, null );
	}

	/**
	 * `media_sideload_image()`/`download_url()`が使う `pre_http_request` を1x1 PNGでスタブする。
	 * 実ネットワークを叩かずに画像取込のパスを検証する。
	 *
	 * `download_url()` は `stream => true` + `filename` 指定でリクエストするため、通常の
	 * `pre_http_request` ショートサーキットは（コアのストリーム書込処理を素通りしてしまい）
	 * `filename`が空ファイルのまま残る。`$parsed_args['filename']`があればそこへ直接書き込む。
	 */
	protected function stub_image_http(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $parsed_args ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 難読化目的ではなく、テスト用1x1 PNGバイナリの埋め込み。
				$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );

				if ( ! empty( $parsed_args['filename'] ) ) {
					file_put_contents( $parsed_args['filename'], $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テストのHTTPスタブ用一時ファイル書込。
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'headers'  => [ 'content-type' => 'image/png' ],
					'body'     => empty( $parsed_args['filename'] ) ? $png : '',
				];
			},
			10,
			2
		);
	}
}
