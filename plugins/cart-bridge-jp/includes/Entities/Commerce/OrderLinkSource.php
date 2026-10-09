<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use Automattic\WooCommerce\Utilities\OrderUtil;
use CartBridgeJP\Entities\LinkSource;
use WC_Order;

/**
 * リンク再構築: 受注。取込みが受注に書く `_cbjp_platform`・`_cbjp_remote_order_number`（= `CanonicalOrder::remote_id()`）から復元する。
 *
 * 受注は要件どおり WooCommerce CRUD 経由。`meta_query` を解釈するのは HPOS の `OrdersTableQuery` だけで、
 * レガシー（投稿型）ストレージでは WC 9.2+ が「非対応引数」として無視し `doing_it_wrong` を出す
 * （`WC_Order_Data_Store_CPT::query()`）。そのため絞り込みは HPOS 有効時の最適化としてのみ付け、
 * どちらの構成でも取得後に `_cbjp_platform` を検証したものだけを upsert 対象にする（レガシー構成では
 * 全受注を offset ページングで走査することになるが、他プラットフォーム由来の受注を誤って紐付けない）。
 */
final class OrderLinkSource extends LinkSource {

	public function key(): string {
		return 'order';
	}

	public function label(): string {
		return __( 'Orders', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 70;
	}

	public function scan( string $platform, int $offset, int $limit ): array {
		$args = [
			'type'    => 'shop_order',
			'limit'   => $limit,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		];

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
			$args['meta_query'] = self::ownership_meta_query( $platform );
		}

		$orders  = wc_get_orders( $args );
		$scanned = 0;
		$rows    = [];

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			++$scanned;

			if ( $order->get_meta( '_cbjp_platform' ) === $platform ) {
				$rows[ $order->get_id() ] = self::meta_string( $order->get_meta( '_cbjp_remote_order_number' ) );
			}
		}

		return [
			'scanned' => $scanned,
			'rows'    => $rows,
		];
	}
}
