<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Support\Logger;
use Throwable;

/**
 * Action Scheduler のアクションを失って進まなくなった run を、理由のコード付きの失敗にする（R3-9・issue #121）。
 *
 * プラグインを停止している間も Action Scheduler は動き、期限の来た `cbjp_process_job` をコールバックの無いまま失敗にする。PHP の致命的エラー・
 * プロセスの強制終了でも、ページを処理していたアクションが失敗扱いになる。どちらもジョブは `running`（`paused`・Retry した `pending`）のまま残り、
 * 同時実行の判定（`JobRepository::is_platform_busy()`）がそのプラットフォームの新しい run・ツールを止め続ける。失敗にすれば、通常の例外で
 * 失敗したときと同じ形（兄弟のジョブは pending のまま）になり、既存の Retry で保存済みの cursor から続けるか、キャンセルできる。
 * 自動では再開しない（致命的エラーが同じページで繰り返すとループになる）。
 */
final class OrphanedRunRecovery {

	/**
	 * 最後の活動（`updated_at`）からこの時間を過ぎた run だけを孤立とみなす。処理中のページはハートビート（`JobHeartbeat`）を打つので、
	 * その間隔の最大（HTTP 1 本〔画像の取得は既定 300 秒〕か 1 件の書込み）の約 3 倍にする。生きたアクションの無い区間（run の開始・Retry の
	 * 遷移からエンキューまで）は `updated_at` が今になるので、ロックの外から呼んでもその区間を孤立と誤認しない。
	 */
	public const GRACE_SECONDS = 900;

	/**
	 * 生きたアクションが無い（停止中に失敗にされた・強制終了された等）ことで失敗にしたジョブの `error_json` の code。
	 */
	public const ERROR_ACTION_LOST = 'action_lost';

	/**
	 * Action Scheduler が PHP の致命的エラーを捕まえたことで失敗にしたジョブの `error_json` の code。
	 */
	public const ERROR_FATAL = 'fatal_error';

	/**
	 * `fatal_error` の message に残す PHP のエラーの長さの上限（文字数）。
	 */
	private const FATAL_MESSAGE_MAX_LENGTH = 300;

	public function __construct(
		private readonly JobRepository $jobs = new JobRepository(),
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * プラットフォームの孤立した run を失敗にする。孤立 = 次をすべて満たす進行中の run:
	 * - 失敗したジョブを持たない（持つ run は既に「止まった。Retry かキャンセル」の状態）。
	 * - run の**どのジョブにも**生きたアクション（pending・in-progress）が無い（`complete_job_and_advance()` は、前のジョブのアクションが
	 *   in-progress のまま次のジョブを始めるので、ジョブ単位では見ない）。Action Scheduler を確かめられなければ何もしない。
	 * - ジョブの最新の `updated_at` が `GRACE_SECONDS` より古い。
	 * 失敗にするのは run の最初の未終了のジョブで、書換えは `mark_failed_if_idle()`（判定の後に動いたジョブには負ける）。
	 *
	 * @return int 失敗にしたジョブの数。
	 */
	public function recover( string $platform ): int {
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - self::GRACE_SECONDS );
		$candidates = array_filter(
			$this->jobs->find_active_runs_for_platform( $platform ),
			// MySQL の DATETIME 文字列（`Y-m-d H:i:s`）は辞書順＝時刻順。
			static fn ( array $run ): bool => ! $run['has_failed_job'] && $run['updated_at'] <= $cutoff
		);

		// 画面が数秒ごとに一覧を照会するので、古い run が無ければ Action Scheduler を照会しない。
		if ( [] === $candidates ) {
			return 0;
		}

		$live_job_ids = $this->jobs->live_action_job_ids();

		if ( null === $live_job_ids ) {
			return 0;
		}

		$recovered = 0;

		foreach ( $candidates as $run ) {
			$job_ids = array_map( 'intval', array_column( $this->jobs->find_by_run( $run['run_id'] ), 'id' ) );

			if ( [] !== array_intersect( $job_ids, $live_job_ids ) ) {
				continue;
			}

			$job = $this->jobs->find_next_incomplete_for_run( $run['run_id'] );

			if ( null === $job ) {
				continue;
			}

			$error = [
				'code'    => self::ERROR_ACTION_LOST,
				'message' => 'No background action is left to process this job (for example, the plugin was deactivated during the run, or PHP stopped while processing it). Retry to continue from where it stopped.',
			];

			if ( $this->jobs->mark_failed_if_idle( (int) $job['id'], $error, $cutoff ) ) {
				++$recovered;
				$this->logger->warning(
					'Job marked as failed: no background action is left to process it.',
					[
						'platform' => $platform,
						'entity'   => $job['entity'],
						'job_id'   => (int) $job['id'],
					]
				);
			}
		}

		return $recovered;
	}

	/**
	 * `action_scheduler_unexpected_shutdown` のコールバック。Action Scheduler が PHP の致命的エラー（メモリ・実行時間の上限など）で
	 * アクションを失敗にした直後に、そのアクションが処理していたジョブを失敗にする（猶予を待たない。ページを処理していたプロセスは終わる）。
	 * 自分の hook・group のアクションで、`job_id` が正の int のときだけ。shutdown の中なので例外を外へ出さない。
	 * 既定の設定では走らない（実測）: WordPress の致命的エラーハンドラーは `shutdown` アクションより先に登録された shutdown 関数で、エラー画面を
	 * 出して `wp_die()` で終わるため、`shutdown` アクションが発火しない。その場合は AS が in-progress を失敗扱いにした後、`recover()` が猶予の後に拾う。
	 * 即時に効くのは、ハンドラーを無効にしたサイト（`WP_DISABLE_FATAL_ERROR_HANDLER`）と、ハンドラーがエラー画面を出さないとき。
	 *
	 * @param mixed $action_id Action Scheduler のアクションの ID。
	 * @param mixed $error     `error_get_last()` の値。
	 * @return bool ジョブを失敗にしたときだけ true。
	 */
	public function fail_after_fatal_error( mixed $action_id, mixed $error ): bool {
		try {
			if ( ! is_int( $action_id ) && ! ( is_string( $action_id ) && ctype_digit( $action_id ) ) ) {
				return false;
			}

			if ( ! class_exists( \ActionScheduler::class ) ) {
				return false;
			}

			$action = \ActionScheduler::store()->fetch_action( (string) $action_id );

			if ( JobManager::ACTION_HOOK !== $action->get_hook() || JobManager::ACTION_GROUP !== $action->get_group() ) {
				return false;
			}

			$job_id = $action->get_args()['job_id'] ?? null;

			if ( ! is_int( $job_id ) || $job_id <= 0 ) {
				return false;
			}

			$failed = $this->jobs->mark_failed(
				$job_id,
				[
					'code'    => self::ERROR_FATAL,
					'message' => self::fatal_error_message( $error ),
				]
			);

			if ( $failed ) {
				$this->logger->error( 'Job failed: PHP stopped with a fatal error while processing it.', [ 'job_id' => $job_id ] );
			}

			return $failed;
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * PHP のエラーの 1 行目（キャッチされない例外は 2 行目以降がスタックトレース）。WordPress のディレクトリを外して短くし、長さに上限を付ける。
	 */
	private static function fatal_error_message( mixed $error ): string {
		$message = is_array( $error ) && is_string( $error['message'] ?? null ) ? $error['message'] : '';
		$lines   = preg_split( '/\R/', $message, 2 );
		$line    = trim( is_array( $lines ) ? (string) $lines[0] : '' );

		if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			$line = str_replace( ABSPATH, '', $line );
		}

		return mb_substr( $line, 0, self::FATAL_MESSAGE_MAX_LENGTH );
	}
}
