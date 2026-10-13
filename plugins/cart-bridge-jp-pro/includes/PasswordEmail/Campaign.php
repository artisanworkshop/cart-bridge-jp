<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

/**
 * パスワード設定メールの一斉送信（run）の状態（R3-10）。
 *
 * 状態は 2 つの option に分ける（どちらも autoload なし）:
 * - **制御**（{@see self::CONTROL_OPTION}）: run の ID・状態・停止の理由・チェーンのトークン・0.1.0 の推定を含めるか。開始・取消・再開・次のバッチへの引継ぎ・完了・停止は、
 *   直前に DB から読んだ値と一致するときだけ書く（CAS。{@see StateOption}）。取消がバッチの書き戻しで消えない。
 * - **進捗**（{@see self::PROGRESS_OPTION}）: カーソル（見終えた最大のユーザー ID）と件数。バッチだけが CAS で書き、開始で作り直す。
 *
 * Action Scheduler のアクションはトークンを持ち（`Sender::HOOK` の引数 `[ run_id, token ]`）、制御のトークンと一致するバッチだけが進む。次のバッチを予約するときと
 * 再開するときにトークンを替えるので、古いチェーン・2 回目の再開の要求は進めない。
 */
final class Campaign {

	public const CONTROL_OPTION  = 'cbjp_pro_password_email_control';
	public const PROGRESS_OPTION = 'cbjp_pro_password_email_progress';

	/** 0.1.0 で作ったと推定したアカウント（開始のときに推定して控える。run の間は変えない）。 */
	public const LEGACY_OPTION = 'cbjp_pro_password_email_legacy';

	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_STOPPED   = 'stopped';

	/** 続けて送れなかった（SMTP の上限・送信の設定の誤りなど）。 */
	public const STOP_MAIL_FAILING = 'mail_failing';

	/** メールが無効・登録されていない（WooCommerce > 設定 > メール）。 */
	public const STOP_EMAIL_UNAVAILABLE = 'email_unavailable';

	/** マイアカウントのページ・パスワード忘れのエンドポイントが使えない（設定のリンクが壊れる）。 */
	public const STOP_ACCOUNT_PAGE = 'account_page';

	/** 次のバッチを予約できなかった。 */
	public const STOP_SCHEDULE_FAILED = 'schedule_failed';

	/** 進捗の行が run と食い違う（後から書き換えられた）。 */
	public const STOP_STATE = 'state';

	private function __construct() {}

	/**
	 * 今の制御（DB から読む）。無い・壊れていれば null。
	 *
	 * @return array{run_id:string,status:string,reason:string,token:string,include_legacy:bool,started_at:int,started_by:int,updated_at:int,rev:int}|null
	 */
	public static function control(): ?array {
		$read = StateOption::read( self::CONTROL_OPTION );

		return null === $read ? null : self::normalize_control( $read['value'] );
	}

	/**
	 * 今の進捗（DB から読む）。無い・壊れていれば空の進捗。
	 *
	 * @return array{run_id:string,cursor:int,sent:int,failed:int,unknown:int,legacy:int,consecutive_failures:int,last_batch_at:int,rev:int}
	 */
	public static function progress(): array {
		$read = StateOption::read( self::PROGRESS_OPTION );

		return self::normalize_progress( null === $read ? [] : $read['value'] );
	}

	/**
	 * 新しい run を始める（進行中の run が無いときだけ）。
	 *
	 * @param array<int,int> $legacy_ids 0.1.0 で作ったと推定したアカウント（`$include_legacy` のときだけ使う）。
	 * @return array{run_id:string,token:string}|null 始めた run。進行中の run があれば・競合に負ければ null。
	 */
	public static function start( int $user_id, bool $include_legacy, array $legacy_ids ): ?array {
		$current = StateOption::read( self::CONTROL_OPTION );

		if ( null !== $current && self::STATUS_RUNNING === self::normalize_control( $current['value'] )['status'] ) {
			return null;
		}

		$run_id  = self::random_id();
		$token   = self::random_id();
		$now     = time();
		$control = [
			'run_id'         => $run_id,
			'status'         => self::STATUS_RUNNING,
			'reason'         => '',
			'token'          => $token,
			'include_legacy' => $include_legacy,
			'started_at'     => $now,
			'started_by'     => $user_id,
			'updated_at'     => $now,
			'rev'            => null === $current ? 1 : self::normalize_control( $current['value'] )['rev'] + 1,
		];

		// 制御の CAS に勝ってから進捗と推定を作り直す（同時の開始で負けた側が、勝った run の進捗を書き換えない）。バッチは呼び出し側がこの後に予約するので、
		// 最初のバッチは新しい進捗を読む。前の run のバッチが残っていても、制御の run が替わったので進捗を書かずに止まる（進捗の書込みも CAS）。
		if ( ! StateOption::swap( self::CONTROL_OPTION, $current, $control ) ) {
			return null;
		}

		StateOption::delete( self::PROGRESS_OPTION );
		StateOption::insert( self::PROGRESS_OPTION, self::empty_progress( $run_id ) );
		StateOption::delete( self::LEGACY_OPTION );
		StateOption::insert(
			self::LEGACY_OPTION,
			[
				'run_id' => $run_id,
				'ids'    => $include_legacy ? array_values( array_map( 'intval', $legacy_ids ) ) : [],
			]
		);

		return [
			'run_id' => $run_id,
			'token'  => $token,
		];
	}

	/**
	 * 取り消す（進行中・停止中の run だけ。run の ID が今の run と一致するときだけ）。
	 */
	public static function cancel( string $run_id ): bool {
		return self::transition( $run_id, null, [ self::STATUS_RUNNING, self::STATUS_STOPPED ], self::STATUS_CANCELLED, '', null );
	}

	/**
	 * 再開する（停止中・中断した run）。トークンを替えるので、古いチェーンは進めず、同じ要求が 2 回届いても 2 回目は失敗する。
	 *
	 * @param string $expected_token 画面が見たトークン（その後に引き継がれた・再開されたなら失敗する）。
	 * @return string|null 新しいトークン。
	 */
	public static function resume( string $run_id, string $expected_token ): ?string {
		$token = self::random_id();

		return self::transition( $run_id, $expected_token, [ self::STATUS_RUNNING, self::STATUS_STOPPED ], self::STATUS_RUNNING, '', $token ) ? $token : null;
	}

	/**
	 * 次のバッチへ引き継ぐ（トークンを替える）。
	 *
	 * @return string|null 新しいトークン。取り消された・他のチェーンに替わっていれば null。
	 */
	public static function handoff( string $run_id, string $token ): ?string {
		$next = self::random_id();

		return self::transition( $run_id, $token, [ self::STATUS_RUNNING ], self::STATUS_RUNNING, '', $next ) ? $next : null;
	}

	public static function complete( string $run_id, string $token ): bool {
		return self::transition( $run_id, $token, [ self::STATUS_RUNNING ], self::STATUS_COMPLETED, '', null );
	}

	public static function stop( string $run_id, string $token, string $reason ): bool {
		return self::transition( $run_id, $token, [ self::STATUS_RUNNING ], self::STATUS_STOPPED, $reason, null );
	}

	/**
	 * 進捗を書く（`$expected` は同じバッチが読んだ生の値。開始で作り直されていれば書かない）。
	 *
	 * @param array{run_id:string,cursor:int,sent:int,failed:int,unknown:int,legacy:int,consecutive_failures:int,last_batch_at:int,rev:int} $progress
	 */
	public static function save_progress( string $expected, array $progress ): bool {
		$progress['rev'] = $progress['rev'] + 1;

		return StateOption::replace( self::PROGRESS_OPTION, $expected, $progress );
	}

	/**
	 * 進捗の生の値（バッチの CAS 用）と読んだ進捗。
	 *
	 * @return array{raw:string,progress:array{run_id:string,cursor:int,sent:int,failed:int,unknown:int,legacy:int,consecutive_failures:int,last_batch_at:int,rev:int}}|null
	 */
	public static function progress_for_update(): ?array {
		$read = StateOption::read( self::PROGRESS_OPTION );

		if ( null === $read ) {
			return null;
		}

		return [
			'raw'      => $read['raw'],
			'progress' => self::normalize_progress( $read['value'] ),
		];
	}

	/**
	 * 開始のときに控えた 0.1.0 の推定（run が違えば空）。
	 *
	 * @return array<int,int>
	 */
	public static function legacy_ids( string $run_id ): array {
		$read  = StateOption::read( self::LEGACY_OPTION );
		$value = null === $read ? [] : $read['value'];

		if ( ( $value['run_id'] ?? null ) !== $run_id || ! is_array( $value['ids'] ?? null ) ) {
			return [];
		}

		return array_values( array_filter( $value['ids'], 'is_int' ) );
	}

	/**
	 * @param array<int,string> $from
	 */
	private static function transition( string $run_id, ?string $token, array $from, string $to, string $reason, ?string $new_token ): bool {
		$current = StateOption::read( self::CONTROL_OPTION );

		if ( null === $current ) {
			return false;
		}

		$control = self::normalize_control( $current['value'] );

		if ( '' === $run_id || ! hash_equals( $control['run_id'], $run_id ) || ! in_array( $control['status'], $from, true ) ) {
			return false;
		}

		if ( null !== $token && ! hash_equals( $control['token'], $token ) ) {
			return false;
		}

		$control['status']     = $to;
		$control['reason']     = $reason;
		$control['updated_at'] = time();
		$control['rev']        = $control['rev'] + 1;

		if ( null !== $new_token ) {
			$control['token'] = $new_token;
		}

		return StateOption::replace( self::CONTROL_OPTION, $current['raw'], $control );
	}

	/**
	 * @param array<string,mixed> $value
	 * @return array{run_id:string,status:string,reason:string,token:string,include_legacy:bool,started_at:int,started_by:int,updated_at:int,rev:int}
	 */
	private static function normalize_control( array $value ): array {
		$status = $value['status'] ?? null;

		return [
			'run_id'         => self::id_or_empty( $value['run_id'] ?? null ),
			// 知らない状態は取消として読む（壊れた値で送り続けない）。
			'status'         => in_array( $status, [ self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_STOPPED ], true ) ? $status : self::STATUS_CANCELLED,
			'reason'         => is_string( $value['reason'] ?? null ) ? $value['reason'] : '',
			'token'          => self::id_or_empty( $value['token'] ?? null ),
			// 推定を含めるのは実 bool の true のときだけ（`(bool) 'false'` は true。CLAUDE.md）。
			'include_legacy' => true === ( $value['include_legacy'] ?? null ),
			'started_at'     => self::int_or_zero( $value['started_at'] ?? null ),
			'started_by'     => self::int_or_zero( $value['started_by'] ?? null ),
			'updated_at'     => self::int_or_zero( $value['updated_at'] ?? null ),
			'rev'            => self::int_or_zero( $value['rev'] ?? null ),
		];
	}

	/**
	 * @param array<string,mixed> $value
	 * @return array{run_id:string,cursor:int,sent:int,failed:int,unknown:int,legacy:int,consecutive_failures:int,last_batch_at:int,rev:int}
	 */
	private static function normalize_progress( array $value ): array {
		return [
			'run_id'               => self::id_or_empty( $value['run_id'] ?? null ),
			'cursor'               => self::int_or_zero( $value['cursor'] ?? null ),
			'sent'                 => self::int_or_zero( $value['sent'] ?? null ),
			'failed'               => self::int_or_zero( $value['failed'] ?? null ),
			'unknown'              => self::int_or_zero( $value['unknown'] ?? null ),
			'legacy'               => self::int_or_zero( $value['legacy'] ?? null ),
			'consecutive_failures' => self::int_or_zero( $value['consecutive_failures'] ?? null ),
			'last_batch_at'        => self::int_or_zero( $value['last_batch_at'] ?? null ),
			'rev'                  => self::int_or_zero( $value['rev'] ?? null ),
		];
	}

	/**
	 * @return array{run_id:string,cursor:int,sent:int,failed:int,unknown:int,legacy:int,consecutive_failures:int,last_batch_at:int,rev:int}
	 */
	private static function empty_progress( string $run_id ): array {
		return self::normalize_progress( [ 'run_id' => $run_id ] );
	}

	private static function random_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private static function id_or_empty( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{32}\z/', $value ) ? $value : '';
	}

	private static function int_or_zero( mixed $value ): int {
		return is_int( $value ) && $value >= 0 ? $value : 0;
	}
}
