<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use WC_Product;

/**
 * `ProductWriter::prepare()` の戻り値。`$product` は `save()` される前の（またはvalidate()では
 * 一切保存されない）メモリ上のオブジェクト。
 */
final readonly class ProductPrepared {

	/**
	 * @param array<int,string> $variation_axis_names
	 * @param array<int,string> $stale_variations
	 * @param 'created'|'updated' $operation
	 * @param array<int,string>  $warnings
	 */
	public function __construct(
		public WC_Product $product,
		public bool $has_variants,
		public array $variation_axis_names,
		public array $stale_variations,
		public string $operation,
		public array $warnings
	) {}
}
