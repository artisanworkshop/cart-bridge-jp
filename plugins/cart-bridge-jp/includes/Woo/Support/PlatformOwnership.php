<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * `_cbjp_platform`メタによる「このレコードは自分（呼び出し元Writerのplatform）が
 * 管理しているものか」の判定を共有する。既存Wooデータ（店舗独自データ・別プラットフォーム
 * 由来のデータ）を誤って上書き/削除しないためのガードとして、コード重複を避けつつ
 * `Writer\CouponWriter`/`Writer\TermWriter`/`Writer\VariationWriter`で共用する。
 */
final class PlatformOwnership {

	private function __construct() {}

	public static function owns_post( int $post_id, string $platform ): bool {
		return get_post_meta( $post_id, '_cbjp_platform', true ) === $platform;
	}

	public static function owns_term( int $term_id, string $platform ): bool {
		return get_term_meta( $term_id, '_cbjp_platform', true ) === $platform;
	}
}
