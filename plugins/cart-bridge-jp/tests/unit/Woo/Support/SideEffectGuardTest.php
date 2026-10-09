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

	/**
	 * WooCommerceの「Deferred emails」機能（既定無効）が有効な場合、
	 * `WC_Emails::init_transactional_emails()`は`wp_mail()`を直接呼ばない
	 * `queue_transactional_email()`を各種メールトリガーへ登録する。実送信は`shutdown`で
	 * スケジュールされる別のAction Scheduler実行（別リクエスト）で行われるため、
	 * `pre_wp_mail`によるガードだけでは実際にメールが送信されてしまう。このコールバック自体を
	 * `run()`の間だけ検出・退避し、終了後に元へ戻すことを確認する
	 * （実際の`WC_Emails::init_transactional_emails()`を経由せず、同じコールバックを
	 * 任意のフックへ登録することで機能有効時の状態を模擬する）。
	 */
	public function test_deferred_email_queueing_callback_is_suspended_only_during_run(): void {
		$callback = [ 'WC_Emails', 'queue_transactional_email' ];
		add_filter( 'woocommerce_order_status_pending_to_processing', $callback, 10, 10 );

		try {
			$guard           = new SideEffectGuard();
			$seen_during_run = null;

			$guard->run(
				function () use ( &$seen_during_run, $callback ) {
					$seen_during_run = has_filter( 'woocommerce_order_status_pending_to_processing', $callback );

					return null;
				}
			);

			$this->assertFalse( $seen_during_run );
			$this->assertSame( 10, has_filter( 'woocommerce_order_status_pending_to_processing', $callback ) );
		} finally {
			remove_filter( 'woocommerce_order_status_pending_to_processing', $callback, 10 );
		}
	}
}
