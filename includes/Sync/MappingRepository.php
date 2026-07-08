<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * `cbjp_mappings` テーブルへのアクセス。冪等性・差分検出・無料版上限カウントの正。
 */
final class MappingRepository {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'cbjp_mappings';
	}

	/**
	 * UNIQUE (platform, entity_type, remote_id) による upsert。
	 */
	public function upsert( string $platform, string $entity_type, string $remote_id, int $local_id, ?string $checksum ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UNIQUEキーによるupsertはwpdb->replace/insertでは表現できないため直接クエリを使う。
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"INSERT INTO {$this->table()} (platform, entity_type, remote_id, local_id, checksum, synced_at)
				 VALUES (%s, %s, %s, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), checksum = VALUES(checksum), synced_at = VALUES(synced_at)",
				$platform,
				$entity_type,
				$remote_id,
				$local_id,
				$checksum,
				$now
			)
		);
	}

	public function find_local_id( string $platform, string $entity_type, string $remote_id ): ?int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT local_id FROM {$this->table()} WHERE platform = %s AND entity_type = %s AND remote_id = %s",
				$platform,
				$entity_type,
				$remote_id
			)
		);

		return null === $value ? null : (int) $value;
	}

	public function find_checksum( string $platform, string $entity_type, string $remote_id ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT checksum FROM {$this->table()} WHERE platform = %s AND entity_type = %s AND remote_id = %s",
				$platform,
				$entity_type,
				$remote_id
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * 無料版上限強制の正となる累積カウント（D15/§10.2）。
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

	public function delete_for_platform( string $platform, ?string $entity_type = null ): int {
		global $wpdb;

		if ( null !== $entity_type ) {
			return (int) $wpdb->delete(
				$this->table(),
				[
					'platform'    => $platform,
					'entity_type' => $entity_type,
				],
				[ '%s', '%s' ]
			);
		}

		return (int) $wpdb->delete( $this->table(), [ 'platform' => $platform ], [ '%s' ] );
	}
}
