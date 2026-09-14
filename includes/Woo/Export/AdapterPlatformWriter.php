<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Export;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
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
			'customer' => $this->push_customer( $item, $existing_remote_id ),
			'order' => $this->push_order( $item ),
			'stock' => $this->push_stock( $item ),
			'coupon' => $this->push_coupon( $item, $existing_remote_id ),
			// `category`/`tag`/`review`は`JobManager::EXPORT_ENTITIES_WITH_READER`が
			// そもそも到達させない想定だが、防御の二重化として`WooRepository::write()`と
			// 同じ扱いにする。
			default => new PushResult( '', PushResult::OPERATION_SKIPPED, [ WarningCode::ENTITY_NOT_SUPPORTED ] ),
		};
	}

	private function push_product( CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		if ( ! $item instanceof CanonicalProduct ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "product".' );
		}

		return $this->adapter->push_product( $item, $existing_remote_id );
	}

	private function push_customer( CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		if ( ! $item instanceof CanonicalCustomer ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "customer".' );
		}

		return $this->adapter->push_customer( $item, $existing_remote_id );
	}

	private function push_order( CanonicalModel $item ): PushResult {
		if ( ! $item instanceof CanonicalOrder ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "order".' );
		}

		return $this->adapter->push_order( $item );
	}

	private function push_stock( CanonicalModel $item ): PushResult {
		if ( ! $item instanceof CanonicalStock ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "stock".' );
		}

		return $this->adapter->push_stock( $item );
	}

	private function push_coupon( CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		if ( ! $item instanceof CanonicalCoupon ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "coupon".' );
		}

		return $this->adapter->push_coupon( $item, $existing_remote_id );
	}
}
