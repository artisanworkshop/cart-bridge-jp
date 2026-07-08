<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

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

	public function has_running_job_for_platform( string $platform ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT COUNT(*) FROM {$this->table()} WHERE platform = %s AND status = %s",
				$platform,
				self::STATUS_RUNNING
			)
		);

		return $count > 0;
	}

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
	 * @param array{code:string,message:string} $error
	 */
	public function mark_failed( int $id, array $error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'status'     => self::STATUS_FAILED,
				'error_json' => wp_json_encode( $error ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @return array{total:int,processed:int,created:int,updated:int,skipped:int,warned:int,failed:int}
	 */
	public function empty_totals(): array {
		return [
			'total'     => 0,
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'warned'    => 0,
			'failed'    => 0,
		];
	}
}
