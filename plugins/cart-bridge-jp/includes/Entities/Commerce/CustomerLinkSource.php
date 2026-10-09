<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Entities\LinkSource;

/**
 * リンク再構築: 顧客（WP ユーザー）。取込みがユーザーに書く `_cbjp_platform`・`_cbjp_remote_id` から復元する。
 */
final class CustomerLinkSource extends LinkSource {

	public function key(): string {
		return 'customer';
	}

	public function label(): string {
		return __( 'Customers', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 60;
	}

	public function scan( string $platform, int $offset, int $limit ): array {
		$ids = get_users(
			[
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
				'meta_key'   => '_cbjp_platform',
				'meta_value' => $platform,
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'fields'     => 'ID',
			]
		);

		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'user', $ids );

		$rows = [];

		foreach ( $ids as $user_id ) {
			$rows[ $user_id ] = self::meta_string( get_user_meta( $user_id, '_cbjp_remote_id', true ) );
		}

		return [
			'scanned' => count( $ids ),
			'rows'    => $rows,
		];
	}
}
