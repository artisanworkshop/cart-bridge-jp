<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use WP_UnitTestCase;

final class DryRunItemRepositoryTest extends WP_UnitTestCase {

	private DryRunItemRepository $repository;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->repository = new DryRunItemRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array{entity:string,remote_id:string,label:string,operation:string,existing_local_id:int,warnings:array<int,string>}
	 */
	private function row( array $overrides = [] ): array {
		return array_merge(
			[
				'entity'            => 'product',
				'remote_id'         => 'p1',
				'label'             => 'Widget',
				'operation'         => 'created',
				'existing_local_id' => 0,
				'warnings'          => [ 'sku_duplicate:SKU-1' ],
			],
			$overrides
		);
	}

	public function test_insert_many_round_trips_via_list_after(): void {
		$this->repository->insert_many( 'run-1', 1, [ $this->row() ] );

		$rows = $this->repository->list_after( 'run-1', 0, 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'product', $rows[0]['entity'] );
		$this->assertSame( 'p1', $rows[0]['remote_id'] );
		$this->assertSame( 'Widget', $rows[0]['label'] );
		$this->assertSame( 'created', $rows[0]['operation'] );
		$this->assertSame( '0', $rows[0]['existing_local_id'] );
		$this->assertSame( [ 'sku_duplicate:SKU-1' ], json_decode( (string) $rows[0]['warnings_json'], true ) );
	}

	public function test_insert_many_handles_more_rows_than_the_chunk_size(): void {
		$rows = [];

		for ( $i = 0; $i < 450; $i++ ) {
			$rows[] = $this->row( [ 'remote_id' => "p{$i}" ] );
		}

		$this->repository->insert_many( 'run-2', 1, $rows );

		$this->assertSame( 450, $this->repository->count_for_run( 'run-2' ) );
	}

	public function test_rerunning_the_same_job_and_item_upserts_instead_of_duplicating(): void {
		$this->repository->insert_many( 'run-3', 1, [ $this->row( [ 'operation' => 'created' ] ) ] );
		$this->repository->insert_many( 'run-3', 1, [ $this->row( [ 'operation' => 'updated' ] ) ] );

		$rows = $this->repository->list_after( 'run-3', 0, 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'updated', $rows[0]['operation'] );
	}

	public function test_empty_label_and_zero_existing_local_id_are_not_stored_as_null(): void {
		// 生SQLの`$wpdb->prepare()`+`query()`はnullを`%s`プレースホルダー経由で空文字列に
		// 変換してしまう（CLAUDE.md）。この罠を避けるためlabel/existing_local_idはNULL許容
		// カラムを持たない設計にしている（番兵値='' / 0）ことの回帰テスト。
		global $wpdb;

		$this->repository->insert_many(
			'run-4',
			1,
			[
				$this->row(
					[
						'label'             => '',
						'existing_local_id' => 0,
					]
				),
			]
		);

		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}cbjp_dry_run_items WHERE run_id = 'run-4'", ARRAY_A );

		$this->assertNotNull( $row );
		$this->assertSame( '', $row['label'] );
		$this->assertSame( '0', $row['existing_local_id'] );
	}

	public function test_list_after_filters_by_entity(): void {
		$this->repository->insert_many(
			'run-5',
			1,
			[
				$this->row(
					[
						'entity'    => 'product',
						'remote_id' => 'p1',
					]
				),
				$this->row(
					[
						'entity'    => 'order',
						'remote_id' => 'o1',
					]
				),
			]
		);

		$rows = $this->repository->list_after( 'run-5', 0, 10, 'order' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'o1', $rows[0]['remote_id'] );
	}

	public function test_list_after_is_a_keyset_page_ordered_by_id(): void {
		$this->repository->insert_many(
			'run-6',
			1,
			[
				$this->row( [ 'remote_id' => 'p1' ] ),
				$this->row( [ 'remote_id' => 'p2' ] ),
				$this->row( [ 'remote_id' => 'p3' ] ),
			]
		);

		$first_page = $this->repository->list_after( 'run-6', 0, 2 );
		$this->assertCount( 2, $first_page );

		$second_page = $this->repository->list_after( 'run-6', (int) $first_page[1]['id'], 2 );
		$this->assertCount( 1, $second_page );
		$this->assertSame( 'p3', $second_page[0]['remote_id'] );
	}

	public function test_delete_older_than_removes_only_stale_rows(): void {
		global $wpdb;

		$this->repository->insert_many( 'run-7', 1, [ $this->row() ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テスト専用: 保持期間クリーンアップ対象になるよう作成日時を過去に書き換える。
		$wpdb->query( "UPDATE {$wpdb->prefix}cbjp_dry_run_items SET created_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE run_id = 'run-7'" );

		$this->repository->insert_many( 'run-8', 1, [ $this->row( [ 'remote_id' => 'fresh' ] ) ] );

		$deleted = $this->repository->delete_older_than( 30 );

		$this->assertSame( 1, $deleted );
		$this->assertSame( 0, $this->repository->count_for_run( 'run-7' ) );
		$this->assertSame( 1, $this->repository->count_for_run( 'run-8' ) );
	}
}
