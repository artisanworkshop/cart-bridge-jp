<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

use CartBridgeJP\Canonical\CanonicalModel;

/**
 * fetch* メソッドが返す1ページ分の結果。
 */
final readonly class Page {

	/**
	 * @param array<int,CanonicalModel> $items
	 * @param int|null                  $total 取得可能な場合のみ（進捗率表示用）。
	 */
	public function __construct(
		public array $items,
		public ?Cursor $next_cursor,
		public ?int $total = null
	) {}

	public function is_last_page(): bool {
		return null === $this->next_cursor;
	}
}
