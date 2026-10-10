<?php
/**
 * アンインストール時のデータ削除処理。
 *
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Core;

/**
 * `cbjp_delete_data_on_uninstall` オプションが `'yes'` の場合のみテーブル・オプションを削除する（既定は残す）。
 *
 * 設定は Tools タブ（`GET/PUT /settings/uninstall`。R3-5a）で変える。値は `'yes'`/`'no'` の文字列で保存し、読むときは
 * `'yes'` だけを「削除する」とみなす（フェイルクローズ）: `update_option( true )` は DB に `'1'` で入り、別のリクエストの
 * `get_option()` は文字列を返すので真偽値では比べられない。truthy で判定すると、壊れた値（`'false'`・配列など）まで削除に倒れる。
 *
 * `uninstall.php` は vendor/autoload.php の無いチェックアウトでも、このクラスと `Activator` だけを読み込んで呼ぶ。
 * ほかのクラス（`Sync\JobManager` など）を参照しないこと。
 */
final class Uninstaller {

	public const DELETE_DATA_OPTION = 'cbjp_delete_data_on_uninstall';

	/**
	 * Action Scheduler のグループ（`Sync\JobManager::ACTION_GROUP`・`Sync\LogCleanup` と同じ値。上の理由で参照せず書き写す。
	 * 一致は `UninstallerTest` が確かめる）。
	 */
	public const ACTION_GROUP = 'cart-bridge-jp';

	private const ENABLED  = 'yes';
	private const DISABLED = 'no';

	public static function uninstall(): void {
		if ( ! self::delete_data_enabled() ) {
			return;
		}

		self::unschedule_actions();
		self::drop_tables();
		self::delete_options();
	}

	public static function delete_data_enabled(): bool {
		return self::ENABLED === get_option( self::DELETE_DATA_OPTION, self::DISABLED );
	}

	public static function set_delete_data( bool $enabled ): void {
		update_option( self::DELETE_DATA_OPTION, $enabled ? self::ENABLED : self::DISABLED, false );
	}

	/**
	 * 未実行のジョブ・ログ整理のアクションを取り消す。テーブルを消した後に残ると、コールバックの無いまま失敗として積まれる
	 * （プラグインのファイルが消えた後に実行されるため）。WooCommerce が無効なら Action Scheduler も無いので何もしない。
	 */
	private static function unschedule_actions(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], self::ACTION_GROUP );
		}
	}

	private static function drop_tables(): void {
		global $wpdb;

		$prefix = $wpdb->prefix;

		foreach ( [ 'cbjp_jobs', 'cbjp_mappings', 'cbjp_logs', 'cbjp_dry_run_items', 'cbjp_push_intents' ] as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は固定接頭辞+定数、ユーザー入力なし。
			$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
		}
	}

	private static function delete_options(): void {
		global $wpdb;

		delete_option( Activator::DB_VERSION_OPTION );
		delete_option( self::DELETE_DATA_OPTION );

		// オプション名はテーブルと異なり素の `cbjp_` 接頭辞で保存している
		// （cbjp_token_{platform} / cbjp_rate_limit_{platform} 等。R3-6a より前の版が残した
		// cbjp_sample_{platform} / cbjp_export_sample_{platform} もここで消える）。
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
