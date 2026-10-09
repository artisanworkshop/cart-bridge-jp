<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Canonical\CanonicalModel;

/**
 * dry-run時の書き込み先。実際にはWooに何も書き込まず、操作種別の分類のみを返す
 * （件数の集計は Importer が WriteResult から行い totals_json に持つ）。
 */
final class DryRunReporter implements WooWriter {

	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		$operation = null === $existing_local_id ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		return new WriteResult( 0, $operation );
	}
}
