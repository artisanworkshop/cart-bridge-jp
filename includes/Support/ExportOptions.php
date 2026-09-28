<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

/**
 * プラットフォーム単位のエクスポート設定（オプション `cbjp_export_options_{platform}`。D24）。
 *
 * 現在のキーは `push_images`（商品画像をアップロードするか）のみ。プレミアムプラン限定でベータ版の機能は
 * 既定オフ（店舗が Export タブで明示的に選んだときだけ動かす）のため、読取は**実際の真偽値 `true` だけ**を
 * オンとして扱い、欠損・壊れた値（`'true'`・`1`・配列・`stdClass` 等）は全てオフに倒す（フェイルクローズ。
 * `(bool)` キャストは `'false'` を true にしてしまうため使わない）。
 *
 * `cbjp_settings_{platform}`（マッピング。`Woo\Support\MethodMap` と `Admin\RestController` が全置換で書く）とは
 * 別のオプションにしてある: あちらは4つのマップキーだけを読み書きするため、同じオプションに載せると
 * マッピング保存で本設定が消える。アンインストール時は `Core\Uninstaller` が `cbjp_` 接頭辞のオプションを全削除する。
 */
final class ExportOptions {

	private const KEY_PUSH_IMAGES = 'push_images';

	public static function option_name( string $platform ): string {
		return "cbjp_export_options_{$platform}";
	}

	public static function push_images_enabled( string $platform ): bool {
		return true === ( self::read( $platform )[ self::KEY_PUSH_IMAGES ] ?? null );
	}

	/**
	 * 他のキーは保持する（将来の設定追加で既存の値を消さないため）。
	 */
	public static function save_push_images( string $platform, bool $enabled ): void {
		$options                          = self::read( $platform );
		$options[ self::KEY_PUSH_IMAGES ] = $enabled;

		update_option( self::option_name( $platform ), $options, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function read( string $platform ): array {
		$stored = get_option( self::option_name( $platform ), [] );

		return is_array( $stored ) ? $stored : [];
	}
}
