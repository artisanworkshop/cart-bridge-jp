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

	public function __construct( private readonly string $platform ) {}

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
		// `find_existing()`のプラットフォーム絞り込みが新規添付を即座に見つけられるよう、
		// 作成時点でタグ付けする（呼び出し側`ProductWriter::apply_images()`等の再タグ付けは
		// 「同一Woo商品を複数プラットフォームが共有する場合の所有権移譲」用で、これとは別目的）。
		update_post_meta( $attachment_id, '_cbjp_platform', $this->platform );
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

		// `wp_check_filetype()` はファイル名の拡張子のみで判定する。`wp_check_filetype_and_ext()`は
		// ダウンロード済みファイルの内容で検証を行うが、それはファイル名ベースの一次判定が
		// image/*型に一致した場合の「拡張子の食い違い訂正」としてのみ働き、ファイル名に
		// 拡張子が一切無い場合はcontent-sniffステップ自体に入らず空のまま返る。
		// そのため拡張子なしURLは`wp_get_image_mime()`（ファイル内容のみで判定）による
		// 直接のcontentスニッフィングにフォールバックする。
		$filetype = wp_check_filetype_and_ext( $tmp_file, $file_array['name'] );

		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			$real_mime = wp_get_image_mime( $tmp_file );
			$ext       = is_string( $real_mime ) ? wp_get_default_extension_for_mime_type( $real_mime ) : false;

			if ( ! is_string( $real_mime ) || ! is_string( $ext ) ) {
				wp_delete_file( $tmp_file );
				return null;
			}

			$filetype = [
				'ext'             => $ext,
				'type'            => $real_mime,
				'proper_filename' => "{$file_array['name']}.{$ext}",
			];
		}

		// 判定結果の拡張子をファイル名へ反映しないと、`media_handle_sideload()` 内部の
		// 再チェック（ファイル名ベース）で再び失敗しうる。
		if ( ! empty( $filetype['proper_filename'] ) ) {
			$file_array['name'] = $filetype['proper_filename'];
		} elseif ( ! str_ends_with( $file_array['name'], ".{$filetype['ext']}" ) ) {
			$file_array['name'] .= ".{$filetype['ext']}";
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

		// `_cbjp_platform`でも一致する添付のみを再利用する。URLだけで検索すると、異なる
		// プラットフォームの商品がたまたま同一の画像URL（共通CDN等）を参照している場合に
		// 他プラットフォームが取り込んだ添付を横取りしてしまい、`ProductWriter::apply_images()`
		// が直後に`_cbjp_platform`を自分自身へ上書きしてしまう（元の所有プラットフォーム側の
		// ギャラリー保持判定が壊れる）。
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- meta_value による既存添付の検索。get_posts()のmeta_query経由だとSlowDBQuery警告になるため直接クエリにする。テーブル名のみの埋め込みで値はプレースホルダー経由。
				"SELECT source.post_id FROM {$wpdb->postmeta} source
					INNER JOIN {$wpdb->postmeta} platform
						ON platform.post_id = source.post_id
						AND platform.meta_key = '_cbjp_platform'
						AND platform.meta_value = %s
					WHERE source.meta_key = '_cbjp_source_url' AND source.meta_value = %s
					LIMIT 1",
				$this->platform,
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
