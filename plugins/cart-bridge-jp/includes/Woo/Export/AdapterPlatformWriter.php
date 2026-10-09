<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Export;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Sync\PlatformWriter;
use CartBridgeJP\Woo\WarningCode;

/**
 * `Sync\PlatformWriter` の実装。実体の種類の送信（`Entities\EntityType::push()`。商品・顧客・受注・在庫・クーポンは
 * `PlatformAdapter::push_*()` を呼ぶ）へディスパッチする（`Woo\WooRepository` のASP向け対称形）。送信できない種類・登録の無い種類は
 * `ENTITY_NOT_SUPPORTED` でスキップする。`push_*()`が投げる例外（capability未対応の`UnsupportedOperationException`を含む）は
 * ここでは捕まえない。`Sync\Exporter::process_items()`が`Importer`と同じ「1件の異常データで移行全体を止めない」汎用catchで処理する。
 */
final class AdapterPlatformWriter implements PlatformWriter {

	public function __construct( private readonly PlatformAdapter $adapter ) {}

	public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		$type = EntityTypeRegistry::get( $entity );

		if ( null === $type ) {
			return new PushResult( '', PushResult::OPERATION_SKIPPED, [ WarningCode::ENTITY_NOT_SUPPORTED ] );
		}

		return $type->push( $this->adapter, $item, $existing_remote_id );
	}
}
