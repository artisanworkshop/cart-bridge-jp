<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WooReader;
use CartBridgeJP\Sync\WooReaderFactory;
use CartBridgeJP\Woo\Reader\CouponReader;
use CartBridgeJP\Woo\Reader\CustomerReader;
use CartBridgeJP\Woo\Reader\OrderReader;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Reader\StockReader;
use CartBridgeJP\Woo\Support\MethodMap;

/**
 * platformごとに `WooReaderRepository`（Exporterの読出元）を組み立てる既定のファクトリ
 * （`WooRepositoryFactory` の読出側対称形）。
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
			'product'  => new ProductReader( $platform, new MethodMap( $platform ), $mappings ),
			'customer' => new CustomerReader(),
			'order'    => new OrderReader( $platform, $mappings ),
			'stock'    => new StockReader( $platform, $mappings ),
			'coupon'   => new CouponReader(),
		];
	}
}
