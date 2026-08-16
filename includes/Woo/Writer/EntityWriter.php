<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WriteResult;

/**
 * 単一エンティティ種別のWoo書込を担う。`Woo\WooRepository` からディスパッチされる。
 */
interface EntityWriter {

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult;
}
