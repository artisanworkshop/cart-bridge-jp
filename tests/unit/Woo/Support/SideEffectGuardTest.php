<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\SideEffectGuard;
use WP_UnitTestCase;

final class SideEffectGuardTest extends WP_UnitTestCase {

	/**
	 * `wc_increase_stock_levels()`が実際に参照するのは`woocommerce_can_restore_order_stock`
	 * （WooCommerce本体の`wc-stock-functions.php`で確認済み）。以前は存在しない
	 * `woocommerce_payment_complete_restore_order_stock`という名前をフィルタしており、
	 * 実質何もガードしていなかった（死んだフィルタ）。正しいフィルタ名がrun()の間だけ
	 * 有効になり、終了後は元に戻ることを確認する。
	 */
	public function test_stock_restore_filter_is_active_only_during_run(): void {
		$guard = new SideEffectGuard();

		$seen_during_run = null;

		$guard->run(
			function () use ( &$seen_during_run ) {
				$seen_during_run = apply_filters( 'woocommerce_can_restore_order_stock', true, null );

				return null;
			}
		);

		$this->assertFalse( $seen_during_run );
		$this->assertTrue( apply_filters( 'woocommerce_can_restore_order_stock', true, null ) );
	}

	public function test_stock_reduce_filter_is_active_only_during_run(): void {
		$guard = new SideEffectGuard();

		$seen_during_run = null;

		$guard->run(
			function () use ( &$seen_during_run ) {
				$seen_during_run = apply_filters( 'woocommerce_payment_complete_reduce_order_stock', true, 0 );

				return null;
			}
		);

		$this->assertFalse( $seen_during_run );
		$this->assertTrue( apply_filters( 'woocommerce_payment_complete_reduce_order_stock', true, 0 ) );
	}
}
