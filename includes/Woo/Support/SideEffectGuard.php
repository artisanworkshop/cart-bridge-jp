<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * 移行時に発生してはならないWooCommerceの副作用（メール送信・在庫増減）を一時的に抑止する
 * （`docs/03-design-decisions.md` §5 D10 の「受注メール・在庫減算・ポイント付与等の副作用は全て抑止」）。
 *
 * `remove_action('woocommerce_order_status_*', ...)` のようにWC_Emailsのアクション一覧を
 * ハードコードする方式は取らない: WCのバージョンアップで壊れやすく、他プラグインが登録した
 * 正当なフックまで巻き添えで無効化してしまう。代わりに `pre_wp_mail` （WP 5.7+）で
 * `wp_mail()` 自体をショートサーキットする方式を使う。これは `WC()->mailer()` の
 * 初期化タイミングに依存せず、WooCommerceのメールもWPコアの新規ユーザー通知も一撃で止まる。
 */
final class SideEffectGuard {

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function run( callable $callback ): mixed {
		$this->on();

		try {
			return $callback();
		} finally {
			$this->off();
		}
	}

	private function on(): void {
		add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		// `WC_Email::send()` は `apply_filters( 'woocommerce_mail_callback', 'wp_mail', $this )` の
		// 結果を `call_user_func_array()` するため、pre_wp_mail の二重防御として併用する。
		add_filter( 'woocommerce_mail_callback', [ self::class, 'no_op_mail_callback' ], PHP_INT_MAX );
		add_filter( 'send_password_change_email', '__return_false' );
		add_filter( 'send_email_change_email', '__return_false' );

		// ASP側で既に確定済みの受注に対してWoo標準の在庫増減ロジックを再度走らせない
		// （`Writer\OrderWriter` が `set_order_stock_reduced()` でステータスに応じた在庫状態を明示するため）。
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', PHP_INT_MAX );
		add_filter( 'woocommerce_payment_complete_restore_order_stock', '__return_false', PHP_INT_MAX );
	}

	private function off(): void {
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_filter( 'woocommerce_mail_callback', [ self::class, 'no_op_mail_callback' ], PHP_INT_MAX );
		remove_filter( 'send_password_change_email', '__return_false' );
		remove_filter( 'send_email_change_email', '__return_false' );
		remove_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', PHP_INT_MAX );
		remove_filter( 'woocommerce_payment_complete_restore_order_stock', '__return_false', PHP_INT_MAX );
	}

	public static function no_op_mail_callback(): string {
		return '__return_false';
	}
}
