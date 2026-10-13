<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Admin;

use CartBridgeJP\Pro\Admin\LicensePage;
use CartBridgeJP\Pro\Admin\PasswordEmailPage;
use CartBridgeJP\Pro\License\LicenseStore;
use CartBridgeJP\Pro\PasswordEmail\Campaign;
use CartBridgeJP\Pro\PasswordEmail\EmailRegistry;
use CartBridgeJP\Pro\PasswordEmail\LegacyAccountEstimator;
use CartBridgeJP\Pro\PasswordEmail\Sender;
use CartBridgeJP\Pro\PasswordEmail\SendRecord;
use CartBridgeJP\Pro\PasswordEmail\StateOption;
use CartBridgeJP\Pro\Tests\Fixtures\ActivatesLicense;
use CartBridgeJP\Pro\Tests\Fixtures\CreatesImportedCustomers;
use CartBridgeJP\Pro\Tests\Fixtures\PreparesPasswordEmail;
use CartBridgeJP\Tests\Woo\WooTestCase;
use WPDieException;

/**
 * Pro の設定画面のタブ「パスワード設定メール」（R3-10）。操作は権限と nonce を確かめてからだけ行い、一斉送信の開始・再開だけキーの有効化が要る。
 * 断った要求は状態・予約・記録を変えず、メールも送らない。
 */
final class PasswordEmailPageTest extends WooTestCase {

	use ActivatesLicense;
	use CreatesImportedCustomers;
	use PreparesPasswordEmail;

	private PasswordEmailPage $page;

	public function set_up(): void {
		parent::set_up();
		$this->prepare_password_email();
		$this->page = new PasswordEmailPage( new LicenseStore(), LegacyAccountEstimator::create() );
		as_unschedule_all_actions( Sender::HOOK );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ), '前のテストの予約が残っていない' );
	}

	public function tear_down(): void {
		foreach ( [ '_wpnonce', PasswordEmailPage::FIELD_CONFIRM, PasswordEmailPage::FIELD_CONFIRM_ENVIRONMENT, PasswordEmailPage::FIELD_INCLUDE_LEGACY, PasswordEmailPage::FIELD_RUN_ID, PasswordEmailPage::FIELD_TOKEN ] as $field ) {
			unset( $_POST[ $field ], $_REQUEST[ $field ] );
		}
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	public function test_registers_only_logged_in_actions(): void {
		$this->page->register();

		foreach ( PasswordEmailPage::actions() as $action ) {
			$this->assertSame( 10, has_action( 'admin_post_' . $action, [ $this->page, 'handle_request' ] ), $action );
			$this->assertFalse( has_action( 'admin_post_nopriv_' . $action ), 'ログインしていない要求は受けない' );
		}
	}

	public function test_the_booted_plugin_registers_the_email_and_the_actions(): void {
		foreach ( PasswordEmailPage::actions() as $action ) {
			$this->assertTrue( has_action( 'admin_post_' . $action ), $action );
		}
		$this->assertTrue( has_action( Sender::HOOK ) );
		$this->assertNotNull( EmailRegistry::email() );
	}

	/**
	 * @dataProvider all_actions
	 */
	public function test_rejects_a_user_without_the_capability( string $action ): void {
		$this->imported_customer( '1' );
		$this->activate_license();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->forget_sent_mails();
		$this->post( $action, $this->start_fields() );

		$this->assert_dies( $action, 'not allowed to send password setup emails' );
	}

	/**
	 * @dataProvider all_actions
	 */
	public function test_rejects_a_request_without_a_valid_nonce( string $action ): void {
		$this->imported_customer( '1' );
		$this->activate_license();
		$this->as_shop_manager();
		$this->post( $action, $this->start_fields() );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'another-action' );

		$this->assert_dies( $action, 'The link you followed has expired.' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function all_actions(): array {
		$actions = [];

		foreach ( PasswordEmailPage::actions() as $action ) {
			$actions[ $action ] = [ $action ];
		}

		return $actions;
	}

	/**
	 * テスト送信はキーが無くても使え、自分のアドレスへ見本で送る（一斉送信は始めない）。
	 */
	public function test_sends_a_test_email_without_a_license(): void {
		$this->as_shop_manager( 'manager@example.com' );
		$this->post( PasswordEmailPage::ACTION_TEST );

		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_TEST ) );

		$mails = $this->sent_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'manager@example.com', $mails[0]['to'][0][0] );
		$this->assertNull( Campaign::control() );
		$this->assertStringContainsString( 'A test email was sent to manager@example.com.', $this->render() );
	}

	/**
	 * キーが無ければ一斉送信を始めない。状態・予約・記録・メールのどれも変えない。
	 */
	public function test_refuses_to_start_without_a_license(): void {
		$user_id = $this->imported_customer( '1' );
		$this->as_shop_manager();
		$this->post( PasswordEmailPage::ACTION_START, $this->start_fields() );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_START ) );

		$this->assert_nothing_started();
		$this->assertNull( SendRecord::get( $user_id ) );
		$this->assertStringContainsString( 'requires an activated license key', $this->render() );
	}

	/**
	 * @dataProvider missing_confirmations
	 *
	 * @param array<string,string> $fields
	 */
	public function test_refuses_to_start_without_the_confirmations( array $fields, string $reason ): void {
		$this->imported_customer( '1' );
		$this->activate_license();
		$this->as_shop_manager();
		$this->post( PasswordEmailPage::ACTION_START, $fields );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_START ) );

		$this->assert_nothing_started();
		$this->assertStringContainsString( $reason, $this->render() );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function missing_confirmations(): array {
		return [
			'store moved'    => [ [ PasswordEmailPage::FIELD_CONFIRM_ENVIRONMENT => '1' ], 'Confirm that the store has moved' ],
			'not "1"'        => [
				[
					PasswordEmailPage::FIELD_CONFIRM => 'yes',
					PasswordEmailPage::FIELD_CONFIRM_ENVIRONMENT => '1',
				],
				'Confirm that the store has moved',
			],
			'non-production' => [ [ PasswordEmailPage::FIELD_CONFIRM => '1' ], 'not marked as a production site' ],
		];
	}

	public function test_refuses_to_start_when_the_email_is_disabled(): void {
		$this->imported_customer( '1' );
		$this->activate_license();
		$this->as_shop_manager();
		add_filter( 'woocommerce_email_enabled_' . EmailRegistry::EMAIL_ID, '__return_false' );
		$this->post( PasswordEmailPage::ACTION_START, $this->start_fields() );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_START ) );

		$this->assert_nothing_started();
	}

	public function test_starts_sending_with_a_license(): void {
		$this->imported_customer( '1' );
		$this->activate_license();
		$this->as_shop_manager();
		$this->post( PasswordEmailPage::ACTION_START, $this->start_fields() );

		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_START ) );

		$control = Campaign::control();
		$this->assertSame( Campaign::STATUS_RUNNING, $control['status'] );
		$this->assertFalse( $control['include_legacy'] );
		$this->assertNotFalse( as_next_scheduled_action( Sender::HOOK, [ $control['run_id'], $control['token'] ], Sender::GROUP ) );
		$this->assertSame( [], $this->sent_mails(), '送るのはバックグラウンド' );
	}

	public function test_refuses_a_second_start_while_running(): void {
		$this->activate_license();
		$this->as_shop_manager();
		$running = Campaign::start( 1, false, [] );
		$before  = StateOption::read( Campaign::CONTROL_OPTION );
		$this->post( PasswordEmailPage::ACTION_START, $this->start_fields() );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_START ) );

		$this->assertSame( $before, StateOption::read( Campaign::CONTROL_OPTION ) );
		$this->assertSame( $running['run_id'], Campaign::progress()['run_id'] );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
	}

	/**
	 * 0.1.0 の推定は、チェックしたときだけ run に含める。
	 */
	public function test_includes_legacy_accounts_only_when_checked(): void {
		$this->legacy_import_job( '2026-09-01 10:00:00', '2026-09-01 10:05:00' );
		$legacy = $this->legacy_customer( '9', '2026-09-01 10:01:00' );
		$this->activate_license();
		$this->as_shop_manager();
		$this->post( PasswordEmailPage::ACTION_START, $this->start_fields() + [ PasswordEmailPage::FIELD_INCLUDE_LEGACY => '1' ] );

		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_START ) );

		$control = Campaign::control();
		$this->assertTrue( $control['include_legacy'] );
		$this->assertSame( [ $legacy ], Campaign::legacy_ids( $control['run_id'] ) );
	}

	/**
	 * 取消はキーが無くても使える（キーを外した後にも止められる）。予約も消す。
	 */
	public function test_cancels_without_a_license(): void {
		$this->as_shop_manager();
		$run = Campaign::start( 1, false, [] );
		Sender::schedule( $run['run_id'], $run['token'], 60 );
		$this->post( PasswordEmailPage::ACTION_CANCEL, [ PasswordEmailPage::FIELD_RUN_ID => $run['run_id'] ] );

		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_CANCEL ) );

		$this->assertSame( Campaign::STATUS_CANCELLED, Campaign::control()['status'] );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
	}

	/**
	 * 画面が見ていた run と違えば取り消さない（古い画面から新しい run を止めない）。
	 */
	public function test_does_not_cancel_another_run(): void {
		$this->as_shop_manager();
		Campaign::start( 1, false, [] );
		$this->post( PasswordEmailPage::ACTION_CANCEL, [ PasswordEmailPage::FIELD_RUN_ID => str_repeat( 'a', 32 ) ] );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_CANCEL ) );

		$this->assertSame( Campaign::STATUS_RUNNING, Campaign::control()['status'] );
	}

	public function test_resumes_a_stopped_run_with_a_license(): void {
		$this->activate_license();
		$this->as_shop_manager();
		$run = Campaign::start( 1, false, [] );
		Campaign::stop( $run['run_id'], $run['token'], Campaign::STOP_MAIL_FAILING );
		$this->post(
			PasswordEmailPage::ACTION_RESUME,
			[
				PasswordEmailPage::FIELD_RUN_ID => $run['run_id'],
				PasswordEmailPage::FIELD_TOKEN  => $run['token'],
			]
		);

		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_RESUME ) );

		$control = Campaign::control();
		$this->assertSame( Campaign::STATUS_RUNNING, $control['status'] );
		$this->assertNotSame( $run['token'], $control['token'] );
		$this->assertNotFalse( as_next_scheduled_action( Sender::HOOK, [ $run['run_id'], $control['token'] ], Sender::GROUP ) );
	}

	/**
	 * 壊れた記録: `sent:` で始まる値は送信済みとして読み（「未送信に戻す」の対象の SQL と同じ区切り。戻せないのに途中で止まったと出さない）、
	 * それ以外の壊れた値は途中で止まったとして読み、未送信に戻せる（PR #135 G2-1）。
	 */
	public function test_reads_broken_records_consistently_with_the_cleanup(): void {
		$this->as_shop_manager();
		$sent_broken  = $this->imported_customer( '1' );
		$other_broken = $this->imported_customer( '2' );
		update_user_meta( $sent_broken, SendRecord::META, 'sent:truncated' );
		update_user_meta( $other_broken, SendRecord::META, 'garbage' );

		$this->assertSame( SendRecord::STATE_SENT, SendRecord::get( $sent_broken )['state'] );
		$this->assertSame( SendRecord::STATE_SENDING, SendRecord::get( $other_broken )['state'] );
		$this->assertSame( [ $other_broken ], SendRecord::unsent_user_ids() );

		$this->post( PasswordEmailPage::ACTION_CLEAR_SENDING );
		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_CLEAR_SENDING ) );

		$this->assertNull( SendRecord::get( $other_broken ) );
		$this->assertSame( SendRecord::STATE_SENT, SendRecord::get( $sent_broken )['state'] );
	}

	/**
	 * 「未送信に戻す」は、読んだ後にバッチが `sent` に書き換えた記録を消さない（値が一致するときだけ消す。PR #135 G1-2）。
	 */
	public function test_does_not_clear_a_record_that_was_just_marked_sent(): void {
		global $wpdb;

		$this->as_shop_manager();
		$user_id = $this->imported_customer( '1' );
		SendRecord::mark_sending( $user_id, str_repeat( 'a', 32 ) );
		$sent = 'sent:' . str_repeat( 'a', 32 ) . ':1700000000';
		$done = false;
		add_filter(
			'query',
			static function ( string $sql ) use ( &$done, $wpdb, $user_id, $sent ): string {
				if ( ! $done && str_starts_with( $sql, "DELETE FROM {$wpdb->usermeta}" ) && str_contains( $sql, SendRecord::META ) ) {
					$done = true;
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s", $sent, $user_id, SendRecord::META ) );
				}

				return $sql;
			}
		);

		$this->assertFalse( SendRecord::clear_sending( $user_id ) );

		$this->assertTrue( $done, '削除の直前に書き換えた' );
		wp_cache_delete( $user_id, 'user_meta' );
		$this->assertSame( $sent, get_user_meta( $user_id, SendRecord::META, true ) );
	}

	/**
	 * 中断した run（進行中なのに予約が無く、最後のバッチから排他の期限より長く経った）は再開でき、中断の間は「未送信に戻す」もできる（R1-4）。
	 */
	public function test_resumes_an_interrupted_run(): void {
		$this->activate_license();
		$this->as_shop_manager();
		$sending = $this->imported_customer( '1' );
		SendRecord::mark_sending( $sending, str_repeat( 'a', 32 ) );
		$run = Campaign::start( 1, false, [] );
		$this->make_old( $run['run_id'] );

		$this->post( PasswordEmailPage::ACTION_CLEAR_SENDING );
		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_CLEAR_SENDING ) );
		$this->assertNull( SendRecord::get( $sending ) );

		$this->post(
			PasswordEmailPage::ACTION_RESUME,
			[
				PasswordEmailPage::FIELD_RUN_ID => $run['run_id'],
				PasswordEmailPage::FIELD_TOKEN  => $run['token'],
			]
		);

		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_RESUME ) );

		$control = Campaign::control();
		$this->assertSame( Campaign::STATUS_RUNNING, $control['status'] );
		$this->assertNotSame( $run['token'], $control['token'] );
		$this->assertNotFalse( as_next_scheduled_action( Sender::HOOK, [ $run['run_id'], $control['token'] ], Sender::GROUP ) );
	}

	/**
	 * 開始の予約に失敗したら止め、理由を出す（R1-4）。
	 */
	public function test_stops_a_run_whose_first_batch_cannot_be_scheduled(): void {
		$this->imported_customer( '1' );
		$this->activate_license();
		$this->as_shop_manager();
		add_filter( 'pre_as_schedule_single_action', static fn () => 0 );
		$this->post( PasswordEmailPage::ACTION_START, $this->start_fields() );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_START ) );

		$this->assertSame( Campaign::STATUS_STOPPED, Campaign::control()['status'] );
		$this->assertSame( Campaign::STOP_SCHEDULE_FAILED, Campaign::control()['reason'] );
		$this->assertStringContainsString( 'could not be scheduled', $this->render() );
	}

	/**
	 * 画面の件数を数えた後、読んだユーザーのキャッシュを手放す（全員を読むときのメモリを抑える。R1-1）。
	 */
	public function test_releases_the_user_cache_after_counting(): void {
		$this->as_shop_manager();
		$user_id = $this->imported_customer( '1' );
		clean_user_cache( $user_id );

		$this->render();

		$this->assertFalse( wp_cache_get( $user_id, 'users' ) );
		$this->assertFalse( wp_cache_get( $user_id, 'user_meta' ) );
	}

	/**
	 * キーが無ければ再開しない。動いている run（中断していない）も再開しない（チェーンを 2 本にしない）。
	 */
	public function test_refuses_to_resume_without_a_license_or_a_live_run(): void {
		$this->as_shop_manager();
		$run = Campaign::start( 1, false, [] );
		Campaign::stop( $run['run_id'], $run['token'], Campaign::STOP_MAIL_FAILING );
		$fields = [
			PasswordEmailPage::FIELD_RUN_ID => $run['run_id'],
			PasswordEmailPage::FIELD_TOKEN  => $run['token'],
		];
		$this->post( PasswordEmailPage::ACTION_RESUME, $fields );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_RESUME ) );
		$this->assertSame( Campaign::STATUS_STOPPED, Campaign::control()['status'] );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );

		$this->activate_license();
		Campaign::cancel( $run['run_id'] );
		$live = Campaign::start( 1, false, [] );
		$this->post(
			PasswordEmailPage::ACTION_RESUME,
			[
				PasswordEmailPage::FIELD_RUN_ID => $live['run_id'],
				PasswordEmailPage::FIELD_TOKEN  => $live['token'],
			]
		);

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_RESUME ) );
		$this->assertSame( $live['token'], Campaign::control()['token'] );
	}

	/**
	 * `sending` のまま残った顧客を未送信に戻す（送れた記録は残す）。送信中は断る。
	 */
	public function test_marks_interrupted_emails_as_not_sent(): void {
		$this->as_shop_manager();
		$interrupted = $this->imported_customer( '1' );
		$sent        = $this->imported_customer( '2' );
		SendRecord::mark_sending( $interrupted, str_repeat( 'a', 32 ) );
		update_user_meta( $sent, SendRecord::META, 'sent:' . str_repeat( 'a', 32 ) . ':1700000000' );
		$running = Campaign::start( 1, false, [] );
		Sender::schedule( $running['run_id'], $running['token'], 60 );
		$this->post( PasswordEmailPage::ACTION_CLEAR_SENDING );

		$this->assertFalse( $this->page->process( PasswordEmailPage::ACTION_CLEAR_SENDING ), '送信中' );
		$this->assertSame( SendRecord::STATE_SENDING, SendRecord::get( $interrupted )['state'] );

		Campaign::cancel( $running['run_id'] );
		$this->post( PasswordEmailPage::ACTION_CLEAR_SENDING );

		$this->assertTrue( $this->page->process( PasswordEmailPage::ACTION_CLEAR_SENDING ) );
		$this->assertNull( SendRecord::get( $interrupted ) );
		$this->assertSame( SendRecord::STATE_SENT, SendRecord::get( $sent )['state'] );
	}

	public function test_handles_a_request_from_admin_post(): void {
		$this->as_shop_manager();
		$this->post( PasswordEmailPage::ACTION_TEST );
		$terminated = false;
		$page       = new PasswordEmailPage(
			new LicenseStore(),
			LegacyAccountEstimator::create(),
			static function () use ( &$terminated ): void {
				$terminated = true;
			}
		);
		remove_all_actions( 'admin_post_' . PasswordEmailPage::ACTION_TEST );
		$page->register();
		$redirected_to = null;
		add_filter(
			'wp_redirect',
			static function ( string $location ) use ( &$redirected_to ): string {
				$redirected_to = $location;

				return '';
			}
		);

		do_action( 'admin_post_' . PasswordEmailPage::ACTION_TEST );

		$this->assertSame( PasswordEmailPage::page_url(), $redirected_to );
		$this->assertTrue( $terminated );
		$this->assertCount( 1, $this->sent_mails() );
	}

	/**
	 * 件数・理由ごとの内訳・0.1.0 の推定の一覧を出し、開始のフォームはキーがあるときだけ出す。
	 */
	public function test_shows_the_recipients_and_the_forms(): void {
		$this->as_shop_manager();
		$this->imported_customer( '1' );
		$reset = $this->imported_customer( '2' );
		reset_password( get_userdata( $reset ), 'set' );
		$this->legacy_import_job( '2026-09-01 10:00:00', '2026-09-01 10:05:00' );
		$legacy = $this->legacy_customer( '9', '2026-09-01 10:01:00' );
		wp_update_user(
			[
				'ID'           => $legacy,
				'display_name' => 'Legacy & Co',
			]
		);

		$without_key = $this->render();

		$this->assertMatchesRegularExpression( '#Will receive the email</th><td>1</td>#', $without_key, '推定を除いた件数' );
		$this->assertMatchesRegularExpression( '#Left out: Already set a password</th><td>1</td>#', $without_key );
		$this->assertStringContainsString( 'Accounts likely imported by version 0.1.0', $without_key );
		$this->assertStringContainsString( 'Legacy &amp; Co', $without_key );
		$this->assertStringContainsString( 'name="action" value="' . PasswordEmailPage::ACTION_TEST . '"', $without_key );
		$this->assertStringNotContainsString( 'value="' . PasswordEmailPage::ACTION_START . '"', $without_key );
		$this->assertStringContainsString( 'requires an activated license key', $without_key );

		$this->activate_license();
		$with_key = $this->render();

		$this->assertStringContainsString( 'value="' . PasswordEmailPage::ACTION_START . '"', $with_key );
		$this->assertStringContainsString( 'name="' . PasswordEmailPage::FIELD_INCLUDE_LEGACY . '"', $with_key );
		$this->assertStringContainsString( 'name="_wpnonce"', $with_key );
	}

	public function test_renders_nothing_for_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Pro の設定画面はタブで切り替える（メニューは 1 つ）。
	 */
	public function test_the_license_page_shows_the_tab(): void {
		$this->as_shop_manager();
		$license = new LicensePage( \CartBridgeJP\Pro\License\LicenseManager::create(), null, $this->page );

		ob_start();
		$license->render_page();
		$license_tab = (string) ob_get_clean();

		$_GET['tab'] = LicensePage::TAB_PASSWORD_EMAILS;
		ob_start();
		$license->render_page();
		$email_tab = (string) ob_get_clean();

		$this->assertStringContainsString( 'nav-tab-wrapper', $license_tab );
		$this->assertStringContainsString( 'Enter the license key', $license_tab );
		$this->assertStringNotContainsString( 'Send a test email', $license_tab );
		$this->assertStringContainsString( 'Send a test email', $email_tab );
		$this->assertStringNotContainsString( 'Enter the license key', $email_tab );
	}

	/**
	 * 進行中の run を、予約が無く最後の動きが古い（中断した）状態にする。
	 */
	private function make_old( string $run_id ): void {
		$control               = Campaign::control();
		$control['started_at'] = time() - 3600;
		$control['updated_at'] = time() - 3600;
		update_option( Campaign::CONTROL_OPTION, (string) wp_json_encode( $control ) );
		$this->assertSame( $run_id, Campaign::control()['run_id'] );
		$this->assertTrue( Sender::is_interrupted( Campaign::control(), Campaign::progress() ) );
	}

	private function assert_nothing_started(): void {
		$this->assertNull( Campaign::control() );
		$this->assertNull( StateOption::read( Campaign::PROGRESS_OPTION ) );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
		$this->assertSame( [], $this->sent_mails() );
	}

	private function assert_dies( string $action, string $reason ): void {
		$died = null;

		try {
			$this->page->process( $action );
		} catch ( WPDieException $e ) {
			$died = $e;
		}

		$this->assertInstanceOf( WPDieException::class, $died );
		$this->assertStringContainsString( $reason, $died->getMessage() );
		$this->assert_nothing_started();
		$this->assertFalse( get_transient( 'cbjp_pro_password_email_notice_' . get_current_user_id() ) );
	}

	/**
	 * @return array<string,string>
	 */
	private function start_fields(): array {
		return [
			PasswordEmailPage::FIELD_CONFIRM             => '1',
			PasswordEmailPage::FIELD_CONFIRM_ENVIRONMENT => '1',
		];
	}

	private function as_shop_manager( string $email = 'shop@example.com' ): void {
		wp_set_current_user(
			self::factory()->user->create(
				[
					'role'       => 'shop_manager',
					'user_email' => $email,
				]
			)
		);
		$this->forget_sent_mails();
	}

	/**
	 * @param array<string,string> $fields
	 */
	private function post( string $action, array $fields = [] ): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );

		foreach ( $fields as $field => $value ) {
			$_POST[ $field ] = $value;
		}
	}

	private function render(): string {
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}
}
