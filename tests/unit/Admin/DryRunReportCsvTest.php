<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Admin\DryRunReportCsv;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use WP_UnitTestCase;

final class DryRunReportCsvTest extends WP_UnitTestCase {

	private DryRunItemRepository $items;
	private DryRunReportCsv $csv;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->items = new DryRunItemRepository();
		$this->csv   = new DryRunReportCsv( $this->items );
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
				'warnings'          => [],
			],
			$overrides
		);
	}

	public function test_item_without_warnings_is_a_single_row_with_empty_warning_columns(): void {
		$this->items->insert_many( 'run-1', 1, [ $this->row() ] );

		$rows = $this->csv->rows( 'run-1', null, false );

		$this->assertSame(
			[ [ 'product', 'p1', 'Widget', 'created', '0', '', '', '' ] ],
			$rows
		);
	}

	public function test_only_warnings_flag_omits_rows_without_warnings(): void {
		$this->items->insert_many( 'run-2', 1, [ $this->row() ] );

		$this->assertSame( [], $this->csv->rows( 'run-2', null, true ) );
	}

	public function test_multiple_warnings_expand_into_multiple_rows(): void {
		$this->items->insert_many(
			'run-3',
			1,
			[ $this->row( [ 'warnings' => [ 'sku_duplicate:SKU-1', 'tax_class_missing:reduced-rate' ] ] ) ]
		);

		$rows = $this->csv->rows( 'run-3', null, false );

		$this->assertCount( 2, $rows );
		$this->assertSame( [ 'sku_duplicate', 'SKU-1' ], [ $rows[0][5], $rows[0][6] ] );
		$this->assertSame( [ 'tax_class_missing', 'reduced-rate' ], [ $rows[1][5], $rows[1][6] ] );
	}

	public function test_detail_containing_a_colon_is_not_mis_split(): void {
		// `WarningCode::split()`は最初の`:`でのみ分割する契約（画像URL等detail自体に`:`を
		// 含みうるため）。CSVもこの契約に従うことを確認する。
		$this->items->insert_many(
			'run-4',
			1,
			[ $this->row( [ 'warnings' => [ 'image_download_failed:https://example.test/a.png' ] ] ) ]
		);

		$rows = $this->csv->rows( 'run-4', null, false );

		$this->assertSame( 'image_download_failed', $rows[0][5] );
		$this->assertSame( 'https://example.test/a.png', $rows[0][6] );
	}

	public function test_reference_pending_warning_is_flagged_in_the_note_column(): void {
		$this->items->insert_many( 'run-5', 1, [ $this->row( [ 'warnings' => [ 'category_ref_unresolved:10' ] ] ) ] );

		$rows = $this->csv->rows( 'run-5', null, false );

		$this->assertSame( 'reference_pending_import', $rows[0][7] );
	}

	public function test_stock_with_unimported_parent_product_is_flagged_as_pending_import(): void {
		// 在庫は親商品が未解決だとアイテム自体が保存されないため`indicates_unresolved_reference()`
		// （checksumキャッシュ判定用）の対象外だが、レポート上は他の参照未解決と同じ
		// 「未インポート起因」の注記を付ける（実機dry-runで在庫全件がこの警告になった）。
		$this->items->insert_many(
			'run-5b',
			1,
			[
				$this->row(
					[
						'entity'    => 'stock',
						'operation' => 'skipped',
						'warnings'  => [ 'stock_product_unresolved:193326769' ],
					]
				),
			]
		);

		$rows = $this->csv->rows( 'run-5b', null, false );

		$this->assertSame( 'reference_pending_import', $rows[0][7] );
	}

	public function test_unrelated_warning_leaves_the_note_column_empty(): void {
		$this->items->insert_many( 'run-6', 1, [ $this->row( [ 'warnings' => [ 'sku_duplicate:SKU-1' ] ] ) ] );

		$rows = $this->csv->rows( 'run-6', null, false );

		$this->assertSame( '', $rows[0][7] );
	}

	public function test_entity_filter_is_applied(): void {
		$this->items->insert_many(
			'run-7',
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

		$rows = $this->csv->rows( 'run-7', 'order', false );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'o1', $rows[0][1] );
	}

	public function test_csv_injection_payloads_are_hardened(): void {
		$payloads = [ '=cmd|test', '+1+1', '-1+1', '@SUM(A1)' ];

		foreach ( $payloads as $payload ) {
			$this->items->insert_many(
				'run-8',
				1,
				[
					$this->row(
						[
							'remote_id' => $payload,
							'label'     => $payload,
						]
					),
				]
			);
		}

		$rows = $this->csv->rows( 'run-8', null, false );

		$this->assertCount( count( $payloads ), $rows );

		foreach ( $rows as $row ) {
			// remote_id列（index 1）・label列（index 2）はASP由来の自由入力値。
			$this->assertSame( "'", $row[1][0] );
			$this->assertSame( "'", $row[2][0] );
		}
	}

	/**
	 * PRレビュー指摘: `harden()`は「制御文字を除去する」と謳いながら正規表現がタブ/CR/LFを
	 * 除外対象から外しており、実際には値中に残っていた。埋め込まれた生の改行はCSVとしては
	 * `fputcsv()`のクォートで壊れないものの、改行区切り前提の後続パーサーでは行構造が崩れて
	 * 見えるため、他の制御文字と同様に取り除かれることを確認する。
	 */
	public function test_control_characters_including_tab_cr_lf_are_stripped(): void {
		$this->items->insert_many(
			'run-8b',
			1,
			[
				$this->row(
					[
						'remote_id' => "p\t1",
						'label'     => "line1\r\nline2\x00tail",
					]
				),
			]
		);

		$rows = $this->csv->rows( 'run-8b', null, false );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'p1', $rows[0][1] );
		$this->assertSame( 'line1line2tail', $rows[0][2] );
	}

	public function test_paginates_beyond_a_single_batch(): void {
		// PAGE_SIZE（500）を超える行数でも全件出ることを確認する（keysetページングの境界）。
		$rows = [];

		for ( $i = 0; $i < 520; $i++ ) {
			$rows[] = $this->row( [ 'remote_id' => "p{$i}" ] );
		}

		$this->items->insert_many( 'run-9', 1, $rows );

		$this->assertCount( 520, $this->csv->rows( 'run-9', null, false ) );
	}

	public function test_stream_writes_a_bom_and_header_row(): void {
		$this->items->insert_many( 'run-10', 1, [ $this->row() ] );

		ob_start();
		$this->csv->stream( 'run-10', null, false );
		$output = ob_get_clean();

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $output );
		$this->assertStringContainsString( 'entity,remote_id,label,operation,existing_local_id,warning_code,warning_detail,note', $output );
		$this->assertStringContainsString( 'product,p1,Widget,created,0,,,', $output );
	}
}
