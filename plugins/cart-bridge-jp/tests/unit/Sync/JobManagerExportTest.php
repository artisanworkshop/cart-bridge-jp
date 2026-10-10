<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * `JobManager`のexport方向配線（Step 5-9で追加した`TYPE_EXPORT`/`TYPE_DRY_RUN_EXPORT`分岐）を
 * 実配線（`AdapterPlatformWriterFactory`/`WooReaderRepositoryFactory`/`MockPlatformAdapter`の
 * push成功モード + 実WC商品）で検証する。`Sync\Exporter`自体のオーケストレーションロジック
 * （checksum一致・push intent等）は`ExporterTest`が実writer/readerを介さずに検証済み。
 */
final class JobManagerExportTest extends WP_UnitTestCase {

	private JobRepository $jobs;
	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->jobs     = new JobRepository();
		$this->mappings = new MappingRepository();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		remove_all_filters( 'cbjp/limits/product' );
		remove_all_filters( 'cbjp/limits/stock' );
		AdapterRegistry::reset_cache();
		parent::tear_down();
	}

	private function register_adapter( bool $push_products_supported = true, bool $push_stocks_supported = false ): MockPlatformAdapter {
		$adapter = new MockPlatformAdapter( push_products_supported: $push_products_supported, push_stocks_supported: $push_stocks_supported );

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		return $adapter;
	}

	private function create_product( string $name, string $sku ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( '1000' );

		return $product->save();
	}

	public function test_export_run_pushes_products_and_creates_mappings(): void {
		$this->register_adapter();
		$this->create_product( 'Product A', 'SKU-A' );
		$this->create_product( 'Product B', 'SKU-B' );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 2, $this->mappings->count( 'mock', 'product' ) );
	}

	public function test_export_excludes_entities_without_an_implemented_reader(): void {
		$this->register_adapter();
		$this->create_product( 'Product A', 'SKU-A' );

		$manager = JobManager::create();
		// `category`/`tag`/`review`はReaderを持たない設計（`docs/03-design-decisions.md` §10.2:
		// カテゴリは独立したexportエンティティにせず`category_map`で解決する。tag/reviewは
		// `PlatformAdapter`に対応するpush_*()自体が存在しない）ため、要求してもproductだけが
		// ジョブになる（`JobManager::EXPORT_ENTITIES_WITH_READER`）。
		$run_id = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'category', 'tag', 'review', 'product' ] );

		$jobs = $this->jobs->find_by_run( $run_id );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'product', $jobs[0]['entity'] );
	}


	public function test_dry_run_export_records_items_without_persisting_mappings_or_pushing(): void {
		$adapter = $this->register_adapter( push_products_supported: false );
		$this->create_product( 'Product A', 'SKU-A' );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_DRY_RUN_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
		$this->assertSame( 0, count( $adapter->pushed_products ), 'dry-runはpush_product()を一切呼ばない' );
		$this->assertSame( 1, ( new DryRunItemRepository() )->count_for_run( $run_id, 'product' ) );
	}

	/**
	 * ColorMeのようにpush_*が`UnsupportedOperationException`を投げるアダプタでも、
	 * ジョブが失敗せず完了し、該当アイテムがskipped/warnedとして記録されること
	 * （PR-AのE2-2完了条件: E2-3のColorMe push実装を待たずに配線が正しく動く）。
	 */
	public function test_export_with_unsupported_push_completes_with_items_skipped(): void {
		$this->register_adapter( push_products_supported: false );
		$this->create_product( 'Product A', 'SKU-A' );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job    = $this->jobs->find_by_run( $run_id )[0];
		$totals = json_decode( (string) $job['totals_json'], true );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $totals['skipped'] );
		$this->assertSame( 1, $totals['warned'] );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
	}

	/**
	 * D27（R3-6a）: 無料版に件数の上限は無い。D15 の上限（商品 50）を超える商品を、受注に含まれるかによらず全件送る。
	 */
	public function test_export_has_no_count_limit(): void {
		$adapter = $this->register_adapter();

		for ( $i = 1; $i <= 51; $i++ ) {
			$this->create_product( "Product {$i}", "SKU-{$i}" );
		}

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertCount( 51, $adapter->pushed_products );
		$this->assertSame( 51, $this->mappings->count( 'mock', 'product' ) );
	}

	/**
	 * 旧版の上限のフィルター（`cbjp/limits/{entity}`）は何も絞らない（D27。上限を再び足す退行を検出する）。
	 */
	public function test_former_limit_filters_do_not_restrict_exports(): void {
		foreach ( [ 'product', 'stock' ] as $entity ) {
			add_filter( "cbjp/limits/{$entity}", static fn () => 1 );
		}

		$adapter = $this->register_adapter( push_stocks_supported: true );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->create_product( "Product {$i}", "SKU-{$i}" );
		}

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product', 'stock' ] );
		$manager->run_to_completion( $run_id );

		$this->assertCount( 3, $adapter->pushed_products );
		$this->assertCount( 3, $adapter->pushed_stocks );
	}



	public function test_export_pushes_stock_when_supported(): void {
		$adapter    = $this->register_adapter( push_stocks_supported: true );
		$product_id = $this->create_product( 'Product A', 'SKU-A' );
		$this->mappings->upsert( 'mock', 'product', 'p-1', $product_id, null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'stock' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertCount( 1, $adapter->pushed_stocks );
		$this->assertSame( 'p-1', $adapter->pushed_stocks[0]->product_ref );
	}


	/**
	 * 在庫は送った商品すべての分を送る（D15 では受注のサンプルに含まれる商品の分だけだった）。
	 */
	public function test_stock_export_covers_all_exported_products(): void {
		$adapter    = $this->register_adapter( push_stocks_supported: true );
		$ordered_id = $this->create_product( 'Ordered', 'SKU-ORDERED' );
		$other_id   = $this->create_product( 'Other', 'SKU-OTHER' );

		// 両商品とも既にexport済み（mapping有り）という前提にし、mapping未整備による
		// STOCK_PRODUCT_NOT_EXPORTEDブロックと混同しないようにする。
		$this->mappings->upsert( 'mock', 'product', 'p-ordered', $ordered_id, null );
		$this->mappings->upsert( 'mock', 'product', 'p-other', $other_id, null );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $ordered_id ), 1 );
		$order->calculate_totals();
		$order->save();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'stock' ] );
		$manager->run_to_completion( $run_id );

		$pushed_refs = array_map( static fn ( $stock ) => $stock->product_ref, $adapter->pushed_stocks );
		sort( $pushed_refs );
		$this->assertSame( [ 'p-ordered', 'p-other' ], $pushed_refs );
	}
}
