<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * `WooWriter::write()` の結果。
 */
final readonly class WriteResult {

	public const OPERATION_CREATED = 'created';
	public const OPERATION_UPDATED = 'updated';
	public const OPERATION_SKIPPED = 'skipped';

	/**
	 * @param 'created'|'updated'|'skipped' $operation
	 * @param array<int,string>             $warnings
	 * @param bool                          $fully_resolved 商品/カテゴリ参照・顧客参照等、
	 *   実体保存時に一部が未解決のままだった場合はfalseにする。`Importer::process_items()`は
	 *   これがfalseの結果に対してchecksumをキャッシュしない（`upsert()`にnullを渡す）ため、
	 *   参照先が後から解決可能になった場合に再試行される。デフォルトtrue（完全に解決済み）。
	 */
	public function __construct(
		public int $local_id,
		public string $operation,
		public array $warnings = [],
		public bool $fully_resolved = true
	) {}
}
