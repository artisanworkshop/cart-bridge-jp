<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Sync\WooReader;
use CartBridgeJP\Sync\WooReaderFactory;
use CartBridgeJP\Woo\Reader\EntityReader;
use Throwable;

/**
 * platformごとに `WooReaderRepository`（Exporterの読出元）を組み立てる既定のファクトリ
 * （`WooRepositoryFactory` の読出側対称形）。Reader は実体の種類（`Entities\EntityType::reader()`）が作る。
 */
final class WooReaderRepositoryFactory implements WooReaderFactory {

	public function for_platform( string $platform ): WooReader {
		return new WooReaderRepository( $this->readers( $platform ) );
	}

	/**
	 * @return array<string,EntityReader>
	 */
	private function readers( string $platform ): array {
		$services = new WooServices( $platform );
		$readers  = [];

		foreach ( EntityTypeRegistry::all() as $key => $type ) {
			try {
				$reader = $type->reader( $platform, $services );
			} catch ( Throwable $exception ) {
				( new Logger() )->error(
					'Entity type failed to build its Woo reader.',
					[
						'platform'  => $platform,
						'entity'    => $key,
						'exception' => $exception::class,
					]
				);

				continue;
			}

			if ( $reader instanceof EntityReader ) {
				$readers[ $key ] = $reader;
			}
		}

		return $readers;
	}
}
