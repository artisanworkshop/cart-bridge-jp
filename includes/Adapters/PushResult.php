<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * push* メソッドの結果。
 */
final readonly class PushResult {

	public const OPERATION_CREATED = 'created';
	public const OPERATION_UPDATED = 'updated';
	public const OPERATION_SKIPPED = 'skipped';

	/**
	 * @param 'created'|'updated'|'skipped' $operation
	 * @param array<int,string>             $warnings
	 * @param array<int,string>             $variant_remote_ids `push_product()`のみ使用。呼び出し時に
	 *   渡した `CanonicalProduct::$variants` と同じ順序・同じ要素数で、各バリエーションの確定
	 *   remote_id（空文字列=未確定/失敗）を返す。`Sync\Exporter`がこれを`Woo\Reader\ReadItem::
	 *   $variant_local_ids`とzipして`cbjp_mappings`（'variant'）へ書き戻す。要素数が一致しない場合、
	 *   `Exporter`は信頼境界の契約違反とみなし無視する（アーキテクチャ原則8）。
	 */
	public function __construct(
		public string $remote_id,
		public string $operation,
		public array $warnings = [],
		public array $variant_remote_ids = []
	) {}
}
