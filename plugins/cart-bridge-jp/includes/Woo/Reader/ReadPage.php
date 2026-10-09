<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;

/**
 * `EntityReader::query()` が返す1ページ分の結果。`Adapters\Page` のWoo読出側対称形
 * （要素が `CanonicalModel` ではなく警告付きの `ReadItem` である点のみ異なる）。
 */
final readonly class ReadPage {

	/**
	 * @param array<int,ReadItem> $items
	 * @param int|null            $total 取得可能な場合のみ（進捗率表示用）。
	 */
	public function __construct(
		public array $items,
		public ?Cursor $next_cursor,
		public ?int $total = null
	) {}
}
