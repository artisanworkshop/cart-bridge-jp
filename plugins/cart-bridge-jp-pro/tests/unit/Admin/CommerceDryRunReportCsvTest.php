<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Admin;

use CartBridgeJP\Admin\DryRunReportCsv;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Woo\WarningCatalog;
use WP_UnitTestCase;

/**
 * 顧客・受注・クーポンの警告の dry-run の CSV の注記と説明（R3-6c1 で無料版の `DryRunReportCsvTest` から分けた）。
 */
final class CommerceDryRunReportCsvTest extends WP_UnitTestCase {

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
}
