<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

/**
 * パスワード設定メール（`PasswordSetupEmail`）を WooCommerce のメールに登録し、使う側へ渡す（R3-10）。
 *
 * `WC_Email` は autoload できず、`WC()->mailer()` の初期化（`WC_Emails::init()`）で初めて読み込まれる。`PasswordSetupEmail` はそれを継承するので、
 * インスタンスは `woocommerce_email_classes` のコールバックの中でだけ作り、使う側は {@see self::email()} で mailer から引く（クラス名の定数・`class_exists()` で
 * 先に触れると、無い親クラスで fatal になる。CLAUDE.md の起動の注意と同じ）。コンストラクタはフックを足すので、2 つ目を作らない。
 */
final class EmailRegistry {

	/** `WC()->mailer()->get_emails()` の鍵。 */
	public const EMAIL_KEY = 'CBJP_Pro_Password_Setup_Email';

	/** WooCommerce のメールの ID（設定の `woocommerce_{id}_settings`・フィルターの `woocommerce_email_enabled_{id}` など）。 */
	public const EMAIL_ID = 'cbjp_pro_password_setup';

	private function __construct() {}

	public static function register(): void {
		add_filter( 'woocommerce_email_classes', [ self::class, 'add_email' ] );
		add_filter( 'woocommerce_prepare_email_for_preview', [ self::class, 'prepare_preview' ] );
	}

	/**
	 * @param mixed $emails `woocommerce_email_classes` の値（他の拡張が壊しうる）。
	 * @return mixed
	 */
	public static function add_email( mixed $emails ): mixed {
		if ( ! is_array( $emails ) ) {
			return $emails;
		}

		$emails[ self::EMAIL_KEY ] = new PasswordSetupEmail();

		return $emails;
	}

	/**
	 * WooCommerce のメールのプレビュー（WooCommerce > 設定 > メール）は、利用者向けでないメールにダミーの受注を入れる。このメールは見本の名前とリンクで描き、
	 * 再設定キーを作らない。
	 *
	 * @param mixed $email
	 * @return mixed
	 */
	public static function prepare_preview( mixed $email ): mixed {
		if ( $email instanceof PasswordSetupEmail ) {
			$email->use_sample();
		}

		return $email;
	}

	/**
	 * 登録したメール。WooCommerce が無い・他の拡張が外した・壊したときは null。
	 */
	public static function email(): ?PasswordSetupEmail {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = is_array( $emails ) ? ( $emails[ self::EMAIL_KEY ] ?? null ) : null;

		return $email instanceof PasswordSetupEmail ? $email : null;
	}

	/**
	 * 送れない理由（`Campaign::STOP_*`）。送れれば null。メールの有無と有効か（`woocommerce_email_enabled_{id}` を通る）、設定のリンクが使えるか。
	 */
	public static function problem( ?PasswordSetupEmail $email ): ?string {
		if ( null === $email || ! $email->is_enabled() ) {
			return Campaign::STOP_EMAIL_UNAVAILABLE;
		}

		return null === AccountLinks::problem() ? null : Campaign::STOP_ACCOUNT_PAGE;
	}
}
