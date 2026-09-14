<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Export;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Sync\PlatformWriter;
use CartBridgeJP\Sync\PlatformWriterFactory;

/**
 * 既定の `Sync\PlatformWriterFactory` 実装（`Woo\WooRepositoryFactory` のASP向け対称形）。
 */
final class AdapterPlatformWriterFactory implements PlatformWriterFactory {

	public function for_platform( PlatformAdapter $adapter ): PlatformWriter {
		return new AdapterPlatformWriter( $adapter );
	}

	public function for_dry_run( PlatformAdapter $adapter ): PlatformWriter {
		return new DryRunPlatformWriter();
	}
}
