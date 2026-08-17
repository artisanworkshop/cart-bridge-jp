<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WC_Data;

/**
 * `CanonicalModel::extras` を `_cbjp_{key}` メタとしてWooCommerceのCRUDオブジェクトへ書き込む。
 * キー規約は汎用（`_cbjp_platform` メタが出自プラットフォームを別途持つ）: プラットフォーム固有の
 * `cbjp_colorme_*` のような接頭辞にすると、Woo層がColorMeを知っていることになり
 * アーキテクチャ原則1（プラットフォーム固有コードをアダプタ外に置かない）に反するため。
 */
final class ExtrasMeta {

	private function __construct() {}

	/**
	 * @param array<string,mixed> $extras
	 */
	public static function apply( WC_Data $target, array $extras ): void {
		self::apply_via(
			static fn ( string $meta_key, mixed $value ) => $target->update_meta_data( $meta_key, $value ),
			static fn ( string $meta_key ) => $target->delete_meta_data( $meta_key ),
			$extras
		);
	}

	/**
	 * `apply()`のnull→削除／配列→JSON化／それ以外→生値というブランチングを、任意のメタ
	 * ストレージ（`WC_Data`に限らずWPユーザーメタ等）向けに再利用できる形で提供する。
	 * `CustomerWriter`はWP_Userを扱うため`WC_Data`型限定の`apply()`を直接使えず、
	 * `update_user_meta()`/`delete_user_meta()`をコールバックとして渡す。
	 *
	 * @param callable(string,mixed):mixed $update `fn(string $meta_key, mixed $value): mixed`
	 *   （`update_user_meta()`等、戻り値は使わないがbool|int等を返しうる呼び出し先を許容する）
	 * @param callable(string):mixed       $delete `fn(string $meta_key): mixed`
	 * @param array<string,mixed>          $extras
	 */
	public static function apply_via( callable $update, callable $delete, array $extras ): void {
		foreach ( $extras as $key => $value ) {
			$meta_key = "_cbjp_{$key}";

			if ( null === $value ) {
				$delete( $meta_key );
				continue;
			}

			// 配列（pickups/group_ids/membership等）はget_post_meta()の自動unserializeに
			// 依存させず、checksum比較やCSV出力で扱いやすいJSON文字列として保存する。
			$update( $meta_key, is_array( $value ) ? wp_json_encode( $value ) : $value );
		}
	}
}
