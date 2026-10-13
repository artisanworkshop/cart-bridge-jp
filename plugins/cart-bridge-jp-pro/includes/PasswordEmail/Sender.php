<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

use Throwable;
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

	/** 送る前の記録を書けなかった（送らない。最大 1 通を守れないため。PR #135 G1-6）。 */
	public const FAILURE_RECORD = 'record';

	/** このリクエストが持っている排他のハンドル（借りを延ばすたびに替わる。shutdown で解放する）。 */
	private static ?string $held = null;

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
	 * この run のこのトークンの生きた予約（pending・in-progress）があるか（取り消した run の残りの予約を、今の run が生きている証拠にしない。PR #135 G2-3）。
	 * 確かめられなければ null（Action Scheduler の初期化前・照会の例外・照会の DB エラー〔照会は失敗しても空を返すので `$wpdb->last_error` で見る〕・
	 * 読めない予約。無料版の `JobRepository::live_action_job_ids()` と同じ考え方）。
	 */
	public static function has_live_action( string $run_id, string $token ): ?bool {
		global $wpdb;

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler::class ) || ! \ActionScheduler::is_initialized() ) {
			return null;
		}

		try {
			$actions = as_get_scheduled_actions(
				[
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					// `ActionScheduler_Store::STATUS_PENDING`・`STATUS_RUNNING`（静的解析が Action Scheduler のクラスを知らないので文字列で書く）。
					'status'   => [ 'pending', 'in-progress' ],
					'per_page' => -1,
					'orderby'  => 'none',
				]
			);
		} catch ( Throwable ) {
			return null;
		}

		if ( '' !== $wpdb->last_error || ! is_array( $actions ) ) {
			return null;
		}

		foreach ( $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || ! method_exists( $action, 'get_hook' ) || self::HOOK !== $action->get_hook() ) {
				return null;
			}

			$args = $action->get_args();

			if ( is_array( $args ) && ( $args[0] ?? null ) === $run_id && ( $args[1] ?? null ) === $token ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 進行中のはずの run が中断したか: running なのに生きた予約が無く、最後のバッチ（無ければ開始）から排他の期限より長く経った。
	 * 確かめられないときは中断と言わない（再開を勧めない）。
	 *
	 * @param array{run_id:string,status:string,token:string,started_at:int,updated_at:int} $control
	 * @param array{last_batch_at:int}                                                     $progress
	 */
	public static function is_interrupted( array $control, array $progress ): bool {
		if ( Campaign::STATUS_RUNNING !== $control['status'] ) {
			return false;
		}

		$last = max( $progress['last_batch_at'], $control['updated_at'], $control['started_at'] );

		if ( time() - $last <= SendLock::TTL ) {
			return false;
		}

		return false === self::has_live_action( $control['run_id'], $control['token'] );
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

		self::$held = $lock;
		register_shutdown_function( [ self::class, 'release_held' ] );

		try {
			self::run_batch( $run_id, $token, $lock );
		} finally {
			self::release_held();
		}
	}

	/**
	 * このリクエストが持っている排他を解放する（終わったとき・致命的エラーの shutdown）。
	 */
	public static function release_held(): void {
		if ( null !== self::$held ) {
			SendLock::release( self::$held );
			self::$held = null;
		}
	}

	/**
	 * 取り消した run の予約だけを外す（取消の後にほぼ同時に始まった別の run の予約は残す。PR #135 G1-1）。
	 */
	public static function unschedule_run( string $run_id ): void {
		if ( '' === $run_id || ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
			return;
		}

		$actions = as_get_scheduled_actions(
			[
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => 'pending', // `ActionScheduler_Store::STATUS_PENDING`（静的解析が Action Scheduler のクラスを知らないので文字列で書く）。
				'per_page' => 100,
			]
		);

		foreach ( is_array( $actions ) ? $actions : [] as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
				continue;
			}

			$args = $action->get_args();

			if ( is_array( $args ) && ( $args[0] ?? null ) === $run_id ) {
				as_unschedule_action( self::HOOK, $args, self::GROUP );
			}
		}
	}

	/**
	 * 借りを延ばし、このリクエストが持つハンドルも替える。延ばせなければ null（他のバッチが取った）。
	 */
	private static function renew( string $lock ): ?string {
		$renewed = SendLock::renew( $lock );

		if ( null !== $renewed && self::$held === $lock ) {
			self::$held = $renewed;
		}

		return $renewed;
	}

	/**
	 * 1 バッチ（{@see self::handle()} が排他を取ってから呼ぶ）。テストが持っていない排他で呼ぶために public にしている。
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

		$state = Campaign::progress_for_update( $run_id );

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

		// 候補を読めなければ止める（空のページを「見尽くした」と読んで完了にし、残りの顧客を黙って飛ばさない。G3-1）。
		if ( null === $ids ) {
			Campaign::stop( $run_id, $token, Campaign::STOP_SCAN_FAILED );
			return;
		}

		foreach ( $ids as $user_id ) {
			if ( $sent_now >= self::batch_size() || time() - $started >= self::TIME_BUDGET_SECONDS ) {
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

			// 送る前に借りを延ばす。延ばせなければ他のバッチが動いているので、何も書かずに止まる（G1-4）。
			$renewed = self::renew( $lock );

			if ( null === $renewed ) {
				return;
			}

			$lock    = $renewed;
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

			if ( in_array( $outcome, [ self::FAILURE_MAIL, self::FAILURE_RECORD ], true ) ) {
				++$progress['consecutive_failures'];

				if ( $progress['consecutive_failures'] >= self::MAX_CONSECUTIVE_FAILURES ) {
					$stopped = self::FAILURE_RECORD === $outcome ? Campaign::STOP_RECORD_FAILING : Campaign::STOP_MAIL_FAILING;
					break;
				}
			}
		}

		// 状態を書く前にも借りを延ばす（最後の送信が長引いて他のバッチが取っていれば、進捗も引継ぎも書かない。G1-4）。
		if ( null === self::renew( $lock ) ) {
			return;
		}

		$progress['last_batch_at'] = time();

		if ( ! Campaign::save_progress( $run_id, $state['raw'], $progress ) || 'superseded' === $stopped ) {
			return;
		}

		if ( null !== $stopped ) {
			Campaign::stop( $run_id, $token, $stopped );
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
		// 送る前に記録する（この後で処理が落ちても、次のバッチ・次の run がこの顧客へ 2 通目を送らない）。記録を確かめられなければ送らない（G1-6）。
		$sending = SendRecord::mark_sending( $user->ID, $run_id );

		if ( null === $sending ) {
			return self::FAILURE_RECORD;
		}

		$key = get_password_reset_key( $user );

		if ( ! is_string( $key ) || '' === $key ) {
			SendRecord::mark_failed( $user->ID, $sending, $run_id, self::FAILURE_RESET_KEY );

			return self::FAILURE_RESET_KEY;
		}

		// 送れた・送れなかったの記録は、記録がまだ自分の書いた値のときだけ書き換える（G2-2）。
		if ( ! $email->send_to( $user, AccountLinks::set_password_url( $user, $key ) ) ) {
			SendRecord::mark_failed( $user->ID, $sending, $run_id, self::FAILURE_MAIL );

			return self::FAILURE_MAIL;
		}

		SendRecord::mark_sent( $user->ID, $sending, $run_id );

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
