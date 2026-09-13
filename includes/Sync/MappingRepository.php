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
				// checksumがnull指定のとき文字列プレースホルダー経由では空文字列として書き込まれてしまうため、
				// SQL側で空文字列を明示的にNULLへ変換する。この列にはハッシュ値のみが入り空文字列は使わない。
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"INSERT INTO {$this->table()} (platform, entity_type, remote_id, local_id, checksum, synced_at)
				 VALUES (%s, %s, %s, %d, NULLIF(%s, ''), %s)
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

	/**
	 * ページ内アイテムの既存mappingを一括で取得する（アイテム毎のSELECTを避ける）。
	 *
	 * @param array<int,string> $remote_ids
	 * @return array<string,array{local_id:int,checksum:?string}> remote_id をキーとするマップ。
	 */
	public function find_many( string $platform, string $entity_type, array $remote_ids ): array {
		global $wpdb;

		if ( [] === $remote_ids ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $remote_ids ), '%s' ) );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $remote_ids の要素数分の%sを動的生成しており、置換数はプレースホルダー数と一致する。
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名と%sプレースホルダー列のみの埋め込み。値はプレースホルダー経由。
				"SELECT remote_id, local_id, checksum FROM {$this->table()} WHERE platform = %s AND entity_type = %s AND remote_id IN ({$placeholders})",
				array_merge( [ $platform, $entity_type ], $remote_ids )
			),
			ARRAY_A
		);

		$map = [];

		foreach ( $rows as $row ) {
			$map[ (string) $row['remote_id'] ] = [
				'local_id' => (int) $row['local_id'],
				'checksum' => null !== $row['checksum'] ? (string) $row['checksum'] : null,
			];
		}

		return $map;
	}

	/**
	 * `find_local_id()`の逆引き（エクスポート用）。Wooローカルエンティティに対応する
	 * 既存remote_idを解決する。`platform_entity_local`インデックスを使う。
	 *
	 * `(platform, entity_type, local_id)`はUNIQUE制約ではない（同じWooエンティティを複数の
	 * remote_idが指す状態は本来想定しないが、`UNIQUE KEY`は`remote_id`側にしか無い）ため、
	 * 想定外に複数行が存在する場合に備えid昇順（最初にリンクされたもの）を決定的に採用する。
	 */
	public function find_remote_id( string $platform, string $entity_type, int $local_id ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT remote_id FROM {$this->table()} WHERE platform = %s AND entity_type = %s AND local_id = %d ORDER BY id ASC LIMIT 1",
				$platform,
				$entity_type,
				$local_id
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * ページ内アイテムの既存mappingを一括で取得する（`find_many()`の逆引き版。
	 * エクスポートでアイテム毎のSELECTを避けるために使う）。`find_remote_id()`と同じ理由で
	 * id昇順に並べ、想定外の重複行があれば最初の1件を採用する。
	 *
	 * @param array<int,int> $local_ids
	 * @return array<int,array{remote_id:string,checksum:?string}> local_id をキーとするマップ。
	 */
	public function find_many_by_local_ids( string $platform, string $entity_type, array $local_ids ): array {
		global $wpdb;

		if ( [] === $local_ids ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $local_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $local_ids の要素数分の%dを動的生成しており、置換数はプレースホルダー数と一致する。
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名と%dプレースホルダー列のみの埋め込み。値はプレースホルダー経由。
				"SELECT local_id, remote_id, checksum FROM {$this->table()} WHERE platform = %s AND entity_type = %s AND local_id IN ({$placeholders}) ORDER BY id ASC",
				array_merge( [ $platform, $entity_type ], $local_ids )
			),
			ARRAY_A
		);

		$map = [];

		foreach ( $rows as $row ) {
			// 想定外の重複（上記docblock参照）でも先に処理したid昇順の最初の行を保持する。
			if ( isset( $map[ (int) $row['local_id'] ] ) ) {
				continue;
			}

			$map[ (int) $row['local_id'] ] = [
				'remote_id' => (string) $row['remote_id'],
				'checksum'  => null !== $row['checksum'] ? (string) $row['checksum'] : null,
			];
		}

		return $map;
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
	 * platform + entity_type の mapping 行を id 昇順で最大 $limit 件返す（remote_id => local_id）。
	 * サンプルクリーンアップ（`Woo\Tools\SampleCleanup`）が「削除しては先頭から再取得」を繰り返す
	 * 反復用で、offset は取らない。
	 *
	 * @return array<string,int>
	 */
	public function find_page( string $platform, string $entity_type, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT remote_id, local_id FROM {$this->table()} WHERE platform = %s AND entity_type = %s ORDER BY id ASC LIMIT %d",
				$platform,
				$entity_type,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$page = [];

		foreach ( $rows as $row ) {
			$page[ (string) $row['remote_id'] ] = (int) $row['local_id'];
		}

		return $page;
	}

	/**
	 * platform + entity_type の mapping が指すローカルIDの一覧（重複除去・昇順）。
	 * 移行後検証レポート（`VerificationReport`）とクリーンアップのプレビューが Woo 側の実在確認に使う。
	 *
	 * @return array<int,int>
	 */
	public function local_ids( string $platform, string $entity_type ): array {
		global $wpdb;

		$values = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名のみの埋め込み。値はプレースホルダー経由。
				"SELECT DISTINCT local_id FROM {$this->table()} WHERE platform = %s AND entity_type = %s ORDER BY local_id ASC",
				$platform,
				$entity_type
			)
		);

		return array_map( 'intval', $values );
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

	/**
	 * 単一mapping行の削除。ASP側から消えた個別レコード（例: 削除されたバリエーション）の
	 * mappingsを、他のplatform/entity_type分を巻き込まずに掃除するために使う。
	 */
	public function delete_one( string $platform, string $entity_type, string $remote_id ): void {
		global $wpdb;

		$wpdb->delete(
			$this->table(),
			[
				'platform'    => $platform,
				'entity_type' => $entity_type,
				'remote_id'   => $remote_id,
			],
			[ '%s', '%s', '%s' ]
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
