<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Canonical\CanonicalModel;
use RuntimeException;

/**
 * `Woo\WooRepository`（Phase 1）が実装されるまでのプレースホルダー。
 * dry-run は `JobManager` が内部で `DryRunReporter` に差し替えるため到達しない。
 * import/export の実行はPhase 0のREST層で501を返し、本ライターへは到達させない。
 */
final class NotImplementedWriter implements WooWriter {

	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		throw new RuntimeException( 'Woo write is not implemented until Phase 1 (Woo\\WooRepository).' );
	}
}
