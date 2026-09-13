<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Export;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\PlatformWriter;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;

/**
 * `Sync\PlatformWriter` の実装。entityごとに `PlatformAdapter::push_*()` へディスパッチする
 * （`Woo\WooRepository` のASP向け対称形）。`push_*()`が投げる例外（capability未対応の
 * `UnsupportedOperationException`を含む）はここでは捕まえない。`Sync\Exporter::process_items()`が
 * `Importer`と同じ「1件の異常データで移行全体を止めない」汎用catchで処理する。
 */
final class AdapterPlatformWriter implements PlatformWriter {

	public function __construct( private readonly PlatformAdapter $adapter ) {}

	public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		return match ( $entity ) {
			'product' => $this->push_product( $item, $existing_remote_id ),
			// PR-B（customer/order/stock/coupon）まではentity自体をJobManagerが
			// 生成しない想定だが、防御の二重化としてWooRepository::write()と同じ扱いにする。
			default => new PushResult( '', PushResult::OPERATION_SKIPPED, [ WarningCode::ENTITY_NOT_SUPPORTED ] ),
		};
	}

	private function push_product( CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		if ( ! $item instanceof CanonicalProduct ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "product".' );
		}

		return $this->adapter->push_product( $item, $existing_remote_id );
	}
}
