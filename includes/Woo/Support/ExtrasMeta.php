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
		foreach ( $extras as $key => $value ) {
			$meta_key = "_cbjp_{$key}";

			if ( null === $value ) {
				$target->delete_meta_data( $meta_key );
				continue;
			}

			// 配列（pickups/group_ids/membership等）はget_post_meta()の自動unserializeに
			// 依存させず、checksum比較やCSV出力で扱いやすいJSON文字列として保存する。
			$target->update_meta_data( $meta_key, is_array( $value ) ? wp_json_encode( $value ) : $value );
		}
	}
}
