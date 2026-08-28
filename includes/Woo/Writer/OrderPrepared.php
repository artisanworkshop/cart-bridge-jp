<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use WC_Order_Item;
use WC_Order_Item_Product;

/**
 * `OrderWriter::prepare()` の戻り値。`$line_items`/`$shipping_items`はまだ`$order->add_item()`
 * されていない（`write()`が`remove_order_items()`より前に全ての失敗しうる組み立てを終わらせる
 * 既存の設計を維持するため）。
 */
final readonly class OrderPrepared {

	/**
	 * @param array<int,string>              $warnings
	 * @param array<int,WC_Order_Item_Product> $line_items
	 * @param array<int,WC_Order_Item>       $shipping_items
	 */
	public function __construct(
		public array $warnings,
		public array $line_items,
		public array $shipping_items
	) {}
}
