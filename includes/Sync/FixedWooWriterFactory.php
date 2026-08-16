<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * テスト用: platformに関わらず常に同一の `WooWriter`（`InMemoryWriter` 等）を返す。
 */
final class FixedWooWriterFactory implements WooWriterFactory {

	public function __construct( private readonly WooWriter $writer ) {}

	public function for_platform( string $platform ): WooWriter {
		return $this->writer;
	}
}
