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

		$prefix = $wpdb->esc_like( $wpdb->prefix . 'cbjp_' ) . '%';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix
			)
		);
	}
}
