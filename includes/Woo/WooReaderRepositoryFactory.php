<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WooReader;
use CartBridgeJP\Sync\WooReaderFactory;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Support\MethodMap;

/**
 * platformごとに `WooReaderRepository`（Exporterの読出元）を組み立てる既定のファクトリ
 * （`WooRepositoryFactory` の読出側対称形）。
 *
 * PR-A時点は `product` のみ登録する。customer/order/stock/coupon はPR-Bで追加する
 * （`Sync\JobManager::EXPORT_ENTITIES_WITH_READER` が未実装entityを事前に除外するため、
 * ここに到達する前にJobManager側で弾かれる想定）。
 */
final class WooReaderRepositoryFactory implements WooReaderFactory {

	public function for_platform( string $platform ): WooReader {
		return new WooReaderRepository( $this->readers( $platform ) );
	}

	/**
	 * @return array<string,\CartBridgeJP\Woo\Reader\EntityReader>
	 */
	private function readers( string $platform ): array {
		$mappings = new MappingRepository();

		return [
			'product' => new ProductReader( $platform, new MethodMap( $platform ), $mappings ),
		];
	}
}
