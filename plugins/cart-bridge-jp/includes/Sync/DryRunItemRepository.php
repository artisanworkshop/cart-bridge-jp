<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * `cbjp_dry_run_items` の読み書き。dry-run実行が処理した各アイテム1件=1行を記録し、
 * F1-6のCSVレポートが読み出す。書込は `Importer::process_items()` からページ単位で
 * まとめて行う（1件ずつINSERTするとページ内アイテム数だけクエリが積み重なるため）。
 *
 * NULL許容カラムを持たない: `$wpdb->prepare()` + 生 `query()` はnullを`%s`プレースホルダー
 * 経由で空文字列に変換してしまう（CLAUDE.md）。「無し」は`label=''`/`existing_local_id=0`の
 * 番兵値で表現する契約にすることで、この罠を回避する。
 */
final class DryRunItemRepository {

	/**
	 * 1回の`INSERT`文に含める最大行数（max_allowed_packet対策）。
	 */
	private const INSERT_CHUNK_SIZE = 200;

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'cbjp_dry_run_items';
	}

	/**
	 * 同一`(job_id, entity, remote_id)`は`ON DUPLICATE KEY UPDATE`で上書きする
	 * （ジョブ再試行・再実行での冪等性。`MappingRepository::upsert()`と同じ手法）。
	 *
	 * @param array<int,array{entity:string,remote_id:string,label:string,operation:string,existing_local_id:int,warnings:array<int,string>}> $rows
	 */
	public function insert_many( string $run_id, int $job_id, array $rows ): void {
		if ( [] === $rows ) {
			return;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		foreach ( array_chunk( $rows, self::INSERT_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '(%s, %d, %s, %s, %s, %s, %d, %s, %s)' ) );
			$values       = [];

			foreach ( $chunk as $row ) {
				array_push(
					$values,
					$run_id,
					$job_id,
					$row['entity'],
					$row['remote_id'],
					$row['label'],
					$row['operation'],
					$row['existing_local_id'],
					(string) wp_json_encode( array_values( $row['warnings'] ) ),
					$now
				);
			}

			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- テーブル名と$placeholders（$chunkの行数分の固定パターン文字列を繰り返し生成した%sプレースホルダー列）の埋め込み。置換数（$values）はプレースホルダー数と一致する。
					"INSERT INTO {$this->table()} (run_id, job_id, entity, remote_id, label, operation, existing_local_id, warnings_json, created_at) VALUES {$placeholders} ON DUPLICATE KEY UPDATE run_id = VALUES(run_id), label = VALUES(label), operation = VALUES(operation), existing_local_id = VALUES(existing_local_id), warnings_json = VALUES(warnings_json), created_at = VALUES(created_at)",
					$values
				)
			);
		}
	}

	/**
	 * CSVストリーミング用のkeysetページング（id昇順）。OFFSETは使わない（大量行で遅くなるため）。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_after( string $run_id, int $after_id, int $limit, ?string $entity = null ): array {
		global $wpdb;

		$where  = [ 'run_id = %s', 'id > %d' ];
		$params = [ $run_id, $after_id ];

		if ( null !== $entity ) {
			$where[]  = 'entity = %s';
			$params[] = $entity;
		}

		$params[] = $limit;

		// $where は固定リテラルの条件句のみ（ユーザー入力は含まない）。値は全て $params 経由でプレースホルダー化する。
		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sqlは上記の通り固定条件句のみで動的に組み立てたSQL文字列であり、値は$paramsでプレースホルダー化されている。
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public function count_for_run( string $run_id, ?string $entity = null ): int {
		global $wpdb;

		if ( null !== $entity ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
					"SELECT COUNT(*) FROM {$this->table()} WHERE run_id = %s AND entity = %s",
					$run_id,
					$entity
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT COUNT(*) FROM {$this->table()} WHERE run_id = %s",
				$run_id
			)
		);
	}

	/**
	 * `cbjp/dry_run_items/retention_days` フィルターで指定された日数を超えた行を削除する
	 * （`LogRepository::delete_older_than()`と同じ方式）。
	 */
	public function delete_older_than( int $days ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"DELETE FROM {$this->table()} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql', true ),
				$days
			)
		);
	}
}
