<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures\Gizmo;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\Reader\ReadPage;
use CartBridgeJP\Woo\Support\EntityOrigin;

/**
 * gizmo の投稿を読む（エクスポート）。`_gizmo_blocked` のある投稿には止める警告を付ける。
 */
final class GizmoReader implements EntityReader {

	public function __construct( private readonly string $platform ) {}

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$ids   = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- テスト用のフィクスチャ。
				'meta_key'       => '_gizmo',
			]
		);
		$items = [];

		foreach ( array_map( 'intval', $ids ) as $post_id ) {
			$items[] = new ReadItem(
				$post_id,
				new CanonicalGizmo( 'local:' . $post_id, (string) get_the_title( $post_id ), (int) get_post_meta( $post_id, '_gizmo_amount', true ) ),
				'' !== get_post_meta( $post_id, '_gizmo_blocked', true ) ? [ 'gizmo_blocked' ] : [],
				true,
				[],
				EntityOrigin::post_linked_by_import( $post_id, $this->platform )
			);
		}

		return new ReadPage( $items, null, count( $items ) );
	}
}
