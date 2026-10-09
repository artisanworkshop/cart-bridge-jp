<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use WC_Coupon;

/**
 * `CouponWriter::prepare()` の戻り値。`$coupon` が null の場合は保存自体を見送る
 * （フェイルクローズ・コード衝突）。
 */
final readonly class CouponPrepared {

	/**
	 * @param 'created'|'updated'|'skipped' $operation
	 * @param array<int,string>             $warnings
	 */
	public function __construct(
		public ?WC_Coupon $coupon,
		public string $operation,
		public array $warnings
	) {}
}
