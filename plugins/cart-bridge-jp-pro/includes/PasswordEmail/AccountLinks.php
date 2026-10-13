<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

use WP_User;

/**
 * パスワード設定メールのリンク（R3-10）。WooCommerce の新規アカウントのメールと同じく、マイアカウントのパスワード忘れのエンドポイントを
 * `action=newaccount` で開く（`WC_Email_Customer_New_Account::generate_set_password_url()`。WooCommerce は文字列をつなぐが、プレーンなパーマリンクでは
 * `?` が二重になって壊れるので `add_query_arg()` で組み立てる）。
 */
final class AccountLinks {

	/** マイアカウントのページが公開されていない。 */
	public const PROBLEM_ACCOUNT_PAGE = 'account_page';

	/** パスワード忘れのエンドポイントが空（WooCommerce > 設定 > 高度な設定）。 */
	public const PROBLEM_LOST_PASSWORD_ENDPOINT = 'lost_password_endpoint';

	private function __construct() {}

	/**
	 * 設定のリンクが使えない理由。使えれば null。開始の前に確かめて断り、送信中なら止める（壊れたリンクを送らない）。
	 */
	public static function problem(): ?string {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;

		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return self::PROBLEM_ACCOUNT_PAGE;
		}

		$endpoint = get_option( 'woocommerce_myaccount_lost_password_endpoint', 'lost-password' );

		if ( ! is_string( $endpoint ) || '' === trim( $endpoint ) ) {
			return self::PROBLEM_LOST_PASSWORD_ENDPOINT;
		}

		return null;
	}

	/**
	 * パスワードを設定するリンク（再設定キーつき）。
	 */
	public static function set_password_url( WP_User $user, string $key ): string {
		return add_query_arg(
			[
				'action' => 'newaccount',
				'key'    => rawurlencode( $key ),
				'login'  => rawurlencode( $user->user_login ),
			],
			wc_get_account_endpoint_url( 'lost-password' )
		);
	}

	/**
	 * リンクの期限が切れたときに新しいリンクを頼むページ（WooCommerce はマイアカウントのパスワード忘れへ差し替える。無ければ wp-login.php）。
	 */
	public static function lost_password_url(): string {
		return wp_lostpassword_url();
	}

	/**
	 * 再設定キーの有効期限（秒。WordPress の `password_reset_expiration`。既定 1 日）。
	 */
	public static function expiration_seconds(): int {
		/** This filter is documented in wp-includes/user.php */
		$seconds = apply_filters( 'password_reset_expiration', DAY_IN_SECONDS );

		return is_numeric( $seconds ) && (int) $seconds > 0 ? (int) $seconds : DAY_IN_SECONDS;
	}
}
