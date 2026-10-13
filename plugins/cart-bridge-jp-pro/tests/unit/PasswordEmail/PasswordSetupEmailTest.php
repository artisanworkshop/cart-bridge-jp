<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\PasswordEmail;

use CartBridgeJP\Pro\PasswordEmail\AccountLinks;
use CartBridgeJP\Pro\PasswordEmail\EmailRegistry;
use CartBridgeJP\Pro\PasswordEmail\PasswordSetupEmail;
use CartBridgeJP\Pro\Tests\Fixtures\CreatesImportedCustomers;
use CartBridgeJP\Pro\Tests\Fixtures\PreparesPasswordEmail;
use CartBridgeJP\Tests\Woo\WooTestCase;
use RuntimeException;

/**
 * パスワード設定メール（R3-10）: WooCommerce のメールへの登録・宛先と CC/BCC・宛先ごとの値の入れ替え・テスト送信とプレビュー・設定のリンク。
 */
final class PasswordSetupEmailTest extends WooTestCase {

	use CreatesImportedCustomers;
	use PreparesPasswordEmail;

	private PasswordSetupEmail $email;

	public function set_up(): void {
		parent::set_up();
		$this->email = $this->prepare_password_email();
	}

	public function test_is_a_manual_customer_email_registered_in_woocommerce(): void {
		$this->assertSame( EmailRegistry::EMAIL_ID, $this->email->id );
		$this->assertTrue( $this->email->is_customer_email() );
		$this->assertTrue( $this->email->is_manual() );
		$this->assertSame( $this->email, WC()->mailer()->get_emails()[ EmailRegistry::EMAIL_KEY ] );
	}

	public function test_sends_the_link_to_the_customer_only(): void {
		global $wpdb;

		$user_id = $this->imported_customer( '1', 'taro@example.com' );
		// `wp_update_user()` はタグを除くので、DB に直接書いて、エスケープしないと崩れる名前を作る。
		$wpdb->update( $wpdb->users, [ 'display_name' => 'Taro <b>Yamada</b>' ], [ 'ID' => $user_id ] );
		clean_user_cache( $user_id );
		$url = AccountLinks::set_password_url( get_userdata( $user_id ), 'abc123' );
		$this->forget_sent_mails();

		$this->assertTrue( $this->email->send_to( get_userdata( $user_id ), $url ) );

		$mails = $this->sent_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'taro@example.com', $mails[0]['to'][0][0] );
		$this->assertSame( [], $mails[0]['cc'] );
		$this->assertSame( [], $mails[0]['bcc'] );
		$this->assertStringContainsString( 'key=abc123', $mails[0]['body'] );
		$this->assertStringContainsString( 'action=newaccount', $mails[0]['body'] );
		$this->assertStringContainsString( 'Taro &lt;b&gt;Yamada&lt;/b&gt;', $mails[0]['body'], '名前はエスケープする' );
		$this->assertStringNotContainsString( '<b>Yamada</b>', $mails[0]['body'] );
		$this->assertStringContainsString( 'Set a password for your account at', $mails[0]['subject'] );
	}

	/**
	 * CC・BCC は付けない（設定・フィルターのどちらでも。付けると全員の有効なリンクが他の宛先に届く）。設定の欄も出さない。
	 */
	public function test_never_adds_cc_or_bcc(): void {
		// CC・BCC の見出しは WooCommerce の「メールの改善」が有効なときだけ付く。
		update_option( 'woocommerce_feature_email_improvements_enabled', 'yes' );
		add_filter( 'woocommerce_email_cc_recipient_' . EmailRegistry::EMAIL_ID, static fn () => 'cc@example.com' );
		add_filter( 'woocommerce_email_bcc_recipient_' . EmailRegistry::EMAIL_ID, static fn () => 'bcc@example.com' );
		// 全メールに BCC を足す汎用のスニペット（`woocommerce_email_headers`）にも全員のリンクを渡さない（R1-L5）。
		add_filter( 'woocommerce_email_headers', static fn ( $headers ) => $headers . "Bcc: spy@example.com\r\nCC: spy-cc@example.com\r\n" );
		$user = get_userdata( $this->imported_customer( '1' ) );
		$this->forget_sent_mails();

		$this->assertTrue( $this->email->send_to( $user, AccountLinks::set_password_url( $user, 'abc123' ) ) );

		$mail = $this->sent_mails()[0];
		$this->assertSame( [], $mail['cc'] );
		$this->assertSame( [], $mail['bcc'] );
		$this->assertStringNotContainsString( 'cc@example.com', $mail['header'] );
		$this->assertStringNotContainsString( 'bcc@example.com', $mail['header'] );
		$this->assertStringNotContainsString( 'spy', $mail['header'] );
		$this->assertStringContainsString( 'Content-Type:', $mail['header'], 'ほかの見出しは残す' );

		$this->assertSame( '', $this->email->get_cc_recipient() );
		$this->assertSame( '', $this->email->get_bcc_recipient() );
		$this->assertFalse( $this->email->supports_cc_bcc() );

		// 11.2 は `supports_cc_bcc()` で欄を足さない。11.1 まで（全メールに欄を足す）向けの `init_form_fields()` の除外は、この版では確かめられない。
		$this->email->init_form_fields();
		$this->assertArrayNotHasKey( 'cc', $this->email->form_fields );
		$this->assertArrayNotHasKey( 'bcc', $this->email->form_fields );
	}

	/**
	 * mailer のインスタンスは 1 つ。前の宛先の名前・リンクが次のメールに残らない（残ると別の顧客のリンクを送る）。
	 */
	public function test_does_not_carry_one_customer_into_the_next_email(): void {
		$first  = get_userdata( $this->imported_customer( '1', 'first@example.com' ) );
		$second = get_userdata( $this->imported_customer( '2', 'second@example.com' ) );
		wp_update_user(
			[
				'ID'           => $first->ID,
				'display_name' => 'First Person',
			]
		);
		wp_update_user(
			[
				'ID'           => $second->ID,
				'display_name' => 'Second Person',
			]
		);

		$this->forget_sent_mails();
		$this->email->send_to( get_userdata( $first->ID ), AccountLinks::set_password_url( $first, 'firstkey' ) );
		$this->email->send_to( get_userdata( $second->ID ), AccountLinks::set_password_url( $second, 'secondkey' ) );

		$mail = $this->sent_mails()[1];
		$this->assertSame( 'second@example.com', $mail['to'][0][0] );
		$this->assertStringContainsString( 'secondkey', $mail['body'] );
		$this->assertStringNotContainsString( 'firstkey', $mail['body'] );
		$this->assertStringNotContainsString( 'First Person', $mail['body'] );
		$this->assertStringNotContainsString( 'login=' . rawurlencode( $first->user_login ), $mail['body'] );
		$this->assertSame( '', $this->email->set_password_url, '送り終えたら消す' );
		$this->assertNull( $this->email->object );
	}

	public function test_does_not_send_when_the_email_is_disabled(): void {
		add_filter( 'woocommerce_email_enabled_' . EmailRegistry::EMAIL_ID, '__return_false' );
		$user = get_userdata( $this->imported_customer( '1' ) );
		$this->forget_sent_mails();

		$this->assertFalse( $this->email->send_to( $user, AccountLinks::set_password_url( $user, 'abc123' ) ) );
		$this->assertSame( [], $this->sent_mails() );
		$this->assertSame( EmailRegistry::problem( $this->email ), 'email_unavailable' );
	}

	/**
	 * 送信の失敗（`wp_mail()` の false・SMTP のプラグインの例外）は false にする（例外で一斉送信を落とさない）。
	 */
	public function test_reports_a_failed_delivery(): void {
		$user = get_userdata( $this->imported_customer( '1' ) );
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->assertFalse( $this->email->send_to( $user, AccountLinks::set_password_url( $user, 'abc123' ) ) );

		remove_filter( 'pre_wp_mail', '__return_false' );
		add_filter(
			'pre_wp_mail',
			static function (): never {
				throw new RuntimeException( 'SMTP is down' );
			}
		);

		$this->assertFalse( $this->email->send_to( $user, AccountLinks::set_password_url( $user, 'abc123' ) ) );
		$this->assertSame( '', $this->email->set_password_url );
	}

	/**
	 * テスト送信は見本の名前とパスワード忘れのページで描き、管理者の再設定キーを作らない。
	 */
	public function test_sends_a_test_email_without_creating_a_reset_key(): void {
		$admin = self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => 'admin@example.com',
			]
		);
		wp_set_current_user( $admin );
		$before = get_userdata( $admin )->user_activation_key;
		$this->forget_sent_mails();

		$this->assertTrue( $this->email->send_test( 'admin@example.com' ) );

		$mail = $this->sent_mails()[0];
		$this->assertSame( 'admin@example.com', $mail['to'][0][0] );
		$this->assertStringContainsString( 'Sample Customer', $mail['body'] );
		$this->assertStringNotContainsString( 'key=', $mail['body'] );
		clean_user_cache( $admin );
		$this->assertSame( $before, get_userdata( $admin )->user_activation_key );
	}

	/**
	 * WooCommerce のメールのプレビューは、このメールに見本を入れる（ダミーの受注を使わない。再設定キーを作らない）。
	 */
	public function test_prepares_a_preview_with_sample_values(): void {
		$prepared = apply_filters( 'woocommerce_prepare_email_for_preview', $this->email );

		$this->assertSame( $this->email, $prepared );
		$this->assertStringContainsString( 'Sample Customer', $this->email->get_content_html() );
		$this->assertStringContainsString( 'Sample Customer', $this->email->get_content_plain() );
		$this->assertSame( AccountLinks::lost_password_url(), $this->email->set_password_url );
	}

	public function test_plain_text_lists_the_links(): void {
		$this->email->user_display_name = 'Taro';
		$this->email->set_password_url  = 'https://example.com/my-account/lost-password/?action=newaccount&key=abc&login=taro';

		$plain = $this->email->get_content_plain();

		$this->assertStringContainsString( 'Taro', $plain );
		$this->assertStringContainsString( 'https://example.com/my-account/lost-password/?action=newaccount&key=abc&login=taro', $plain );
		$this->assertStringContainsString( AccountLinks::lost_password_url(), $plain );
		$this->assertStringContainsString( 'This link expires in 1 day.', $plain );
		$this->assertStringNotContainsString( '&amp;', $plain, 'プレーンテキストのリンクを実体参照にしない' );
	}

	/**
	 * 設定のリンクは `add_query_arg()` で組み立てる（プレーンなパーマリンクでは `?` が二重にならない）。ログイン名はエンコードする。
	 */
	public function test_builds_the_set_password_link_for_any_permalink_setting(): void {
		$user             = get_userdata( $this->imported_customer( '1' ) );
		$user->user_login = 'a+b@example.com';

		$this->set_permalink_structure( '' );
		$plain = AccountLinks::set_password_url( $user, 'abc123' );
		$this->set_permalink_structure( '/%postname%/' );
		$pretty = AccountLinks::set_password_url( $user, 'abc123' );

		foreach ( [ $plain, $pretty ] as $url ) {
			$this->assertSame( 1, substr_count( $url, '?' ), $url );
			$this->assertStringContainsString( 'action=newaccount', $url );
			$this->assertStringContainsString( 'key=abc123', $url );
			$this->assertStringContainsString( 'login=' . rawurlencode( 'a+b@example.com' ), $url );
		}
	}

	/**
	 * 設定のリンクが使えない店舗では送らない（マイアカウントのページが公開されていない・パスワード忘れのエンドポイントが空）。
	 */
	public function test_reports_an_unusable_account_page(): void {
		$this->assertNull( AccountLinks::problem() );

		update_option( 'woocommerce_myaccount_lost_password_endpoint', '' );
		$this->assertSame( AccountLinks::PROBLEM_LOST_PASSWORD_ENDPOINT, AccountLinks::problem() );

		delete_option( 'woocommerce_myaccount_lost_password_endpoint' );
		wp_update_post(
			[
				'ID'          => (int) get_option( 'woocommerce_myaccount_page_id' ),
				'post_status' => 'draft',
			]
		);
		$this->assertSame( AccountLinks::PROBLEM_ACCOUNT_PAGE, AccountLinks::problem() );
		$this->assertSame( 'account_page', EmailRegistry::problem( $this->email ) );
	}

	public function test_link_lifetime_follows_the_reset_key_expiration(): void {
		add_filter( 'password_reset_expiration', static fn () => 2 * DAY_IN_SECONDS );

		$this->assertSame( 2 * DAY_IN_SECONDS, AccountLinks::expiration_seconds() );

		add_filter( 'password_reset_expiration', static fn () => 'broken' );

		$this->assertSame( DAY_IN_SECONDS, AccountLinks::expiration_seconds() );
	}
}
