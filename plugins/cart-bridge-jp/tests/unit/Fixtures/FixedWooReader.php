<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Sync\WooReader;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\Reader\ReadPage;

/**
 * テスト用の固定 WooReader。実際のWooCommerceへは問い合わせず、コンストラクタで渡した
 * `ReadItem`一覧をページング（`MockPlatformAdapter`と同じoffset方式）して返す
 * （`Sync\Exporter`/`JobManager`のオーケストレーションをWoo内部から切り離してテストする用）。
 */
final class FixedWooReader implements WooReader {

	private const DEFAULT_PAGE_SIZE = 2;

	/**
	 * @param array<int,ReadItem> $items
	 * @param int $page_size 既定2件（複数ページに分かれる挙動のテスト用）。1ページに収めて
	 *   まとめて処理させたいテスト（例: 同一ページ内の複数アイテムに渡る無料枠の相互作用）は
	 *   `count($items)`以上の値を渡す。
	 */
	public function __construct(
		private readonly array $items = [],
		private readonly int $page_size = self::DEFAULT_PAGE_SIZE
	) {}

	public function read( string $entity, Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$items = $this->items;

		if ( null !== $only_local_ids ) {
			$items = array_values(
				array_filter( $items, static fn ( ReadItem $item ): bool => in_array( $item->local_id, $only_local_ids, true ) )
			);
		}

		$offset = (int) $cursor->get( 'offset', 0 );
		$slice  = array_slice( $items, $offset, $this->page_size );
		$next   = ( $offset + $this->page_size ) < count( $items ) ? new Cursor( [ 'offset' => $offset + $this->page_size ] ) : null;

		return new ReadPage( $slice, $next, count( $items ) );
	}
}
