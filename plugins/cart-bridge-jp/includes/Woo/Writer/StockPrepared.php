<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalStock;
use WC_Product;

/**
 * `StockWriter::prepare()` の戻り値。`$target` が null の場合は書き込み不可
 * （未解決 or variable親）で、`$local_id_if_unwritable` が `WriteResult` にそのまま使う値。
 */
final readonly class StockPrepared {

	/**
	 * @param array<int,string> $warnings
	 */
	public function __construct(
		public ?WC_Product $target,
		public CanonicalStock $stock,
		public int $local_id_if_unwritable,
		public array $warnings
	) {}
}
