<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Canonical\CanonicalTag;

/**
 * `cbjp_dry_run_items.label`（CSV上の人が読める識別子）を組み立てる。`Support\Logger`の
 * 個人情報禁止ルール（docblock参照）をこのテーブルにも適用し、顧客名・メール等のPIIを
 * 含みうるフィールドは常に空文字にする。
 */
final class DryRunLabel {

	private function __construct() {}

	public static function for_entity( string $entity, CanonicalModel $item ): string {
		return match ( true ) {
			$item instanceof CanonicalProduct, $item instanceof CanonicalCategory, $item instanceof CanonicalTag => $item->name,
			$item instanceof CanonicalCoupon => $item->code,
			$item instanceof CanonicalOrder => $item->number,
			$item instanceof CanonicalStock => $item->sku ?? '',
			// customer/reviewはPII（氏名・メール）を含みうるため常に空。
			default => '',
		};
	}
}
