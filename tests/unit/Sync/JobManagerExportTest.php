<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\ExportSampleSelector;
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
 * （checksum一致・quota等）は`ExporterTest`が実writer/readerを介さずに検証済み。
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
		AdapterRegistry::reset_cache();
		ExportSampleSelector::clear( 'mock' );
		parent::tear_down();
	}

	private function register_adapter( bool $push_products_supported = true ): MockPlatformAdapter {
		$adapter = new MockPlatformAdapter( push_products_supported: $push_products_supported );

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

		// Pro相当（上限解除）でサンプリングを無効にし、通常のカーソル全量走査パスを通す。
		add_filter( 'cbjp/limits/product', static fn () => null );

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

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		// PR-A時点では customer/category にReaderが無いため、要求してもproductだけが
		// ジョブになる（`JobManager::EXPORT_ENTITIES_WITH_READER`）。
		$run_id = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'category', 'customer', 'product' ] );

		$jobs = $this->jobs->find_by_run( $run_id );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'product', $jobs[0]['entity'] );
	}

	public function test_dry_run_export_records_items_without_persisting_mappings_or_pushing(): void {
		$adapter = $this->register_adapter( push_products_supported: false );
		$this->create_product( 'Product A', 'SKU-A' );

		add_filter( 'cbjp/limits/product', static fn () => null );

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

		add_filter( 'cbjp/limits/product', static fn () => null );

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

	public function test_free_tier_export_sample_is_restricted_to_products_in_the_latest_orders(): void {
		$this->register_adapter();
		$sampled_id   = $this->create_product( 'Sampled', 'SKU-SAMPLED' );
		$unsampled_id = $this->create_product( 'Unsampled', 'SKU-UNSAMPLED' );

		// ちょうど10件（`ExportSampleSelector::SAMPLE_ORDER_LIMIT`）の受注を用意し、
		// §10.2 #5後半のフォールバック補完（受注10件未満の場合に通常一覧の先頭ページから
		// 残り枠を補完する）が発生しないようにする。補完が入ると`$unsampled_id`も
		// サンプルに含まれてしまい、このテストの検証対象（受注に紐づく商品のみへの制限）が
		// 別の挙動と混ざってしまうため。
		for ( $i = 0; $i < 10; $i++ ) {
			$order = wc_create_order();
			$order->add_product( wc_get_product( $sampled_id ), 1 );
			$order->calculate_totals();
			$order->save();
		}

		$manager = JobManager::create();
		// 無料版の上限（デフォルト50件）はそのまま = サンプリング有効。
		$run_id = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ) );
		$this->assertNotNull( $this->mappings->find_remote_id( 'mock', 'product', $sampled_id ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', $unsampled_id ) );
	}
}
