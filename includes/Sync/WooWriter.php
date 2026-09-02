<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Canonical\CanonicalModel;

/**
 * Importerの書き込み先インターフェース。実移行では `Woo\WooRepository`（Phase 1）が、
 * dry-runでは `Woo\DryRunRepository`（F1-6。`Writer\EntityWriter::validate()`のみを呼び
 * 何も永続化しない）が実装を差し替える。
 */
interface WooWriter {

	/**
	 * @param string $entity 'category'|'tag'|'product'|'customer'|'order'|'stock'|'coupon'|'review'
	 * @param int|null $existing_local_id mappingsに既存の紐付けがあればそのローカルID。
	 */
	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult;
}
