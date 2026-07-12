<?php
/**
 * アンインストール時のデータ削除処理。
 *
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Core;

/**
 * `cbjp_delete_data_on_uninstall` オプションが true の場合のみテーブル・オプションを削除する。
 */
final class Uninstaller {

	public const DELETE_DATA_OPTION = 'cbjp_delete_data_on_uninstall';

	public static function uninstall(): void {
		if ( ! get_option( self::DELETE_DATA_OPTION, false ) ) {
			return;
		}

		self::drop_tables();
		self::delete_options();
	}

	private static function drop_tables(): void {
		global $wpdb;

		$prefix = $wpdb->prefix;

		foreach ( [ 'cbjp_jobs', 'cbjp_mappings', 'cbjp_logs' ] as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は固定接頭辞+定数、ユーザー入力なし。
			$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
		}
	}

	private static function delete_options(): void {
		global $wpdb;

		delete_option( Activator::DB_VERSION_OPTION );
		delete_option( self::DELETE_DATA_OPTION );

		// オプション名はテーブルと異なり素の `cbjp_` 接頭辞で保存している
		// （cbjp_token_{platform} / cbjp_sample_{platform} / cbjp_rate_limit_{platform} 等）。
		$pattern = $wpdb->esc_like( 'cbjp_' ) . '%';

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		// 生SQLのDELETEはオブジェクトキャッシュを無効化しないため、delete_option() で1件ずつ削除する
		// （永続オブジェクトキャッシュ環境でトークン等が残留しないように）。
		foreach ( $option_names as $option_name ) {
			delete_option( (string) $option_name );
		}
	}
}
