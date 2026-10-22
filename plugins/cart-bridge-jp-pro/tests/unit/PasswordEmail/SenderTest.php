<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\PasswordEmail;

use CartBridgeJP\Pro\PasswordEmail\Campaign;
use CartBridgeJP\Pro\PasswordEmail\EmailRegistry;
use CartBridgeJP\Pro\PasswordEmail\LegacyAccountEstimator;
use CartBridgeJP\Pro\PasswordEmail\Sender;
use CartBridgeJP\Pro\PasswordEmail\SendLock;
use CartBridgeJP\Pro\PasswordEmail\SendRecord;
use CartBridgeJP\Pro\PasswordEmail\StateOption;
use CartBridgeJP\Pro\Tests\Fixtures\CreatesImportedCustomers;
use CartBridgeJP\Pro\Tests\Fixtures\PreparesPasswordEmail;
use CartBridgeJP\Tests\Woo\WooTestCase;

/**
 * パスワード設定メールの一斉送信（R3-10）: Action Scheduler の 1 アクションが 1 バッチ。送る前に記録し、取消・別のチェーン・連続の失敗で止まる。
 */
final class SenderTest extends WooTestCase {

	use CreatesImportedCustomers;
	use PreparesPasswordEmail;

	public function set_up(): void {
		parent::set_up();
		$this->prepare_password_email();
		as_unschedule_all_actions( Sender::HOOK );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ), '前のテストの予約が残っていない' );
	}

	public function test_sends_to_every_recipient_and_finishes(): void {
		$first  = $this->imported_customer( '1' );
		$second = $this->imported_customer( '2' );
		$staff  = $this->imported_customer( '3' );
		get_userdata( $staff )->set_role( 'shop_manager' );
		$run = $this->start();

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertSame( [ 'customer1@example.com', 'customer2@example.com' ], $this->recipients() );
		$this->assertSame( SendRecord::STATE_SENT, SendRecord::get( $first )['state'] );
		$this->assertSame( SendRecord::STATE_SENT, SendRecord::get( $second )['state'] );
		$this->assertNull( SendRecord::get( $staff ) );
		$this->assertSame( Campaign::STATUS_COMPLETED, Campaign::control()['status'] );
		$this->assertSame( 2, Campaign::progress()['sent'] );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ), '終わったら次を予約しない' );
	}

	/**
	 * 1 バッチの上限で区切り、トークンを替えて次のバッチを予約する。次のバッチは続きから送り、同じ顧客へ 2 通目を送らない。
	 */
	public function test_continues_in_the_next_batch_with_a_new_token(): void {
		add_filter( 'cbjp/pro/password_email/batch_size', static fn () => 2 );
		$this->imported_customer( '1' );
		$this->imported_customer( '2' );
		$this->imported_customer( '3' );
		$run = $this->start();

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertCount( 2, $this->recipients() );
		$next = Campaign::control()['token'];
		$this->assertNotSame( $run['token'], $next );
		$this->assertNotFalse( as_next_scheduled_action( Sender::HOOK, [ $run['run_id'], $next ], Sender::GROUP ) );

		Sender::handle( $run['run_id'], $run['token'] );
		$this->assertCount( 2, $this->recipients(), '古いトークンのアクションは何もしない' );

		Sender::handle( $run['run_id'], $next );

		$this->assertSame( [ 'customer1@example.com', 'customer2@example.com', 'customer3@example.com' ], $this->recipients() );
		$this->assertSame( Campaign::STATUS_COMPLETED, Campaign::control()['status'] );
	}

	/**
	 * 候補を上限（200 人）まで見て送る相手がいなくても、上限に達したら完了せずに次のバッチへ引き継ぐ（最初の 200 人だけで「完了」にしない。R1-2）。
	 */
	public function test_does_not_finish_while_candidates_remain_beyond_the_scan_limit(): void {
		for ( $i = 0; $i < Sender::SCAN_LIMIT; $i++ ) {
			$linked = self::factory()->user->create( [ 'role' => 'customer' ] );
			update_user_meta( $linked, '_cbjp_remote_id', "linked{$i}" );
		}
		$this->imported_customer( '1' );
		$run = $this->start();

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertSame( [], $this->recipients() );
		$this->assertSame( Campaign::STATUS_RUNNING, Campaign::control()['status'] );
		$next = Campaign::control()['token'];
		$this->assertNotSame( $run['token'], $next );

		Sender::handle( $run['run_id'], $next );

		$this->assertSame( [ 'customer1@example.com' ], $this->recipients() );
		$this->assertSame( Campaign::STATUS_COMPLETED, Campaign::control()['status'] );
	}

	/**
	 * 排他の期限が近ければ送らずに引き継ぐ（期限の後は他のバッチが奪いうる。R1-3）。
	 */
	public function test_does_not_send_without_holding_the_lock(): void {
		$this->imported_customer( '1' );
		$run = $this->start();

		Sender::run_batch( $run['run_id'], $run['token'], ( time() + SendLock::TTL ) . '|' . str_repeat( 'a', 32 ) );

		$this->assertSame( [], $this->recipients() );
		$this->assertSame( 0, Campaign::progress()['cursor'] );
		$this->assertSame( $run['token'], Campaign::control()['token'], '引き継がない（排他を持つバッチが続ける）' );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
	}

	/**
	 * 送信が長引いて排他を他のバッチに取られたら、次の宛先へ送らず、進捗も引継ぎも書かない（2 つのバッチが同じカーソルを進めない。PR #135 G1-4）。
	 */
	public function test_stops_writing_when_another_batch_takes_the_lock(): void {
		global $wpdb;

		$this->imported_customer( '1' );
		$this->imported_customer( '2' );
		$run = $this->start();
		// 相手の排他の値は 1 度だけ作る（書くときと比べるときで time() を別々に呼ぶと、秒をまたいだときに違う値になる。PR #150 の CI で 1 度落ちた）。
		$other_lock = ( time() + 300 ) . '|' . str_repeat( 'd', 32 );
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( $wpdb, $other_lock ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $other_lock, SendLock::OPTION ) );

				return $short_circuit;
			}
		);

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertSame( [ 'customer1@example.com' ], $this->recipients() );
		$this->assertSame( 0, Campaign::progress()['sent'], '進捗を書かない' );
		$this->assertSame( $run['token'], Campaign::control()['token'], '引き継がない' );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
		$this->assertSame( $other_lock, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", SendLock::OPTION ) ), '他のバッチの排他を消さない' );
	}

	/**
	 * 最後の 1 通の送信中に排他を取られたら、完了も進捗も書かない（状態を書く前にも借りを確かめる。PR #135 G1-4）。
	 */
	public function test_does_not_finish_when_the_lock_is_lost_during_the_last_send(): void {
		global $wpdb;

		$this->imported_customer( '1' );
		$run = $this->start();
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( $wpdb ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", ( time() + 300 ) . '|' . str_repeat( 'd', 32 ), SendLock::OPTION ) );

				return $short_circuit;
			}
		);

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertCount( 1, $this->recipients() );
		$this->assertSame( Campaign::STATUS_RUNNING, Campaign::control()['status'], '完了にしない（排他を持つバッチが続ける）' );
		$this->assertSame( 0, Campaign::progress()['sent'] );
	}

	/**
	 * 送る前の記録を書けなければ送らず、続けて 3 人書けなければ止める（最大 1 通を守れないので。PR #135 G1-6）。
	 */
	public function test_does_not_send_when_the_record_cannot_be_saved(): void {
		foreach ( [ '1', '2', '3', '4' ] as $remote ) {
			$this->imported_customer( $remote );
		}
		$run = $this->start();
		add_filter(
			'update_user_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return SendRecord::META === $meta_key ? true : $check;
			},
			10,
			3
		);

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertSame( [], $this->recipients() );
		$this->assertSame( Campaign::STATUS_STOPPED, Campaign::control()['status'] );
		$this->assertSame( Campaign::STOP_RECORD_FAILING, Campaign::control()['reason'] );
		$this->assertSame( 3, Campaign::progress()['failed'] );
	}

	/**
	 * 取消は取り消した run の予約だけを外す（取消の直後に始まった別の run の予約は残す。PR #135 G1-1）。
	 */
	public function test_unschedules_only_the_named_run(): void {
		$cancelled = str_repeat( 'a', 32 );
		$other     = str_repeat( 'b', 32 );
		Sender::schedule( $cancelled, str_repeat( 'c', 32 ), 60 );
		Sender::schedule( $cancelled, str_repeat( 'd', 32 ), 120 );
		Sender::schedule( $other, str_repeat( 'e', 32 ), 60 );

		Sender::unschedule_run( $cancelled );

		$this->assertFalse( as_has_scheduled_action( Sender::HOOK, [ $cancelled, str_repeat( 'c', 32 ) ], Sender::GROUP ) );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK, [ $cancelled, str_repeat( 'd', 32 ) ], Sender::GROUP ) );
		$this->assertTrue( as_has_scheduled_action( Sender::HOOK, [ $other, str_repeat( 'e', 32 ) ], Sender::GROUP ) );
	}

	/**
	 * 次のバッチを予約できなければ止める（チェーンが黙って切れない。R1-4）。
	 */
	public function test_stops_when_the_next_batch_cannot_be_scheduled(): void {
		add_filter( 'cbjp/pro/password_email/batch_size', static fn () => 1 );
		$this->imported_customer( '1' );
		$this->imported_customer( '2' );
		$run = $this->start();
		add_filter( 'pre_as_schedule_single_action', static fn () => 0 );

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertCount( 1, $this->recipients() );
		$this->assertSame( Campaign::STATUS_STOPPED, Campaign::control()['status'] );
		$this->assertSame( Campaign::STOP_SCHEDULE_FAILED, Campaign::control()['reason'] );
	}

	/**
	 * 送る前に記録する（送信の途中で処理が落ちても、2 通目を送らない）。`pre_wp_mail` の中で DB の値を直接読んで確かめる。
	 */
	public function test_records_the_customer_before_sending(): void {
		global $wpdb;

		$user_id = $this->imported_customer( '1' );
		$run     = $this->start();
		$seen    = null;
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( &$seen, $wpdb, $user_id ) {
				$seen = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $user_id, SendRecord::META ) );

				return $short_circuit;
			}
		);

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertIsString( $seen );
		$this->assertStringStartsWith( 'sending:' . $run['run_id'] . ':', $seen );
		$this->assertStringStartsWith( 'sent:' . $run['run_id'] . ':', get_user_meta( $user_id, SendRecord::META, true ) );
	}

	/**
	 * 送信中に取り消されたら、次の宛先から先は送らず、取消をバッチの書き戻しで消さない（状態は制御と進捗に分けてある）。
	 */
	public function test_stops_when_cancelled_during_the_batch(): void {
		$this->imported_customer( '1' );
		$this->imported_customer( '2' );
		$this->imported_customer( '3' );
		$run = $this->start();
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( $run ) {
				Campaign::cancel( $run['run_id'] );

				return $short_circuit;
			}
		);

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertCount( 1, $this->recipients() );
		$this->assertSame( Campaign::STATUS_CANCELLED, Campaign::control()['status'] );
		$this->assertSame( 1, Campaign::progress()['sent'], '送った分の進捗は書く' );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
	}

	/**
	 * 送る直前に判定をやり直す（開始の後にパスワードを設定し直した顧客には送らない）。
	 */
	public function test_reevaluates_each_customer_right_before_sending(): void {
		$user_id = $this->imported_customer( '1' );
		$run     = $this->start();
		reset_password( get_userdata( $user_id ), 'set by the customer' );
		$this->forget_sent_mails();

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertSame( [], $this->recipients() );
		$this->assertNull( SendRecord::get( $user_id ) );
	}

	/**
	 * 送れなかった顧客は記録を消して失敗を残し（次の送信で送り直す）、続けて 3 通送れなければ止める（残りを全員「失敗」にしない）。
	 */
	public function test_stops_after_consecutive_failures(): void {
		$ids = [];
		foreach ( [ '1', '2', '3', '4' ] as $remote ) {
			$ids[] = $this->imported_customer( $remote );
		}
		$run = $this->start();
		add_filter( 'pre_wp_mail', '__return_false' );

		Sender::handle( $run['run_id'], $run['token'] );

		$control = Campaign::control();
		$this->assertSame( Campaign::STATUS_STOPPED, $control['status'] );
		$this->assertSame( Campaign::STOP_MAIL_FAILING, $control['reason'] );
		$this->assertSame( 3, Campaign::progress()['failed'] );
		foreach ( array_slice( $ids, 0, 3 ) as $user_id ) {
			$this->assertNull( SendRecord::get( $user_id ) );
			$this->assertSame( Sender::FAILURE_MAIL, SendRecord::failure( $user_id )['reason'] );
		}
		$this->assertNull( SendRecord::failure( $ids[3] ), '4 人目は試さない' );
		$this->assertSame( $ids[2], Campaign::progress()['cursor'], 'カーソルは試した宛先まで' );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
	}

	/**
	 * 失敗した後に送れたら、失敗の記録を消し、連続の数を戻す。
	 */
	public function test_a_success_clears_the_failure(): void {
		$user_id = $this->imported_customer( '1' );
		update_user_meta( $user_id, SendRecord::FAILED_META, str_repeat( 'a', 32 ) . ':1700000000:' . Sender::FAILURE_MAIL );
		$run = $this->start();

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertNull( SendRecord::failure( $user_id ) );
		$this->assertSame( SendRecord::STATE_SENT, SendRecord::get( $user_id )['state'] );
	}

	/**
	 * 他のバッチが排他を持っていれば送らず、今のチェーンなら同じトークンで後に回す（チェーンを切らない）。
	 */
	public function test_waits_while_another_batch_holds_the_lock(): void {
		$this->imported_customer( '1' );
		$run  = $this->start();
		$lock = SendLock::acquire();

		Sender::handle( $run['run_id'], $run['token'] );

		$this->assertSame( [], $this->recipients() );
		$this->assertNotFalse( as_next_scheduled_action( Sender::HOOK, [ $run['run_id'], $run['token'] ], Sender::GROUP ) );
		$this->assertSame( Campaign::STATUS_RUNNING, Campaign::control()['status'] );
		SendLock::release( (string) $lock );
	}

	public function test_does_nothing_for_another_chain_or_run(): void {
		$this->imported_customer( '1' );
		$run = $this->start();

		Sender::handle( $run['run_id'], str_repeat( 'b', 32 ) );
		Sender::handle( str_repeat( 'c', 32 ), $run['token'] );
		Sender::handle( [ 'not a string' ], $run['token'] );

		$this->assertSame( [], $this->recipients() );
		$this->assertSame( $run['token'], Campaign::control()['token'] );
		$this->assertFalse( as_has_scheduled_action( Sender::HOOK ) );
	}

	/**
	 * メールが無効・マイアカウントのページが使えない・進捗が run と食い違うときは送らずに止まる。
	 */
	public function test_stops_when_the_email_cannot_be_sent(): void {
		$this->imported_customer( '1' );

		add_filter( 'woocommerce_email_enabled_' . EmailRegistry::EMAIL_ID, '__return_false' );
		$run = $this->start();
		Sender::handle( $run['run_id'], $run['token'] );
		$this->assertSame( Campaign::STOP_EMAIL_UNAVAILABLE, Campaign::control()['reason'] );
		remove_all_filters( 'woocommerce_email_enabled_' . EmailRegistry::EMAIL_ID );

		Campaign::cancel( $run['run_id'] );
		update_option( 'woocommerce_myaccount_lost_password_endpoint', '' );
		$run = $this->start();
		Sender::handle( $run['run_id'], $run['token'] );
		$this->assertSame( Campaign::STOP_ACCOUNT_PAGE, Campaign::control()['reason'] );
		delete_option( 'woocommerce_myaccount_lost_password_endpoint' );

		Campaign::cancel( $run['run_id'] );
		$run = $this->start();
		StateOption::delete( Campaign::progress_option( $run['run_id'] ) );
		Sender::handle( $run['run_id'], $run['token'] );
		$this->assertSame( Campaign::STOP_STATE, Campaign::control()['reason'] );

		$this->assertSame( [], $this->recipients() );
	}

	/**
	 * 候補を DB から読めなければ、完了にせず止める（空のページを「見尽くした」と読んで、残りの顧客を黙って飛ばさない。PR #135 G3-1）。
	 */
	public function test_stops_instead_of_finishing_when_candidates_cannot_be_read(): void {
		global $wpdb;

		$this->imported_customer( '1' );
		$run = $this->start();
		add_filter(
			'query',
			static fn ( string $sql ): string => str_contains( $sql, 'SELECT DISTINCT u.ID' ) ? 'SELECT broken FROM no_such_table' : $sql
		);
		$suppress = $wpdb->suppress_errors( true );

		try {
			Sender::handle( $run['run_id'], $run['token'] );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( [], $this->recipients() );
		$this->assertSame( Campaign::STATUS_STOPPED, Campaign::control()['status'] );
		$this->assertSame( Campaign::STOP_SCAN_FAILED, Campaign::control()['reason'] );
	}

	/**
	 * 0.1.0 の推定は、開始のときに含めた run だけが送る。
	 */
	public function test_sends_to_legacy_accounts_only_when_the_run_includes_them(): void {
		$this->legacy_import_job( '2026-09-01 10:00:00', '2026-09-01 10:05:00' );
		$legacy   = $this->legacy_customer( '9', '2026-09-01 10:01:00' );
		$estimate = LegacyAccountEstimator::create()->estimate()['ids'];
		$this->assertSame( [ $legacy ], $estimate );

		$run = $this->start( false, $estimate );
		Sender::handle( $run['run_id'], $run['token'] );
		$this->assertSame( [], $this->recipients() );

		$run = $this->start( true, $estimate );
		Sender::handle( $run['run_id'], $run['token'] );
		$this->assertSame( [ 'legacy9@example.com' ], $this->recipients() );
		$this->assertSame( 1, Campaign::progress()['legacy'] );
		$this->assertSame( 1, Campaign::progress()['unknown'] );
	}

	public function test_batch_settings_are_kept_in_range(): void {
		add_filter( 'cbjp/pro/password_email/batch_size', static fn () => 1000 );
		add_filter( 'cbjp/pro/password_email/interval', static fn () => 1 );
		$this->assertSame( 100, Sender::batch_size() );
		$this->assertSame( 10, Sender::interval() );

		add_filter( 'cbjp/pro/password_email/batch_size', static fn () => '5' );
		add_filter( 'cbjp/pro/password_email/interval', static fn () => null );
		$this->assertSame( Sender::BATCH_SIZE, Sender::batch_size() );
		$this->assertSame( Sender::INTERVAL_SECONDS, Sender::interval() );
	}

	/**
	 * 中断: running なのに生きた予約が無く、最後のバッチから排他の期限より長く経った。予約がある・最近動いた・running でないなら中断ではない。
	 */
	public function test_detects_an_interrupted_run(): void {
		$old     = time() - SendLock::TTL - 10;
		$control = [
			'run_id'     => str_repeat( 'a', 32 ),
			'status'     => Campaign::STATUS_RUNNING,
			'token'      => str_repeat( 'b', 32 ),
			'started_at' => $old,
			'updated_at' => $old,
		];

		$this->assertTrue( Sender::is_interrupted( $control, [ 'last_batch_at' => $old ] ) );
		$this->assertFalse( Sender::is_interrupted( $control, [ 'last_batch_at' => time() ] ) );
		$this->assertFalse( Sender::is_interrupted( [ 'status' => Campaign::STATUS_STOPPED ] + $control, [ 'last_batch_at' => $old ] ) );

		// 別の run・古いトークンの予約は、今の run が生きている証拠にしない（取り消した run の残り。PR #135 G2-3）。
		Sender::schedule( str_repeat( 'c', 32 ), str_repeat( 'b', 32 ), 60 );
		Sender::schedule( str_repeat( 'a', 32 ), str_repeat( 'd', 32 ), 60 );
		$this->assertTrue( Sender::is_interrupted( $control, [ 'last_batch_at' => $old ] ) );

		Sender::schedule( str_repeat( 'a', 32 ), str_repeat( 'b', 32 ), 60 );
		$this->assertFalse( Sender::is_interrupted( $control, [ 'last_batch_at' => $old ] ) );
	}

	/**
	 * 予約を確かめられない（照会の DB エラー）ときは中断と言わない（再開を勧めない。PR #135 G2-3）。
	 */
	public function test_does_not_call_a_run_interrupted_when_the_queue_cannot_be_read(): void {
		global $wpdb;

		$old     = time() - SendLock::TTL - 10;
		$control = [
			'run_id'     => str_repeat( 'a', 32 ),
			'status'     => Campaign::STATUS_RUNNING,
			'token'      => str_repeat( 'b', 32 ),
			'started_at' => $old,
			'updated_at' => $old,
		];
		$table   = $wpdb->prefix . 'actionscheduler_actions';
		add_filter(
			'query',
			static function ( string $sql ) use ( $table ): string {
				return str_contains( $sql, $table ) && str_starts_with( ltrim( $sql ), 'SELECT' ) ? 'SELECT broken FROM no_such_table' : $sql;
			}
		);
		$suppress = $wpdb->suppress_errors( true );

		try {
			$this->assertNull( Sender::has_live_action( $control['run_id'], $control['token'] ) );
			$this->assertFalse( Sender::is_interrupted( $control, [ 'last_batch_at' => $old ] ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * 送信が長引く間に記録が書き換えられた（管理者が未送信に戻し、別の run が送った）ら、送れた・送れなかったで上書きしない（PR #135 G2-2）。
	 *
	 * @dataProvider delivery_results
	 */
	public function test_does_not_overwrite_a_record_replaced_during_the_send( bool $delivered ): void {
		global $wpdb;

		$user_id = $this->imported_customer( '1' );
		$run     = $this->start();
		$other   = 'sent:' . str_repeat( 'e', 32 ) . ':1700000000';
		add_filter(
			'pre_wp_mail',
			static function () use ( $wpdb, $user_id, $other, $delivered ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s", $other, $user_id, SendRecord::META ) );

				return $delivered;
			}
		);

		Sender::handle( $run['run_id'], $run['token'] );

		wp_cache_delete( $user_id, 'user_meta' );
		$this->assertSame( $other, get_user_meta( $user_id, SendRecord::META, true ) );
		$this->assertNull( SendRecord::failure( $user_id ) );
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public static function delivery_results(): array {
		return [
			'delivered' => [ true ],
			'failed'    => [ false ],
		];
	}

	/**
	 * @param array<int,int> $legacy_ids
	 * @return array{run_id:string,token:string}
	 */
	private function start( bool $include_legacy = false, array $legacy_ids = [] ): array {
		$run = Campaign::start( 1, $include_legacy, $legacy_ids );
		$this->assertNotNull( $run );
		$this->forget_sent_mails();

		return $run;
	}

	/**
	 * @return array<int,string>
	 */
	private function recipients(): array {
		return array_map( static fn ( array $mail ): string => (string) $mail['to'][0][0], $this->sent_mails() );
	}
}
