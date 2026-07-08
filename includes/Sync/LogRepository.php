<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * `cbjp_logs` テーブルの読み取り・保持期間クリーンアップ。書き込みは `Support\Logger` が担う。
 */
final class LogRepository {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'cbjp_logs';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list( ?int $job_id = null, ?string $level = null, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$where  = [ '1=1' ];
		$params = [];

		if ( null !== $job_id ) {
			$where[]  = 'job_id = %d';
			$params[] = $job_id;
		}

		if ( null !== $level ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		$offset   = max( 0, ( $page - 1 ) * $per_page );
		$params[] = $per_page;
		$params[] = $offset;

		// $where は固定リテラルの条件句のみ（ユーザー入力は含まない）。値は全て $params 経由でプレースホルダー化する。
		$sql = "SELECT * FROM {$this->table()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sqlは上記の通り固定条件句のみで動的に組み立てたSQL文字列であり、値は$paramsでプレースホルダー化されている。
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * `cbjp/logs/retention_days` フィルターで指定された日数を超えたログを削除する。
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
