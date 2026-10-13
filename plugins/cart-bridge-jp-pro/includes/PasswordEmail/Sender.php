<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

use WP_User;

/**
 * パスワード設定メールを Action Scheduler で少しずつ送る（R3-10。`docs/03` §10.5 の 4）。
 *
 * 1 回のアクションが 1 バッチ: {@see SendLock} の中で、ユーザー ID の順にカーソルの次から候補を見て、最大 {@see self::BATCH_SIZE} 通・{@see self::TIME_BUDGET_SECONDS} 秒・
 * 候補 {@see self::SCAN_LIMIT} 件のどれかで区切り、進捗を書いて、間隔をあけて次のバッチを予約する（レンタルサーバーの送信数の上限に当たりにくくする）。
 * 宛先ごとに、制御を DB から読み直し（取消・別の run なら止まる）、ユーザーのキャッシュを捨てて判定をやり直してから、**送る前に**送信の記録を書く（`SendRecord`）。
 * 続けて {@see self::MAX_CONSECUTIVE_FAILURES} 通送れなければ止める（SMTP の上限など。残りを全員「失敗」にしない）。
 */
final class Sender {

	public const HOOK  = 'cbjp_pro_send_password_emails';
	public const GROUP = 'cart-bridge-jp-pro';

	public const BATCH_SIZE               = 20;
	public const INTERVAL_SECONDS         = 60;
	public const TIME_BUDGET_SECONDS      = 25;
	public const SCAN_LIMIT               = 200;
	public const MAX_CONSECUTIVE_FAILURES = 3;

	/** 失敗の理由（`SendRecord::mark_failed()`）。 */
	public const FAILURE_RESET_KEY = 'reset_key';
	public const FAILURE_MAIL      = 'mail';

	private function __construct() {}

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'handle' ], 10, 2 );
	}

	/**
	 * バッチを予約する。
	 *
	 * @return bool 予約できたか。
	 */
	public static function schedule( string $run_id, string $token, int $delay ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$action_id = as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, [ $run_id, $token ], self::GROUP );

		return is_int( $action_id ) && $action_id > 0;
	}

	/**
	 * 生きた予約（pending・in-progress）があるか。確かめられなければ null（Action Scheduler が読み込まれていない）。
	 */
	public static function has_live_action(): ?bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! class_exists( \ActionScheduler::class ) || ! \ActionScheduler::is_initialized() ) {
			return null;
		}

		return as_has_scheduled_action( self::HOOK, null, self::GROUP );
	}

	/**
	 * 進行中のはずの run が中断したか: running なのに生きた予約が無く、最後のバッチ（無ければ開始）から排他の期限より長く経った。
	 * 確かめられないときは中断と言わない（再開を勧めない）。
	 *
	 * @param array{status:string,started_at:int,updated_at:int} $control
	 * @param array{last_batch_at:int}                           $progress
	 */
	public static function is_interrupted( array $control, array $progress ): bool {
		if ( Campaign::STATUS_RUNNING !== $control['status'] ) {
			return false;
		}

		$last = max( $progress['last_batch_at'], $control['updated_at'], $control['started_at'] );

		if ( time() - $last <= SendLock::TTL ) {
			return false;
		}

		return false === self::has_live_action();
	}

	/**
	 * Action Scheduler のアクション。
	 *
	 * @param mixed $run_id
	 * @param mixed $token
	 */
	public static function handle( mixed $run_id, mixed $token ): void {
		if ( ! is_string( $run_id ) || ! is_string( $token ) ) {
			return;
		}

		$lock = SendLock::acquire();

		if ( null === $lock ) {
			// 他のバッチが動いている。このアクションがまだ今のチェーンなら、後でもう一度来る（チェーンを切らない）。
			if ( self::is_current( $run_id, $token ) ) {
				self::schedule( $run_id, $token, self::interval() );
			}

			return;
		}

		register_shutdown_function( [ SendLock::class, 'release' ], $lock );

		try {
			self::run_batch( $run_id, $token, $lock );
		} finally {
			SendLock::release( $lock );
		}
	}

	/**
	 * 1 バッチ（{@see self::handle()} が排他を取ってから呼ぶ）。テストが期限の近い排他で呼ぶために public にしている。
	 *
	 * @internal
	 */
	public static function run_batch( string $run_id, string $token, string $lock ): void {
		$control = Campaign::control();

		if ( null === $control || ! self::matches( $control, $run_id, $token ) ) {
			return;
		}

		$email   = EmailRegistry::email();
		$problem = EmailRegistry::problem( $email );

		if ( null !== $problem || null === $email ) {
			Campaign::stop( $run_id, $token, $problem ?? Campaign::STOP_EMAIL_UNAVAILABLE );
			return;
		}

		$state = Campaign::progress_for_update();

		if ( null === $state || $state['progress']['run_id'] !== $run_id ) {
			Campaign::stop( $run_id, $token, Campaign::STOP_STATE );
			return;
		}

		$progress = $state['progress'];
		$policy   = new RecipientPolicy( $control['include_legacy'] ? Campaign::legacy_ids( $run_id ) : [], $control['include_legacy'] );
		$started  = time();
		$sent_now = 0;
		$ids      = RecipientScan::ids_after( $progress['cursor'], self::SCAN_LIMIT );
		$examined = 0;
		$stopped  = null;

		foreach ( $ids as $user_id ) {
			if ( $sent_now >= self::batch_size() || time() - $started >= self::TIME_BUDGET_SECONDS || ! SendLock::still_held( $lock ) ) {
				break;
			}

			// 取り消された・別のチェーンに替わったら、この宛先から先は送らない。
			$current = Campaign::control();

			if ( null === $current || ! self::matches( $current, $run_id, $token ) ) {
				$stopped = 'superseded';
				break;
			}

			++$examined;
			$progress['cursor'] = $user_id;

			clean_user_cache( $user_id );
			wp_cache_delete( $user_id, 'user_meta' );
			$verdict = $policy->evaluate( $user_id );

			if ( ! $verdict->sends() ) {
				continue;
			}

			$user = get_userdata( $user_id );

			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$outcome = self::send_one( $email, $user, $run_id );
			++$sent_now;

			if ( null === $outcome ) {
				++$progress['sent'];
				$progress['consecutive_failures'] = 0;
				$progress['unknown']             += $verdict->password_unknown ? 1 : 0;
				$progress['legacy']              += $verdict->legacy ? 1 : 0;
				continue;
			}

			++$progress['failed'];

			if ( self::FAILURE_MAIL === $outcome ) {
				++$progress['consecutive_failures'];

				if ( $progress['consecutive_failures'] >= self::MAX_CONSECUTIVE_FAILURES ) {
					$stopped = Campaign::STOP_MAIL_FAILING;
					break;
				}
			}
		}

		$progress['last_batch_at'] = time();

		if ( ! Campaign::save_progress( $state['raw'], $progress ) || 'superseded' === $stopped ) {
			return;
		}

		if ( Campaign::STOP_MAIL_FAILING === $stopped ) {
			Campaign::stop( $run_id, $token, Campaign::STOP_MAIL_FAILING );
			return;
		}

		// 候補を見尽くした（区切りの前にすべて見て、取った件数が上限に届かなかった）なら終わり。
		$fetched = count( $ids );

		if ( $fetched === $examined && $fetched < self::SCAN_LIMIT ) {
			Campaign::complete( $run_id, $token );
			return;
		}

		$next = Campaign::handoff( $run_id, $token );

		if ( null !== $next && ! self::schedule( $run_id, $next, self::interval() ) ) {
			Campaign::stop( $run_id, $next, Campaign::STOP_SCHEDULE_FAILED );
		}
	}

	/**
	 * 1 人へ送る。送れたら null、送れなければ理由（`SendRecord::mark_failed()` に書いた理由）。
	 */
	private static function send_one( PasswordSetupEmail $email, WP_User $user, string $run_id ): ?string {
		// 送る前に記録する（この後で処理が落ちても、次のバッチ・次の run がこの顧客へ 2 通目を送らない）。
		SendRecord::mark_sending( $user->ID, $run_id );

		$key = get_password_reset_key( $user );

		if ( ! is_string( $key ) || '' === $key ) {
			SendRecord::mark_failed( $user->ID, $run_id, self::FAILURE_RESET_KEY );

			return self::FAILURE_RESET_KEY;
		}

		if ( ! $email->send_to( $user, AccountLinks::set_password_url( $user, $key ) ) ) {
			SendRecord::mark_failed( $user->ID, $run_id, self::FAILURE_MAIL );

			return self::FAILURE_MAIL;
		}

		SendRecord::mark_sent( $user->ID, $run_id );

		return null;
	}

	private static function is_current( string $run_id, string $token ): bool {
		$control = Campaign::control();

		return null !== $control && self::matches( $control, $run_id, $token );
	}

	/**
	 * @param array{run_id:string,status:string,token:string} $control
	 */
	private static function matches( array $control, string $run_id, string $token ): bool {
		return Campaign::STATUS_RUNNING === $control['status']
			&& '' !== $control['run_id'] && hash_equals( $control['run_id'], $run_id )
			&& '' !== $control['token'] && hash_equals( $control['token'], $token );
	}

	/**
	 * 1 バッチで送る上限（`cbjp/pro/password_email/batch_size`。1〜100 に収める）。
	 */
	public static function batch_size(): int {
		$size = apply_filters( 'cbjp/pro/password_email/batch_size', self::BATCH_SIZE );

		return is_int( $size ) ? max( 1, min( 100, $size ) ) : self::BATCH_SIZE;
	}

	/**
	 * バッチの間隔（秒。`cbjp/pro/password_email/interval`。10〜3600 に収める）。
	 */
	public static function interval(): int {
		$seconds = apply_filters( 'cbjp/pro/password_email/interval', self::INTERVAL_SECONDS );

		return is_int( $seconds ) ? max( 10, min( 3600, $seconds ) ) : self::INTERVAL_SECONDS;
	}
}
