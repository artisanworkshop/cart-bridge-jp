<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

use Throwable;
use WC_Email;
use WP_User;

/**
 * パスワード設定メール（R3-10。`docs/03` §10.5）。取込みで作った顧客へ、ストアがこのサイトへ移ったことと、パスワードを設定するリンクを送る。
 * WooCommerce のメールとして登録する（WooCommerce > 設定 > メール で件名・見出し・追加の文を変え、無効にできる。テンプレートはテーマで上書きできる）。
 * 自動では送らず、Pro の設定画面から管理者が始めたときだけ送る（`manual`）。宣伝を入れない（特定電子メール法）。
 *
 * **このファイルは `WC_Email` が読み込まれた後にしか読まない**（{@see EmailRegistry}）。
 *
 * 宛先ごとの値は送るたびにすべて設定し直し、送り終えたら消す（mailer のインスタンスは 1 つで、前の宛先の値が次のメールに残ると、別の顧客のリンクを送ってしまう）。
 * CC・BCC は付けない（付けると、設定した宛先に全員の有効なリンクが届く。WooCommerce の新規アカウント・再設定のメールも付けない）。WooCommerce 9.8〜11.1 は
 * すべてのメールに CC・BCC の設定があり、11.2 で `supports_cc_bcc` が入ったが、フィルター（`woocommerce_email_cc_recipient_{id}`）は 11.2 でも効くので、
 * 宛先を返す処理（{@see self::get_cc_recipient()}・{@see self::get_bcc_recipient()}）ごと空にし、設定の欄も外す。
 */
final class PasswordSetupEmail extends WC_Email {

	/**
	 * CC・BCC を付けない（親の既定は付ける。WooCommerce の版によっては親に宣言が無いので、ここでも宣言する）。
	 *
	 * @var bool
	 */
	public $supports_cc_bcc = false;

	/** 宛名（表示名。無ければ名、ログイン名）。 */
	public string $user_display_name = '';

	/** パスワードを設定するリンク（再設定キーつき。見本では再設定キーの無いパスワード忘れのページ）。 */
	public string $set_password_url = '';

	public function __construct() {
		$this->id              = EmailRegistry::EMAIL_ID;
		$this->customer_email  = true;
		$this->manual          = true;
		$this->supports_cc_bcc = false;
		$this->title           = __( 'Password setup after store migration', 'cart-bridge-jp-pro' );
		$this->description     = __( 'Sent from WooCommerce > Cart Bridge JP Pro > Password setup emails to customers whose accounts were created by importing them from another shop. The email asks them to set a password for this store.', 'cart-bridge-jp-pro' );
		$this->template_html   = 'emails/cbjp-pro-password-setup.php';
		$this->template_plain  = 'emails/plain/cbjp-pro-password-setup.php';
		$this->template_base   = plugin_dir_path( CBJP_PRO_FILE ) . 'templates/';
		$this->placeholders    = [];

		parent::__construct();

		// 親のコンストラクタの後にも固定する（親や他の拡張が既定値に戻さないように）。
		$this->supports_cc_bcc = false;
	}

	/**
	 * CC・BCC の設定の欄を出さない。
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		unset( $this->form_fields['cc'], $this->form_fields['bcc'] );
	}

	/**
	 * CC は付けない（設定・フィルターのどちらでも）。
	 *
	 * @return string
	 */
	public function get_cc_recipient() {
		return '';
	}

	/**
	 * BCC は付けない（設定・フィルターのどちらでも）。
	 *
	 * @return string
	 */
	public function get_bcc_recipient() {
		return '';
	}

	/**
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Set a password for your account at {site_title}', 'cart-bridge-jp-pro' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Our store has moved', 'cart-bridge-jp-pro' );
	}

	/**
	 * @return string
	 */
	public function get_default_additional_content() {
		return '';
	}

	/**
	 * 1 人の顧客へ送る。送れたか（`WC_Email::send()` の結果）を返す。メールが無効・宛先が無い・送信の例外（SMTP のプラグインなど）は失敗。
	 */
	public function send_to( WP_User $user, string $set_password_url ): bool {
		$this->setup_locale();

		try {
			$this->assign( $user, self::display_name( $user ), $set_password_url, $user->user_email );

			return $this->deliver();
		} catch ( Throwable ) {
			return false;
		} finally {
			$this->assign( null, '', '', '' );
			$this->restore_locale();
		}
	}

	/**
	 * テスト送信（見本の名前とリンク。再設定キーを作らない）。
	 */
	public function send_test( string $recipient ): bool {
		$this->setup_locale();

		try {
			$this->assign( null, self::sample_name(), AccountLinks::lost_password_url(), $recipient );

			return $this->deliver();
		} catch ( Throwable ) {
			return false;
		} finally {
			$this->assign( null, '', '', '' );
			$this->restore_locale();
		}
	}

	/**
	 * WooCommerce のメールのプレビュー用の見本（{@see EmailRegistry::prepare_preview()}）。
	 */
	public function use_sample(): void {
		$this->user_display_name = self::sample_name();
		$this->set_password_url  = AccountLinks::lost_password_url();
	}

	/**
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	private function deliver(): bool {
		$recipient = $this->get_recipient();

		if ( ! $this->is_enabled() || ! is_string( $recipient ) || '' === $recipient || '' === $this->set_password_url ) {
			return false;
		}

		return true === $this->send( $recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	private function assign( ?WP_User $user, string $name, string $url, string $recipient ): void {
		$this->object            = $user;
		$this->recipient         = $recipient;
		$this->user_display_name = $name;
		$this->set_password_url  = $url;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return [
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'user_display_name'  => $this->user_display_name,
			'set_password_url'   => $this->set_password_url,
			'lost_password_url'  => AccountLinks::lost_password_url(),
			'link_lifetime'      => human_time_diff( 0, AccountLinks::expiration_seconds() ),
			'store_url'          => home_url( '/' ),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		];
	}

	private static function display_name( WP_User $user ): string {
		foreach ( [ $user->display_name, $user->first_name, $user->user_login ] as $name ) {
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				return trim( $name );
			}
		}

		return '';
	}

	private static function sample_name(): string {
		return __( 'Sample Customer', 'cart-bridge-jp-pro' );
	}
}
