<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use InvalidArgumentException;

/**
 * `cbjp_jobs` テーブルへのアクセス。
 *
 * @phpstan-type JobRow array{
 *     id:int,
 *     run_id:string,
 *     type:string,
 *     platform:string,
 *     entity:string,
 *     status:string,
 *     cursor_json:?string,
 *     totals_json:?string,
 *     error_json:?string,
 *     created_at:string,
 *     updated_at:string
 * }
 * @phpstan-type ActiveRun array{
 *     run_id:string,
 *     type:string,
 *     status:string,
 *     entities:list<string>,
 *     has_failed_job:bool,
 *     created_at:string,
 *     updated_at:string
 * }
 */
final class JobRepository {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_PAUSED    = 'paused';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'cbjp_jobs';
	}

	public function create( string $run_id, string $type, string $platform, string $entity ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$this->table(),
			[
				'run_id'      => $run_id,
				'type'        => $type,
				'platform'    => $platform,
				'entity'      => $entity,
				'status'      => self::STATUS_PENDING,
				'cursor_json' => null,
				'totals_json' => wp_json_encode( $this->empty_totals() ),
				'error_json'  => null,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return JobRow|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値は %d プレースホルダー経由。
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * run_id内のジョブをid昇順（=登録順=エンティティ実行順）で返す。
	 *
	 * @return array<int,JobRow>
	 */
	public function find_by_run( string $run_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値は %s プレースホルダー経由。
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE run_id = %s ORDER BY id ASC", $run_id ),
			ARRAY_A
		);
	}

	/**
	 * run内でまだ完了していない最初のジョブ（次に処理すべきジョブ）を返す。
	 *
	 * @return JobRow|null
	 */
	public function find_next_incomplete_for_run( string $run_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT * FROM {$this->table()} WHERE run_id = %s AND status NOT IN (%s, %s, %s) ORDER BY id ASC LIMIT 1",
				$run_id,
				self::STATUS_COMPLETED,
				self::STATUS_FAILED,
				self::STATUS_CANCELLED
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * 進行中（未終了 = pending/running/paused）のジョブが存在するか。
	 * running のみを見ると、レート制限で paused 中のrunと重複して新規runを開始できてしまう。
	 */
	public function has_active_job_for_platform( string $platform ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT COUNT(*) FROM {$this->table()} WHERE platform = %s AND status IN (%s, %s, %s)",
				$platform,
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				self::STATUS_PAUSED
			)
		);

		return $count > 0;
	}

	/**
	 * `has_active_job_for_platform()`の`run_id`除外版。`retry()`が対象ジョブと同一run内の
	 * 未処理な兄弟ジョブ（`start_run()`がrun開始時に全エンティティを`pending`で作るため、
	 * 1件が`failed`になっても他は`pending`のまま残りうる）を「進行中の別run」と誤検知しない
	 * ようにするため、対象run自身は判定対象から除く。
	 */
	public function has_active_job_for_platform_excluding_run( string $platform, string $run_id ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT COUNT(*) FROM {$this->table()} WHERE platform = %s AND run_id != %s AND status IN (%s, %s, %s)",
				$platform,
				$run_id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				self::STATUS_PAUSED
			)
		);

		return $count > 0;
	}

	/**
	 * Action Scheduler でこのプラットフォームのジョブのページを処理中（`in-progress`）のアクションがあるか
	 * （issue #57）。ジョブの状態は問わない: キャンセルしたジョブは `cancelled` になった後も、処理中のページを
	 * 最後まで書き続ける（`process_job()` は割り込めない）ため、その間は同時実行の判定で「進行中」に数える。
	 * 異常終了したアクションの `in-progress` は、Action Scheduler のキューランナーが次に動いたときに一定時間
	 * （既定 5 分。`action_scheduler_failure_period`）を超えたものから失敗扱いになり、判定から外れる。
	 */
	public function has_in_flight_job_for_platform( string $platform ): bool {
		global $wpdb;

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler_Store::class ) ) {
			return false;
		}

		$actions = as_get_scheduled_actions(
			[
				'hook'     => JobManager::ACTION_HOOK,
				'group'    => JobManager::ACTION_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => -1,
			]
		);

		$job_ids = [];

		foreach ( $actions as $action ) {
			$job_id = is_object( $action ) && method_exists( $action, 'get_args' ) ? ( $action->get_args()['job_id'] ?? null ) : null;

			if ( is_int( $job_id ) && $job_id > 0 ) {
				$job_ids[] = $job_id;
			}
		}

		if ( [] === $job_ids ) {
			return false;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $job_ids ), '%d' ) );

		$count = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $job_ids の要素数分の%dを動的生成しており、置換数はプレースホルダー数と一致する。
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名と%dプレースホルダー列のみの埋め込み。値はプレースホルダー経由。
				"SELECT COUNT(*) FROM {$this->table()} WHERE platform = %s AND id IN ({$placeholders})",
				array_merge( [ $platform ], $job_ids )
			)
		);

		return $count > 0;
	}

	/**
	 * 同時実行の判定（issue #57）: 進行中のジョブ（`$exclude_run_id` の run を除く）があるか、
	 * キャンセル済みを含むジョブのページを処理中のアクションがあるか。判定から状態変更までは
	 * `Support\PlatformLock::run()` で囲むこと（判定だけでは同時に届いた要求同士を防げない）。
	 *
	 * @param string|null $exclude_run_id 進行中のジョブの判定から除く run（`retry()` の対象ジョブ自身の run。
	 *   処理中のアクションの判定には除外を適用しない）。
	 */
	public function is_platform_busy( string $platform, ?string $exclude_run_id = null ): bool {
		$active = null === $exclude_run_id
			? $this->has_active_job_for_platform( $platform )
			: $this->has_active_job_for_platform_excluding_run( $platform, $exclude_run_id );

		return $active || $this->has_in_flight_job_for_platform( $platform );
	}

	/**
	 * 進行中（未終了のジョブ = pending/running/paused を1件以上持つ）の run をプラットフォーム単位で返す（issue #70）。
	 * run_id がブラウザに届かなかった run を UI が見つけ、進捗確認・キャンセルへ誘導するために使う。
	 *
	 * 同時実行ガードは「1プラットフォーム1 run」を保つ前提だが、判定が原子的でない（issue #57）間は
	 * 複数ありうるため一覧で返す（古い順）。run 単位の値は全ジョブ（終了済みを含む）から集約する:
	 * - `status`: running > paused > pending の優先で、未終了のジョブの状態を代表させる。
	 * - `entities`: 全ジョブのエンティティ（id 昇順）。失敗したジョブのエンティティも含める
	 *   （取り込む UI が前回の dry-run 件数を捨てる対象を決めるため）。
	 * - `has_failed_job`: 失敗したジョブがあるか（最初のジョブが失敗し、兄弟ジョブが pending のまま
	 *   止まった run を「実行中」と区別して表示するため）。
	 *
	 * @param string|null $exclude_run_id 判定から除外する run（`retry()`の対象ジョブ自身の run）。
	 * @return list<ActiveRun>
	 */
	public function find_active_runs_for_platform( string $platform, ?string $exclude_run_id = null ): array {
		global $wpdb;

		$run_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT run_id FROM {$this->table()} WHERE platform = %s AND run_id != %s AND status IN (%s, %s, %s) GROUP BY run_id ORDER BY MIN(id) ASC",
				$platform,
				// run_id は UUID で空文字列にならないため、除外が無いときは何も除外しない値になる。
				$exclude_run_id ?? '',
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				self::STATUS_PAUSED
			)
		);

		$runs = [];

		foreach ( $run_ids as $run_id ) {
			$run = $this->summarize_active_run( (string) $run_id, $this->find_by_run( (string) $run_id ) );

			if ( null !== $run ) {
				$runs[] = $run;
			}
		}

		return $runs;
	}

	/**
	 * @param array<int,JobRow> $jobs
	 * @return ActiveRun|null 未終了のジョブが無い（上の SELECT から読み直すまでの間に終わった）run は null。
	 */
	private function summarize_active_run( string $run_id, array $jobs ): ?array {
		$statuses = array_column( $jobs, 'status' );
		$status   = null;

		foreach ( [ self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_PENDING ] as $candidate ) {
			if ( in_array( $candidate, $statuses, true ) ) {
				$status = $candidate;
				break;
			}
		}

		if ( null === $status ) {
			return null;
		}

		$created_at = array_column( $jobs, 'created_at' );
		$updated_at = array_column( $jobs, 'updated_at' );

		return [
			'run_id'         => $run_id,
			'type'           => (string) $jobs[0]['type'],
			'status'         => $status,
			'entities'       => array_values( array_map( 'strval', array_column( $jobs, 'entity' ) ) ),
			'has_failed_job' => in_array( self::STATUS_FAILED, $statuses, true ),
			// MySQL の DATETIME 文字列（`Y-m-d H:i:s`）は辞書順＝時刻順のため文字列のまま比較できる。
			'created_at'     => (string) min( $created_at ),
			'updated_at'     => (string) max( $updated_at ),
		];
	}

	/**
	 * 状態を、今の状態が `$from` のどれかのときだけ `$to` へ変える（issue #57）。キャンセル（`cancel_run()`）や
	 * 別の要求が先に状態を変えていれば何もしない。`process_job()` が処理中のページの後で、キャンセル済みの
	 * ジョブを `completed`/`paused`/`running` で上書きして run を復活させないため、状態遷移は必ずこれを使う。
	 *
	 * @param list<string> $from
	 * @return bool 遷移した（1 行を更新した）ときだけ true。
	 */
	public function transition( int $id, array $from, string $to ): bool {
		global $wpdb;

		// `$to` を `$from` に含めると、MySQL の接続フラグ（CLIENT_FOUND_ROWS）によって「一致した行」と
		// 「変わった行」の数が食い違い、遷移の成否を判定できなくなる。
		if ( [] === $from || in_array( $to, $from, true ) ) {
			throw new InvalidArgumentException( 'A transition needs source states that differ from the target state.' );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $from ), '%s' ) );

		$updated = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $from の要素数分の%sを動的生成しており、置換数はプレースホルダー数と一致する。
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名と%sプレースホルダー列のみの埋め込み。値はプレースホルダー経由。
				"UPDATE {$this->table()} SET status = %s, updated_at = %s WHERE id = %d AND status IN ({$placeholders})",
				array_merge( [ $to, current_time( 'mysql', true ), $id ], array_values( $from ) )
			)
		);

		return 1 === $updated;
	}

	/**
	 * run の未終了のジョブをまとめて `cancelled` にする（1 文の条件付き UPDATE）。読んでから 1 件ずつ更新すると、
	 * その間に完了したジョブを `cancelled` で上書きしうる。
	 *
	 * @return int キャンセルしたジョブの数。
	 */
	public function cancel_run( string $run_id ): int {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"UPDATE {$this->table()} SET status = %s, updated_at = %s WHERE run_id = %s AND status IN (%s, %s, %s)",
				self::STATUS_CANCELLED,
				current_time( 'mysql', true ),
				$run_id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				self::STATUS_PAUSED
			)
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * 状態を無条件に書き換える。状態遷移の競合を考慮しないため本番コードでは使わず（`transition()` を使う）、
	 * テストで任意の状態を作るためだけに残す。
	 */
	public function update_status( int $id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @param array<string,mixed> $totals
	 */
	public function update_progress( int $id, ?string $cursor_json, array $totals ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'cursor_json' => $cursor_json,
				'totals_json' => wp_json_encode( $totals ),
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * ジョブが未終了（pending/running/paused）のときだけ `failed` にする。ページの処理中にキャンセルされた
	 * ジョブを `failed` で上書きしない（issue #57。`transition()` と同じ理由）。
	 *
	 * @param array{code:string,message:string} $error
	 * @return bool 失敗を記録したときだけ true。
	 */
	public function mark_failed( int $id, array $error ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"UPDATE {$this->table()} SET status = %s, error_json = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s, %s)",
				self::STATUS_FAILED,
				(string) wp_json_encode( $error ),
				current_time( 'mysql', true ),
				$id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				self::STATUS_PAUSED
			)
		);

		return 1 === $updated;
	}

	/**
	 * `remote_amount` は受注ジョブのみ意味を持つ: この run で ASP から取得した受注の合計金額
	 * （1/100単位の整数。`Support\Money`）。移行後検証レポート（D17。`VerificationReport`）が
	 * Woo 側の受注合計と突合するために `Importer::process_items()` が累積する。
	 *
	 * `unchanged` は `skipped` の内訳: checksum 一致（変更なし）で書かなかった件数（issue #55）。
	 * dry-run の `created + updated + unchanged` が「移行できる件数」になり、Pro 案内
	 * （`LimitsUpsellNotice`）が「移行できるが未移行」と「どの版でも移行できない」を分けるのに使う。
	 * 導入前に完了したジョブの `totals_json` には無い（`get_run()` は生の JSON を返す）。導入をまたいで続いた
	 * ジョブは、`JobManager::decode_totals()` がこの既定値をマージするため、導入前のページ分が 0 のまま載る（既知の制限）。
	 *
	 * @return array{total:int,processed:int,created:int,updated:int,skipped:int,unchanged:int,warned:int,failed:int,remote_amount:int}
	 */
	public function empty_totals(): array {
		return [
			'total'         => 0,
			'processed'     => 0,
			'created'       => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'unchanged'     => 0,
			'warned'        => 0,
			'failed'        => 0,
			'remote_amount' => 0,
		];
	}
}
