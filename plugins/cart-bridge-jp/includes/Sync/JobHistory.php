<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * ジョブの記録（`cbjp_jobs`）を読む拡張点（R3-10。拡張点の版 4）。拡張が「いつ、どの実体の取込みが動いたか」を知るためのもので、
 * 判定はせず、行の値をそのまま返す（どの行を使うかは呼び出し側が決める）。
 *
 * 時刻は UTC の `Y-m-d H:i:s`（`JobRepository` が `current_time( 'mysql', true )` で書く。0.1.0 から同じ）。終わったジョブの `updated_at` も、
 * 取消（`JobRepository::cancel_run()`）・孤立の回復・Retry で後から動きうる。totals の鍵は版で違う（`JobRepository::empty_totals()` の説明）。
 */
final class JobHistory {

	/**
	 * 種類・プラットフォーム・実体の一致するジョブを id の昇順で返す。totals・error が読めなければ空の配列・null にする。
	 *
	 * @return list<array{id:int,status:string,created_at:string,updated_at:string,totals:array<string,mixed>,error_code:?string}>
	 */
	public function jobs( string $type, string $platform, string $entity ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'cbjp_jobs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自前のテーブルの読取り。
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT id, status, totals_json, error_json, created_at, updated_at FROM {$table} WHERE type = %s AND platform = %s AND entity = %s ORDER BY id ASC",
				$type,
				$platform,
				$entity
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$jobs = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$jobs[] = [
				'id'         => (int) $row['id'],
				'status'     => (string) $row['status'],
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
				'totals'     => self::decode_object( $row['totals_json'] ?? null ),
				'error_code' => self::error_code( $row['error_json'] ?? null ),
			];
		}

		return $jobs;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function decode_object( mixed $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	private static function error_code( mixed $json ): ?string {
		$code = self::decode_object( $json )['code'] ?? null;

		return is_string( $code ) ? $code : null;
	}
}
