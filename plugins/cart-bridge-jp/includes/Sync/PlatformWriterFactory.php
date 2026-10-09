<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\PlatformAdapter;

/**
 * `PlatformWriter` を組み立てる（`WooWriterFactory` の対称形）。`write()`が実際に
 * `PlatformAdapter::push_*()` を呼ぶため、platform文字列ではなくアダプタ本体を受け取る。
 */
interface PlatformWriterFactory {

	public function for_platform( PlatformAdapter $adapter ): PlatformWriter;

	/**
	 * dry-run用。アダプタを一切呼ばない（`Woo\DryRunRepository`のexport対称形）。
	 */
	public function for_dry_run( PlatformAdapter $adapter ): PlatformWriter;
}
