<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools\Link;

use CartBridgeJP\Entities\LinkSource;

/**
 * リンク再構築: 投稿型の実体（商品・バリエーション・クーポン）。取込みが投稿に書く `_cbjp_platform`・`_cbjp_remote_id` から復元する。
 */
final class PostLinkSource extends LinkSource {

	public function __construct(
		private readonly string $key,
		private readonly int $position,
		private readonly string $label,
		private readonly string $post_type
	) {}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return $this->label;
	}

	public function position(): int {
		return $this->position;
	}

	public function scan( string $platform, int $offset, int $limit ): array {
		$ids = get_posts(
			[
				'post_type'      => $this->post_type,
				// ゴミ箱は対象外（リンクを戻すと次回 import が更新経路でゴミ箱の実体を復活させてしまう）。
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
				'meta_query'     => self::ownership_meta_query( $platform ),
			]
		);

		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'post', $ids );

		$rows = [];

		foreach ( $ids as $post_id ) {
			$rows[ $post_id ] = self::meta_string( get_post_meta( $post_id, '_cbjp_remote_id', true ) );
		}

		return [
			'scanned' => count( $ids ),
			'rows'    => $rows,
		];
	}
}
