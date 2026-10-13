<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Fixtures;

use CartBridgeJP\Pro\PasswordEmail\EmailRegistry;
use CartBridgeJP\Pro\PasswordEmail\PasswordSetupEmail;
use MockPHPMailer;

/**
 * パスワード設定メール（R3-10）のテストの前提: マイアカウントのページ（設定のリンクに要る）と、送ったメールを記録する WP のテストの mailer。
 */
trait PreparesPasswordEmail {

	private function prepare_password_email(): PasswordSetupEmail {
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'My account',
			]
		);
		update_option( 'woocommerce_myaccount_page_id', $page_id );
		reset_phpmailer_instance();

		$email = EmailRegistry::email();
		$this->assertInstanceOf( PasswordSetupEmail::class, $email, 'WooCommerce のメールに登録されている' );

		return $email;
	}

	/**
	 * 準備（顧客の作成など）で送られたメールを記録から消す（取込みの通知の抑止は単体テストでは効かない）。
	 */
	private function forget_sent_mails(): void {
		reset_phpmailer_instance();
	}

	private function mailer(): MockPHPMailer {
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertInstanceOf( MockPHPMailer::class, $mailer );

		return $mailer;
	}

	/**
	 * @return array<int,array{to:array<int,mixed>,cc:array<int,mixed>,bcc:array<int,mixed>,header:string,subject:string,body:string}>
	 */
	private function sent_mails(): array {
		return $this->mailer()->mock_sent;
	}
}
