<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * platformごとに `WooReader` を組み立てる（`WooWriterFactory` の対称形）。
 * `category_map` 等プラットフォーム単位の設定を読むため platform が必須。
 */
interface WooReaderFactory {

	public function for_platform( string $platform ): WooReader;
}
