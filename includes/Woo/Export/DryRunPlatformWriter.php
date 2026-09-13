<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Export;

use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\PlatformWriter;

/**
 * `Sync\PlatformWriter` の実装だが `PlatformAdapter::push_*()` を一切呼ばない
 * （`Woo\DryRunRepository` のASP向け対称形）。ColorMeの`push_*()`はE2-3まで
 * `UnsupportedOperationException`を投げるため、dry-runの参照解決・警告は
 * `Woo\Reader\EntityReader`（読出時点）が担い、このクラスはmappingsの有無だけで
 * created/updatedを判定した結果を返す。
 */
final class DryRunPlatformWriter implements PlatformWriter {

	public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		$operation = null !== $existing_remote_id ? PushResult::OPERATION_UPDATED : PushResult::OPERATION_CREATED;

		// 何もpushしないためremote_idは未確定。既存mappingがあればそのremote_idを、
		// 新規作成候補は空文字列を返す（`Sync\Exporter::process_items()`の
		// 「実際にpushされたか」判定における0/空文字列の番兵値契約）。
		return new PushResult( $existing_remote_id ?? '', $operation, [] );
	}
}
