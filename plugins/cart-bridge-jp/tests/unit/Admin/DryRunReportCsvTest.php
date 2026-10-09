<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Admin\DryRunReportCsv;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Woo\WarningCatalog;
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

		$rows = $this->csv->rows( 'run-1', null, false, WarningCatalog::IMPORT );

		$this->assertSame(
			[ [ 'product', 'p1', 'Widget', 'created', '0', '', '', '', '', '', '' ] ],
			$rows
		);
	}

	public function test_only_warnings_flag_omits_rows_without_warnings(): void {
		$this->items->insert_many( 'run-2', 1, [ $this->row() ] );

		$this->assertSame( [], $this->csv->rows( 'run-2', null, true, WarningCatalog::IMPORT ) );
	}

	public function test_multiple_warnings_expand_into_multiple_rows(): void {
		$this->items->insert_many(
			'run-3',
			1,
			[ $this->row( [ 'warnings' => [ 'sku_duplicate:SKU-1', 'tax_class_missing:reduced-rate' ] ] ) ]
		);

		$rows = $this->csv->rows( 'run-3', null, false, WarningCatalog::IMPORT );

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

		$rows = $this->csv->rows( 'run-4', null, false, WarningCatalog::IMPORT );

		$this->assertSame( 'image_download_failed', $rows[0][5] );
		$this->assertSame( 'https://example.test/a.png', $rows[0][6] );
	}

	public function test_reference_pending_warning_is_flagged_in_the_note_column(): void {
		$this->items->insert_many( 'run-5', 1, [ $this->row( [ 'warnings' => [ 'category_ref_unresolved:10' ] ] ) ] );

		$rows = $this->csv->rows( 'run-5', null, false, WarningCatalog::IMPORT );

		$this->assertSame( 'reference_pending_import', $rows[0][7] );
	}

	/**
	 * D26: 軽減税率の税区分・税率が Woo に無いことによる取込みの警告は、`tax_setup_required`（WooCommerce の税の設定を先に作る）。
	 */
	public function test_tax_setup_warnings_are_flagged_in_the_note_column(): void {
		$this->items->insert_many( 'run-tax', 1, [ $this->row( [ 'warnings' => [ 'reduced_tax_class_not_found', 'tax_rates_not_configured:reduced-rate', 'tax_class_missing:x' ] ] ) ] );

		$rows = $this->csv->rows( 'run-tax', null, false, WarningCatalog::IMPORT );

		$this->assertSame( [ 'tax_setup_required', 'tax_setup_required', '' ], array_column( $rows, 7 ) );
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

		$rows = $this->csv->rows( 'run-5b', null, false, WarningCatalog::IMPORT );

		$this->assertSame( 'reference_pending_import', $rows[0][7] );
	}

	/**
	 * エクスポート方向の「参照先がまだエクスポートされていない」警告
	 * （`ORDER_LINE_PRODUCT_NOT_EXPORTED`/`ORDER_CUSTOMER_NOT_EXPORTED`）は、インポート方向の
	 * `reference_pending_import`（「先にインポートしてください」）ではなく専用の
	 * `reference_pending_export`（「先にエクスポートしてください」）を付ける必要がある
	 * （向きが逆の誤った案内を防ぐ。レビュー指摘）。
	 */
	public function test_export_pending_reference_warning_is_flagged_as_pending_export(): void {
		$this->items->insert_many(
			'run-5c',
			1,
			[ $this->row( [ 'warnings' => [ 'order_line_product_not_exported:42' ] ] ) ]
		);

		$rows = $this->csv->rows( 'run-5c', null, false, WarningCatalog::IMPORT );

		$this->assertSame( 'reference_pending_export', $rows[0][7] );
	}

	/**
	 * R3-0m: 決済/配送/カテゴリの未マッピングは「マッピング設定（Mappings タブ）を追加すれば消える」警告として
	 * `mapping_required` を付ける。実店舗の受注 dry-run で全件に付いた 2 警告の `note` が空で、店舗オーナーが
	 * 原因（マッピング未設定）に辿り着けなかった。
	 */
	public function test_unmapped_method_and_category_warnings_are_flagged_as_mapping_required(): void {
		$this->items->insert_many(
			'run-5d',
			1,
			[
				$this->row(
					[
						'entity'   => 'order',
						'warnings' => [ 'payment_method_unmapped:1094475', 'shipping_method_unmapped:640580' ],
					]
				),
				$this->row(
					[
						'remote_id' => 'p2',
						'warnings'  => [ 'category_map_unresolved:15' ],
					]
				),
			]
		);

		$rows = $this->csv->rows( 'run-5d', null, false, WarningCatalog::IMPORT );

		$this->assertCount( 3, $rows );
		$this->assertSame( [ 'payment_method_unmapped', '1094475', 'mapping_required' ], [ $rows[0][5], $rows[0][6], $rows[0][7] ] );
		$this->assertSame( [ 'shipping_method_unmapped', '640580', 'mapping_required' ], [ $rows[1][5], $rows[1][6], $rows[1][7] ] );
		$this->assertSame( [ 'category_map_unresolved', '15', 'mapping_required' ], [ $rows[2][5], $rows[2][6], $rows[2][7] ] );
	}

	/**
	 * R3-0n: 受注の商品・顧客の未解決は「未インポート、またはASP側で削除済み」を含む `reference_unresolved`。
	 * 実店舗の受注 dry-run では、この 2 コードの参照先はすべて削除済みで、`reference_pending_import`（先にインポートすれば
	 * 消える）は誤った案内だった。商品は取り込み済みでバリエーションだけ特定できない明細には注記を付けない。
	 */
	public function test_order_reference_warnings_get_a_neutral_note(): void {
		$this->items->insert_many(
			'run-5e',
			1,
			[
				$this->row(
					[
						'entity'   => 'order',
						'warnings' => [ 'order_line_product_unresolved:p-gone', 'order_customer_unresolved:c-gone', 'order_line_variation_unmatched:vp-axes' ],
					]
				),
			]
		);

		$rows = $this->csv->rows( 'run-5e', null, false, WarningCatalog::IMPORT );

		$this->assertCount( 3, $rows );
		$this->assertSame( [ 'order_line_product_unresolved', 'p-gone', 'reference_unresolved' ], [ $rows[0][5], $rows[0][6], $rows[0][7] ] );
		$this->assertSame( [ 'order_customer_unresolved', 'c-gone', 'reference_unresolved' ], [ $rows[1][5], $rows[1][6], $rows[1][7] ] );
		$this->assertSame( [ 'order_line_variation_unmatched', 'vp-axes', '' ], [ $rows[2][5], $rows[2][6], $rows[2][7] ] );
	}

	public function test_unrelated_warning_leaves_the_note_column_empty(): void {
		$this->items->insert_many( 'run-6', 1, [ $this->row( [ 'warnings' => [ 'sku_duplicate:SKU-1' ] ] ) ] );

		$rows = $this->csv->rows( 'run-6', null, false, WarningCatalog::IMPORT );

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

		$rows = $this->csv->rows( 'run-7', 'order', false, WarningCatalog::IMPORT );

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

		$rows = $this->csv->rows( 'run-8', null, false, WarningCatalog::IMPORT );

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

		$rows = $this->csv->rows( 'run-8b', null, false, WarningCatalog::IMPORT );

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

		$this->assertCount( 520, $this->csv->rows( 'run-9', null, false, WarningCatalog::IMPORT ) );
	}

	/**
	 * R3-0k: 警告の行の末尾 3 列は、その警告の `WarningCatalog` の説明（重大度・原因・対処）。
	 */
	public function test_warning_rows_carry_the_catalog_description(): void {
		$this->items->insert_many( 'run-cat', 1, [ $this->row( [ 'warnings' => [ 'category_ref_unresolved:10' ] ] ) ] );

		$rows        = $this->csv->rows( 'run-cat', null, false, WarningCatalog::IMPORT );
		$description = WarningCatalog::describe( 'category_ref_unresolved:10', WarningCatalog::IMPORT );

		$this->assertCount( 11, $rows[0] );
		$this->assertSame( [ $description['severity'], $description['message'], $description['action'] ], array_slice( $rows[0], 8 ) );
		$this->assertNotSame( '', $rows[0][9] );
		// 既存の列（コード・detail・note）は変わらない。
		$this->assertSame( [ 'category_ref_unresolved', '10', 'reference_pending_import' ], array_slice( $rows[0], 5, 3 ) );
	}

	/**
	 * 同じ警告コードでも取込みとエクスポートで意味が違う（通貨の不一致は、取込みは保存し、エクスポートは送らない）。
	 */
	public function test_the_run_direction_selects_the_description(): void {
		$this->items->insert_many( 'run-dir', 1, [ $this->row( [ 'warnings' => [ 'currency_mismatch' ] ] ) ] );

		$import = $this->csv->rows( 'run-dir', null, false, WarningCatalog::IMPORT );
		$export = $this->csv->rows( 'run-dir', null, false, WarningCatalog::EXPORT );

		$this->assertNotSame( WarningCatalog::SEVERITY_BLOCKING, $import[0][8] );
		$this->assertSame( WarningCatalog::SEVERITY_BLOCKING, $export[0][8] );
		$this->assertNotSame( $import[0][9], $export[0][9] );
	}

	/**
	 * 同じ警告コードでも行の種別で結果が違う（管理者・スタッフと同じメールの顧客は、顧客を飛ばし、受注はゲストとして書く）。
	 */
	public function test_the_row_entity_selects_the_description(): void {
		$warning = 'customer_account_protected:c-1';

		$this->items->insert_many(
			'run-entity',
			1,
			[
				$this->row(
					[
						'entity'    => 'customer',
						'remote_id' => 'c-1',
						'operation' => 'skipped',
						'warnings'  => [ $warning ],
					]
				),
				$this->row(
					[
						'entity'    => 'order',
						'remote_id' => 'o-1',
						'warnings'  => [ $warning ],
					]
				),
			]
		);

		$rows = $this->csv->rows( 'run-entity', null, false, WarningCatalog::IMPORT );

		$this->assertSame( [ 'customer', 'order' ], array_column( $rows, 0 ) );
		$this->assertSame( WarningCatalog::SEVERITY_BLOCKING, $rows[0][8] );
		$this->assertSame( WarningCatalog::SEVERITY_ACTION_REQUIRED, $rows[1][8] );
		$this->assertSame( WarningCatalog::describe( $warning, WarningCatalog::IMPORT, 'order' )['message'], $rows[1][9] );
	}

	public function test_an_unknown_direction_describes_warnings_as_unknown(): void {
		$this->items->insert_many( 'run-nodir', 1, [ $this->row( [ 'warnings' => [ 'currency_mismatch' ] ] ) ] );

		$rows = $this->csv->rows( 'run-nodir', null, false, '' );

		$this->assertSame( WarningCatalog::SEVERITY_UNKNOWN, $rows[0][8] );
	}

	public function test_the_direction_follows_the_job_type(): void {
		$this->assertSame( WarningCatalog::IMPORT, DryRunReportCsv::direction_for_job_type( JobManager::TYPE_DRY_RUN ) );
		$this->assertSame( WarningCatalog::IMPORT, DryRunReportCsv::direction_for_job_type( JobManager::TYPE_IMPORT ) );
		$this->assertSame( WarningCatalog::EXPORT, DryRunReportCsv::direction_for_job_type( JobManager::TYPE_DRY_RUN_EXPORT ) );
		$this->assertSame( WarningCatalog::EXPORT, DryRunReportCsv::direction_for_job_type( JobManager::TYPE_EXPORT ) );
		$this->assertSame( '', DryRunReportCsv::direction_for_job_type( 'something_else' ) );
	}

	/**
	 * 説明の列も CSV インジェクション対策を通す（翻訳は外部の入力で、detail は ASP 由来の値を含む）。
	 */
	public function test_description_columns_are_hardened(): void {
		add_filter(
			'gettext',
			static function ( string $translation, string $text, string $domain ): string {
				return 'cart-bridge-jp' === $domain ? '=HYPERLINK("x")' : $translation;
			},
			10,
			3
		);

		$this->items->insert_many( 'run-harden', 1, [ $this->row( [ 'warnings' => [ 'sku_duplicate:SKU-1' ] ] ) ] );

		$rows = $this->csv->rows( 'run-harden', null, false, WarningCatalog::IMPORT );

		$this->assertSame( "'=HYPERLINK(\"x\")", $rows[0][9] );
	}

	public function test_stream_writes_a_bom_and_header_row(): void {
		$this->items->insert_many( 'run-10', 1, [ $this->row() ] );

		ob_start();
		$this->csv->stream( 'run-10', null, false, WarningCatalog::IMPORT );
		$output = ob_get_clean();

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $output );
		$this->assertStringContainsString( "entity,remote_id,label,operation,existing_local_id,warning_code,warning_detail,note,severity,message,action\n", $output );
		$this->assertStringContainsString( "product,p1,Widget,created,0,,,,,,\n", $output );
	}
}
