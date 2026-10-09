<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * `cbjp_push_intents` テーブルへのアクセス。作成結果が不明なまま自動再送しないための
 * 「送信中の印」（D21-B。`docs/03-design-decisions.md` §10.2「B: 作成結果が不明な実体を
 * 自動では再送しない」）。`Sync\Exporter` が作成経路（`existing_remote_id === null`）の
 * push直前に `begin()` で印を書き、結果に応じて `delete()`（送信していない／拒否が確定）するか
 * `mark_ambiguous()`（5xx・status 0・その他の例外で結果が不明）で残す。印が残る実体は
 * `has_unresolved()` により以後のexport（dry-runを含む）でpushされなくなる。
 */
final class PushIntentRepository {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'cbjp_push_intents';
	}

	/**
	 * `UNIQUE KEY (platform, entity_type, local_id)` への `INSERT IGNORE`。
	 * 既に行があれば何もせず false を返す（＝他run/他プロセスが同時に開始済み。#57の部分緩和）。
	 * 新規に印を書けた場合のみ true（このアイテムをpushしてよい）。
	 */
	public function begin( string $platform, string $entity_type, int $local_id, ?string $run_id, ?int $job_id ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UNIQUEキーによるinsert-if-absentはwpdb->insert()では表現できないため直接クエリを使う。
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"INSERT IGNORE INTO {$this->table()} (platform, entity_type, local_id, run_id, job_id, reason, created_at, updated_at)
				 VALUES (%s, %s, %d, NULLIF(%s, ''), NULLIF(%d, 0), NULL, %s, %s)",
				$platform,
				$entity_type,
				$local_id,
				$run_id ?? '',
				$job_id ?? 0,
				$now,
				$now
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * 読取専用の存在確認。dry-runのブロック判定はこちらを使い、DBへ書き込まない
	 * （F1-6の「dry-runは何も永続化しない」不変条件を守る）。
	 */
	public function has_unresolved( string $platform, string $entity_type, int $local_id ): bool {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT id FROM {$this->table()} WHERE platform = %s AND entity_type = %s AND local_id = %d LIMIT 1",
				$platform,
				$entity_type,
				$local_id
			)
		);

		return null !== $value;
	}

	/**
	 * 結果が不明（5xx・status 0・その他の例外・契約違反の再スロー）だったことを記録し、
	 * 印を残す（削除しない）。
	 */
	public function mark_ambiguous( string $platform, string $entity_type, int $local_id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'reason'     => 'ambiguous_error',
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'platform'    => $platform,
				'entity_type' => $entity_type,
				'local_id'    => $local_id,
			],
			[ '%s', '%s' ],
			[ '%s', '%s', '%d' ]
		);
	}

	/**
	 * 送信していない、または拒否が確定した（4xx・`UnsupportedOperationException`・送信前の
	 * `RateLimitExhaustedException`）場合に印を消す。実際に作成が確認できた場合も、mapping書込み後に
	 * これを呼ぶ。
	 */
	public function delete( string $platform, string $entity_type, int $local_id ): void {
		global $wpdb;

		$wpdb->delete(
			$this->table(),
			[
				'platform'    => $platform,
				'entity_type' => $entity_type,
				'local_id'    => $local_id,
			],
			[ '%s', '%s', '%d' ]
		);
	}

	/**
	 * `Woo\Tools\SampleCleanup` が対象platformの mappings を全て処理し終えた（全量完了）タイミングで
	 * 呼ぶ。mappingsを持たない未解決intent（＝定義上そのもの）は個別バッチの mapping 駆動ループでは
	 * 検出できないため、全量完了時に一括で消す。
	 */
	public function delete_for_platform( string $platform ): int {
		global $wpdb;

		return (int) $wpdb->delete( $this->table(), [ 'platform' => $platform ], [ '%s' ] );
	}

	/**
	 * `Sync\LimitPolicy` の無料版上限カウント用。未解決intentは「作成済みかもしれない実体」として
	 * 累積カウントに含める（D21-B「無料版の上限」）。
	 */
	public function count( string $platform, string $entity_type ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT COUNT(*) FROM {$this->table()} WHERE platform = %s AND entity_type = %s",
				$platform,
				$entity_type
			)
		);
	}

	/**
	 * REST一覧（`GET /push-intents/{platform}`）用。id昇順。
	 *
	 * @return array<int,array{id:int,entity_type:string,local_id:int,run_id:?string,job_id:?int,reason:?string,created_at:string,updated_at:string}>
	 */
	public function find_unresolved( string $platform ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT id, entity_type, local_id, run_id, job_id, reason, created_at, updated_at FROM {$this->table()} WHERE platform = %s ORDER BY id ASC",
				$platform
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): array => [
				'id'          => (int) $row['id'],
				'entity_type' => (string) $row['entity_type'],
				'local_id'    => (int) $row['local_id'],
				'run_id'      => null !== $row['run_id'] ? (string) $row['run_id'] : null,
				'job_id'      => null !== $row['job_id'] ? (int) $row['job_id'] : null,
				'reason'      => null !== $row['reason'] ? (string) $row['reason'] : null,
				'created_at'  => (string) $row['created_at'],
				'updated_at'  => (string) $row['updated_at'],
			],
			$rows
		);
	}

	/**
	 * REST解除（`POST /push-intents/{platform}/{id}/resolve`）用。`platform` が一致しない行は
	 * 見つからない扱いにする（他プラットフォームのidで誤って解除できないようにする）。
	 *
	 * @return ?array{id:int,entity_type:string,local_id:int,run_id:?string,job_id:?int,reason:?string,created_at:string,updated_at:string}
	 */
	public function find( int $id, string $platform ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT id, entity_type, local_id, run_id, job_id, reason, created_at, updated_at FROM {$this->table()} WHERE id = %d AND platform = %s",
				$id,
				$platform
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		return [
			'id'          => (int) $row['id'],
			'entity_type' => (string) $row['entity_type'],
			'local_id'    => (int) $row['local_id'],
			'run_id'      => null !== $row['run_id'] ? (string) $row['run_id'] : null,
			'job_id'      => null !== $row['job_id'] ? (int) $row['job_id'] : null,
			'reason'      => null !== $row['reason'] ? (string) $row['reason'] : null,
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
		];
	}

	public function delete_by_id( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
	}
}
