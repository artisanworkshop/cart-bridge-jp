<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Woo\Support;

use CartBridgeJP\Pro\Woo\Writer\CustomerWriter;
use CartBridgeJP\Woo\Support\EntityOrigin;
use WC_Order;

/**
 * D25「実体は作られた向きにだけ更新する」の顧客（WP ユーザー）・受注の判定（R3-6c1 で `EntityOrigin` から分けた。考え方は `EntityOrigin`）。
 *
 * 顧客は、作成時にだけ書く不変の印`_cbjp_created_by_import`の一致も取込み側に含める（別プラットフォームがメールで採用し直して
 * `_cbjp_platform`が書き換わっても、作成元を見失わない）。エクスポートの Reader（送らない判定）とインポート側のガード
 * （上書きしない判定。種類の `is_linked_by_export()`）は同じ関数を使う。
 */
final class CommerceOrigin {

	private function __construct() {}

	/**
	 * 顧客（WP ユーザー）が`$platform`からの取込みで結ばれているか（リンクの印か作成の印のどちらかが一致）。
	 */
	public static function user_linked_by_import( int $user_id, string $platform ): bool {
		if ( '' === $platform ) {
			return false;
		}

		return get_user_meta( $user_id, '_cbjp_platform', true ) === $platform
			|| get_user_meta( $user_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) === $platform;
	}

	/**
	 * 受注が`$platform`からの取込みで結ばれているか（HPOS でも読めるよう`WC_Order::get_meta()`で読む）。
	 */
	public static function order_linked_by_import( WC_Order $order, string $platform ): bool {
		return '' !== $platform && $order->get_meta( '_cbjp_platform' ) === $platform;
	}

	/**
	 * `OrderWriter::write()`の stale-ID 判定（`wc_get_order() instanceof WC_Order`）と同じ条件で受注を読む。
	 */
	public static function order_linked_by_export( int $order_id, string $platform ): bool {
		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order && ! self::order_linked_by_import( $order, $platform );
	}
}
