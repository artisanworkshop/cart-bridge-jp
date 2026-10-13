<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\PasswordEmail;

use CartBridgeJP\Pro\PasswordEmail\Campaign;
use CartBridgeJP\Pro\PasswordEmail\StateOption;
use WP_UnitTestCase;

/**
 * パスワード設定メールの run の状態（R3-10）。制御の遷移はすべて、直前に読んだ値と一致するときだけ書く（CAS）。
 */
final class CampaignTest extends WP_UnitTestCase {

	public function test_starts_a_run_and_resets_the_progress(): void {
		$started = Campaign::start( 5, true, [ 3, 4 ] );

		$this->assertNotNull( $started );
		$control = Campaign::control();
		$this->assertSame( $started['run_id'], $control['run_id'] );
		$this->assertSame( $started['token'], $control['token'] );
		$this->assertSame( Campaign::STATUS_RUNNING, $control['status'] );
		$this->assertTrue( $control['include_legacy'] );
		$this->assertSame( 5, $control['started_by'] );
		$this->assertSame( $started['run_id'], Campaign::progress()['run_id'] );
		$this->assertSame( 0, Campaign::progress()['cursor'] );
		$this->assertSame( [ 3, 4 ], Campaign::legacy_ids( $started['run_id'] ) );
		$this->assertSame( [], Campaign::legacy_ids( str_repeat( 'f', 32 ) ), '別の run には推定を渡さない' );
	}

	/**
	 * 推定を含めない run は推定を控えない（送る判定に使わない）。
	 */
	public function test_keeps_no_legacy_accounts_when_not_included(): void {
		$started = Campaign::start( 5, false, [ 3, 4 ] );

		$this->assertFalse( Campaign::control()['include_legacy'] );
		$this->assertSame( [], Campaign::legacy_ids( $started['run_id'] ) );
	}

	public function test_refuses_to_start_while_a_run_is_running(): void {
		$first  = Campaign::start( 5, false, [] );
		$before = StateOption::read( Campaign::CONTROL_OPTION );

		$this->assertNull( Campaign::start( 6, false, [] ) );

		$this->assertSame( $before, StateOption::read( Campaign::CONTROL_OPTION ) );
		$this->assertSame( $first['run_id'], Campaign::progress()['run_id'], '進捗も作り直さない' );
	}

	public function test_starts_again_after_a_finished_run(): void {
		$first = Campaign::start( 5, false, [] );
		$this->assertTrue( Campaign::complete( $first['run_id'], $first['token'] ) );

		$second = Campaign::start( 5, false, [] );

		$this->assertNotNull( $second );
		$this->assertNotSame( $first['run_id'], $second['run_id'] );
		$this->assertSame( $second['run_id'], Campaign::progress()['run_id'] );
	}

	public function test_cancels_only_the_named_run(): void {
		$run = Campaign::start( 5, false, [] );

		$this->assertFalse( Campaign::cancel( str_repeat( 'a', 32 ) ) );
		$this->assertFalse( Campaign::cancel( '' ) );
		$this->assertSame( Campaign::STATUS_RUNNING, Campaign::control()['status'] );

		$this->assertTrue( Campaign::cancel( $run['run_id'] ) );
		$this->assertSame( Campaign::STATUS_CANCELLED, Campaign::control()['status'] );
		$this->assertFalse( Campaign::cancel( $run['run_id'] ), '取り消した run はもう取り消せない' );
	}

	/**
	 * 再開と引継ぎはトークンを替える。古いトークンでは進めず、同じ再開の要求が 2 回届いても 2 回目は失敗する。
	 */
	public function test_resume_and_handoff_replace_the_token(): void {
		$run = Campaign::start( 5, false, [] );
		$this->assertTrue( Campaign::stop( $run['run_id'], $run['token'], Campaign::STOP_MAIL_FAILING ) );
		$this->assertSame( Campaign::STOP_MAIL_FAILING, Campaign::control()['reason'] );

		$resumed = Campaign::resume( $run['run_id'], $run['token'] );

		$this->assertNotNull( $resumed );
		$this->assertNotSame( $run['token'], $resumed );
		$this->assertSame( Campaign::STATUS_RUNNING, Campaign::control()['status'] );
		$this->assertSame( '', Campaign::control()['reason'] );
		$this->assertNull( Campaign::resume( $run['run_id'], $run['token'] ), '同じ要求の 2 回目' );
		$this->assertNull( Campaign::handoff( $run['run_id'], $run['token'] ), '古いチェーン' );

		$next = Campaign::handoff( $run['run_id'], $resumed );

		$this->assertNotNull( $next );
		$this->assertFalse( Campaign::complete( $run['run_id'], $resumed ) );
		$this->assertTrue( Campaign::complete( $run['run_id'], $next ) );
	}

	public function test_a_cancelled_run_cannot_be_handed_off_or_completed(): void {
		$run = Campaign::start( 5, false, [] );
		Campaign::cancel( $run['run_id'] );

		$this->assertNull( Campaign::handoff( $run['run_id'], $run['token'] ) );
		$this->assertFalse( Campaign::complete( $run['run_id'], $run['token'] ) );
		$this->assertFalse( Campaign::stop( $run['run_id'], $run['token'], Campaign::STOP_STATE ) );
		$this->assertSame( Campaign::STATUS_CANCELLED, Campaign::control()['status'] );
	}

	/**
	 * 進捗は読んだ値と一致するときだけ書く（新しい run が進捗を作り直した後、前の run のバッチが書き戻さない）。
	 */
	public function test_progress_is_written_only_over_the_value_read(): void {
		$first = Campaign::start( 5, false, [] );
		$state = Campaign::progress_for_update();
		Campaign::cancel( $first['run_id'] );
		$second = Campaign::start( 5, false, [] );

		$progress         = $state['progress'];
		$progress['sent'] = 9;

		$this->assertFalse( Campaign::save_progress( $state['raw'], $progress ) );
		$this->assertSame( $second['run_id'], Campaign::progress()['run_id'] );
		$this->assertSame( 0, Campaign::progress()['sent'] );

		$state            = Campaign::progress_for_update();
		$progress         = $state['progress'];
		$progress['sent'] = 2;

		$this->assertTrue( Campaign::save_progress( $state['raw'], $progress ) );
		$this->assertSame( 2, Campaign::progress()['sent'] );
	}

	/**
	 * 壊れた値: 知らない状態は取消として読み（送り続けない）、推定を含めるのは実 bool の true のときだけ。
	 */
	public function test_reads_broken_values_on_the_safe_side(): void {
		update_option(
			Campaign::CONTROL_OPTION,
			(string) wp_json_encode(
				[
					'run_id'         => str_repeat( 'a', 32 ),
					'status'         => 'unknown',
					'token'          => 'not-a-token',
					'include_legacy' => 'true',
				]
			)
		);

		$control = Campaign::control();

		$this->assertSame( Campaign::STATUS_CANCELLED, $control['status'] );
		$this->assertSame( '', $control['token'] );
		$this->assertFalse( $control['include_legacy'] );
		$this->assertNull( Campaign::handoff( str_repeat( 'a', 32 ), '' ) );
	}

	/**
	 * run の ID・トークンの無い進行中・停止中は取消として読む（どの操作も run を名指しできず、開始もできずに行き詰まらない。R1-L3）。
	 *
	 * @dataProvider stuck_controls
	 *
	 * @param array<string,string> $control
	 */
	public function test_reads_a_running_run_without_ids_as_cancelled( array $control ): void {
		update_option( Campaign::CONTROL_OPTION, (string) wp_json_encode( $control ) );

		$this->assertSame( Campaign::STATUS_CANCELLED, Campaign::control()['status'] );
		$this->assertNotNull( Campaign::start( 5, false, [] ), '新しい run を始められる' );
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function stuck_controls(): array {
		return [
			'running without a run ID' => [
				[
					'status' => 'running',
					'token'  => str_repeat( 'a', 32 ),
				],
			],
			'stopped without a token'  => [
				[
					'status' => 'stopped',
					'run_id' => str_repeat( 'a', 32 ),
				],
			],
		];
	}

	/**
	 * 再開は連続の失敗の数を戻す（メールを送れずに止まった run を再開して、最初の 1 通の失敗でまた止めない。R1-L6）。
	 */
	public function test_resume_resets_the_consecutive_failures(): void {
		$run                              = Campaign::start( 5, false, [] );
		$state                            = Campaign::progress_for_update();
		$progress                         = $state['progress'];
		$progress['consecutive_failures'] = 3;
		$this->assertTrue( Campaign::save_progress( $state['raw'], $progress ) );
		Campaign::stop( $run['run_id'], $run['token'], Campaign::STOP_MAIL_FAILING );

		$this->assertNotNull( Campaign::resume( $run['run_id'], $run['token'] ) );

		$this->assertSame( 0, Campaign::progress()['consecutive_failures'] );
	}

	public function test_state_options_are_not_autoloaded(): void {
		global $wpdb;

		Campaign::start( 5, false, [] );

		foreach ( [ Campaign::CONTROL_OPTION, Campaign::PROGRESS_OPTION, Campaign::LEGACY_OPTION ] as $name ) {
			$this->assertSame( 'off', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ), $name );
		}
	}
}
