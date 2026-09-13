<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;

/**
 * `Exporter` の書込先インターフェース（`WooWriter` のASP向け対称形）。実行では
 * `Woo\Export\AdapterPlatformWriter`（`PlatformAdapter::push_*()` へディスパッチ）が、
 * dry-runでは `Woo\Export\DryRunPlatformWriter`（ネットワークを叩かず mappings の有無のみで
 * created/updatedを判定）が実装を差し替える。
 */
interface PlatformWriter {

	/**
	 * @param string $entity 'product'（PR-Bで customer/order/stock/coupon を追加）
	 * @param string|null $existing_remote_id mappingsに既存の紐付けがあればそのremote_id。
	 */
	public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult;
}
