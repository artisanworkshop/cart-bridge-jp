<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WP_Error;

/**
 * ASP側の画像URLをメディアライブラリへsideloadする。同一URLの再ダウンロードを避けるため、
 * 添付に `_cbjp_source_url` メタを付けて事前に検索・再利用する。
 */
final class MediaImporter {

	/**
	 * リクエスト内キャッシュ（同一ページ内の複数商品が同じ画像URLを共有するケース）。
	 *
	 * @var array<string,int>
	 */
	private array $resolved = [];

	/**
	 * 画像をsideloadし添付IDを返す。失敗時はnullを返し、呼び出し側が警告を積んで続行する
	 * （1枚の画像取得失敗で商品全体の取込を止めない）。
	 */
	public function import( string $url, int $post_id ): ?int {
		$existing = $this->find_existing( $url );

		if ( null !== $existing ) {
			return $existing;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

		if ( $attachment_id instanceof WP_Error ) {
			$attachment_id = $this->fallback_sideload( $url, $post_id );
		}

		if ( ! is_int( $attachment_id ) ) {
			return null;
		}

		update_post_meta( $attachment_id, '_cbjp_source_url', $url );
		$this->resolved[ $url ] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * `media_sideload_image()` は拡張子をURLパスから正規表現で判定できないと失敗する
	 * （クエリ文字列付きURL・拡張子なしURル等）。`download_url()` で一旦取得し、レスポンスの
	 * Content-Typeからファイル種別を判定して `media_handle_sideload()` に渡すフォールバック。
	 */
	private function fallback_sideload( string $url, int $post_id ): ?int {
		$tmp_file = download_url( $url );

		if ( $tmp_file instanceof WP_Error ) {
			return null;
		}

		$file_array = [
			'name'     => wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?? $url ),
			'tmp_name' => $tmp_file,
		];

		$filetype = wp_check_filetype( $file_array['name'] );

		if ( null === $filetype['ext'] ) {
			wp_delete_file( $tmp_file );
			return null;
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( $attachment_id instanceof WP_Error ) {
			wp_delete_file( $tmp_file );
			return null;
		}

		return $attachment_id;
	}

	private function find_existing( string $url ): ?int {
		if ( isset( $this->resolved[ $url ] ) ) {
			return $this->resolved[ $url ];
		}

		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- meta_value による既存添付の検索。get_posts()のmeta_query経由だとSlowDBQuery警告になるため直接クエリにする。テーブル名のみの埋め込みで値はプレースホルダー経由。
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cbjp_source_url' AND meta_value = %s LIMIT 1",
				$url
			)
		);

		if ( null === $attachment_id ) {
			return null;
		}

		$this->resolved[ $url ] = (int) $attachment_id;

		return $this->resolved[ $url ];
	}
}
