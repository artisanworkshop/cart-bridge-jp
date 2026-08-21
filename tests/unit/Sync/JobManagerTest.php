<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\FixedWooWriterFactory;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\RunAlreadyInProgressException;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\InMemoryWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_UnitTestCase;

final class JobManagerTest extends WP_UnitTestCase {

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
		remove_all_filters( 'cbjp/limits/customer' );
		remove_all_filters( 'cbjp/limits/order' );
		remove_all_filters( 'cbjp/limits/stock' );
		AdapterRegistry::reset_cache();
		delete_option( 'cbjp_sample_mock' );
		parent::tear_down();
	}

	/**
	 * @param array<int,\CartBridgeJP\Canonical\CanonicalProduct>  $products
	 * @param array<int,\CartBridgeJP\Canonical\CanonicalCustomer> $customers
	 * @param array<int,\CartBridgeJP\Canonical\CanonicalOrder>    $orders
	 * @param array<int,\CartBridgeJP\Canonical\CanonicalCategory> $categories
	 */
	private function register_adapter( array $products = [], array $customers = [], array $orders = [], array $categories = [] ): MockPlatformAdapter {
		$adapter = new MockPlatformAdapter( $products, $customers, $orders, $categories );

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

	private function make_manager( InMemoryWriter $writer ): JobManager {
		return new JobManager(
			$this->jobs,
			new LimitPolicy( $this->mappings ),
			new Importer( $this->mappings ),
			new FixedWooWriterFactory( $writer )
		);
	}

	public function test_import_walks_all_pages_and_creates_mappings_for_unlimited_entity(): void {
		$categories = [
			CanonicalFactory::category( 'c1', 'Category 1' ),
			CanonicalFactory::category( 'c2', 'Category 2' ),
			CanonicalFactory::category( 'c3', 'Category 3' ),
		];
		$this->register_adapter( categories: $categories );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 3, $this->mappings->count( 'mock', 'category' ) );
		$this->assertCount( 3, $writer->writes );
	}

	public function test_dry_run_does_not_persist_mappings_or_call_the_real_writer(): void {
		$categories = [ CanonicalFactory::category( 'c1', 'Category 1' ) ];
		$this->register_adapter( categories: $categories );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'dry_run', 'mock', [ 'category' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'category' ) );
		$this->assertSame( [], $writer->writes );
	}

	public function test_run_across_multiple_pages_resumes_correctly(): void {
		// MockPlatformAdapterのページサイズは2件。5件投入して複数ページに跨がせる。
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1' ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
			CanonicalFactory::product( 'p4', 'SKU-4' ),
			CanonicalFactory::product( 'p5', 'SKU-5' ),
		];
		$this->register_adapter( products: $products );

		// Pro相当（上限解除）にして通常のカーソル全量取込パスを通す。
		add_filter( 'cbjp/limits/product', static fn() => null );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'product' ] );

		// 1ページ目だけ処理し、カーソルが保存されていることを確認する。
		$job = $this->jobs->find_next_incomplete_for_run( $run_id );
		$manager->process_job( (int) $job['id'] );

		$job_after_one_page = $this->jobs->find( (int) $job['id'] );
		$this->assertSame( JobRepository::STATUS_RUNNING, $job_after_one_page['status'] );
		$this->assertNotNull( $job_after_one_page['cursor_json'] );
		$this->assertSame( 2, $this->mappings->count( 'mock', 'product' ) );

		// 新しいJobManagerインスタンス（別のASワーカーを想定）で再開する。
		$resumed_manager = $this->make_manager( $writer );
		$resumed_manager->run_to_completion( $run_id );

		$this->assertSame( 5, $this->mappings->count( 'mock', 'product' ) );
		$this->assertCount( 5, $writer->writes );

		$final_job = $this->jobs->find( (int) $job['id'] );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $final_job['status'] );
	}

	public function test_totals_total_reflects_the_adapters_reported_count_not_a_sum_across_pages(): void {
		// MockPlatformAdapterのページサイズは2件。5件投入して3ページに跨がせ、
		// 各ページがtotal=5を報告する（ページ毎の単純合算だと15になってしまう）。
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1' ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
			CanonicalFactory::product( 'p4', 'SKU-4' ),
			CanonicalFactory::product( 'p5', 'SKU-5' ),
		];
		$this->register_adapter( products: $products );

		add_filter( 'cbjp/limits/product', static fn() => null );

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job    = $this->jobs->find_by_run( $run_id )[0];
		$totals = json_decode( (string) $job['totals_json'], true );
		$this->assertSame( 5, $totals['total'] );
	}

	public function test_totals_total_is_set_from_the_sample_size_for_id_fetch_entities(): void {
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1' ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
		];
		$orders   = [ CanonicalFactory::order( '1001', null, [ 'p1' ] ) ];
		$this->register_adapter( products: $products, orders: $orders );

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job    = $this->jobs->find_by_run( $run_id )[0];
		$totals = json_decode( (string) $job['totals_json'], true );
		// 最新注文由来のサンプルはp1のみ（1件）。
		$this->assertSame( 1, $totals['total'] );
	}

	public function test_totals_total_is_set_from_the_sample_size_for_stock_import(): void {
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1', 7 ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
		];
		$orders   = [ CanonicalFactory::order( '1001', null, [ 'p1' ] ) ];
		$this->register_adapter( products: $products, orders: $orders );

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'stock' ] );
		$manager->run_to_completion( $run_id );

		$job    = $this->jobs->find_by_run( $run_id )[0];
		$totals = json_decode( (string) $job['totals_json'], true );
		$this->assertSame( 1, $totals['total'] );
	}

	public function test_starting_a_second_run_while_one_is_in_progress_throws(): void {
		$this->register_adapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] );

		$manager = $this->make_manager( new InMemoryWriter() );
		$manager->start_run( 'import', 'mock', [ 'category' ] );

		$this->expectException( RunAlreadyInProgressException::class );
		$manager->start_run( 'import', 'mock', [ 'category' ] );
	}

	public function test_free_tier_caps_order_import_at_the_default_limit(): void {
		$orders = [];
		for ( $i = 1; $i <= 15; $i++ ) {
			$orders[] = CanonicalFactory::order( (string) ( 1000 + $i ), null, [] );
		}
		$this->register_adapter( orders: $orders );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		// order のデフォルト上限は10件（D15/§10.2）。
		$this->assertSame( 10, $this->mappings->count( 'mock', 'order' ) );
	}

	public function test_pro_filter_removes_the_order_limit(): void {
		$orders = [];
		for ( $i = 1; $i <= 15; $i++ ) {
			$orders[] = CanonicalFactory::order( (string) ( 1000 + $i ), null, [] );
		}
		$this->register_adapter( orders: $orders );

		add_filter( 'cbjp/limits/order', static fn() => null );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		$this->assertSame( 15, $this->mappings->count( 'mock', 'order' ) );
	}

	public function test_free_tier_product_import_is_restricted_to_the_order_derived_sample(): void {
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1' ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
		];
		// 最新注文（fetch_latest_ordersはordersの先頭からlimit件）はp1のみを参照する。
		$orders = [ CanonicalFactory::order( '1001', null, [ 'p1' ] ) ];

		$this->register_adapter( products: $products, orders: $orders );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ) );
		$this->assertNotNull( $this->mappings->find_local_id( 'mock', 'product', 'p1' ) );
	}

	public function test_free_tier_stock_import_derives_from_sample_products(): void {
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1', 7 ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
		];
		$orders   = [ CanonicalFactory::order( '1001', null, [ 'p1' ] ) ];

		$this->register_adapter( products: $products, orders: $orders );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'stock' ] );
		$manager->run_to_completion( $run_id );

		// §10.2 #4: 全量走査せず、サンプル商品（p1）分の在庫のみ書き込まれる。
		$this->assertSame( 1, $this->mappings->count( 'mock', 'stock' ) );
		$this->assertNotNull( $this->mappings->find_local_id( 'mock', 'stock', 'p1' ) );
	}

	public function test_pro_unlock_imports_all_stock_without_sample_filtering(): void {
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1' ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
		];
		$orders   = [ CanonicalFactory::order( '1001', null, [ 'p1' ] ) ];

		$this->register_adapter( products: $products, orders: $orders );

		// Pro相当: 商品上限の解除でサンプリング自体が無効になる。
		add_filter( 'cbjp/limits/product', static fn() => null );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'stock' ] );
		$manager->run_to_completion( $run_id );

		$this->assertSame( 3, $this->mappings->count( 'mock', 'stock' ) );
	}

	public function test_starting_a_second_run_while_a_job_is_paused_throws(): void {
		$adapter = new MockPlatformAdapter(
			categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ],
			fetch_failure: new RateLimitExhaustedException( 'mock' )
		);

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job    = $this->jobs->find_next_incomplete_for_run( $run_id );
		$manager->process_job( (int) $job['id'] );

		// running が存在しない瞬間（paused のみ）でも、進行中runとして新規runをブロックする。
		$this->assertSame( JobRepository::STATUS_PAUSED, $this->jobs->find( (int) $job['id'] )['status'] );

		$this->expectException( RunAlreadyInProgressException::class );
		$manager->start_run( 'import', 'mock', [ 'category' ] );
	}

	public function test_run_to_completion_returns_early_when_a_job_is_paused(): void {
		$adapter = new MockPlatformAdapter(
			categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ],
			fetch_failure: new RateLimitExhaustedException( 'mock' )
		);

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$manager->run_to_completion( $run_id );

		// paused → 即再実行のタイトループにならず、最初のpauseで停止すること。
		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_PAUSED, $job['status'] );
		$this->assertSame( 1, $adapter->fetch_calls );
	}

	public function test_rate_limit_exhaustion_pauses_the_job_instead_of_failing(): void {
		$adapter = new MockPlatformAdapter(
			categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ],
			fetch_failure: new RateLimitExhaustedException( 'mock' )
		);

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job    = $this->jobs->find_next_incomplete_for_run( $run_id );
		$manager->process_job( (int) $job['id'] );

		$paused_job = $this->jobs->find( (int) $job['id'] );
		$this->assertSame( JobRepository::STATUS_PAUSED, $paused_job['status'] );
	}

	public function test_rerun_skips_unchanged_items_via_checksum(): void {
		$categories = [
			CanonicalFactory::category( 'c1', 'Category 1' ),
			CanonicalFactory::category( 'c2', 'Category 2' ),
		];
		$this->register_adapter( categories: $categories );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$first_run = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$manager->run_to_completion( $first_run );
		$this->assertCount( 2, $writer->writes );

		$second_run = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$manager->run_to_completion( $second_run );

		// checksum一致（変更なし）のため書込みは増えない（03 §5 冪等性）。
		$this->assertCount( 2, $writer->writes );
		$this->assertSame( 2, $this->mappings->count( 'mock', 'category' ) );

		$second_job = $this->jobs->find_by_run( $second_run )[0];
		$totals     = json_decode( (string) $second_job['totals_json'], true );
		$this->assertSame( 2, $totals['skipped'] );
	}

	public function test_retry_requeues_a_failed_job(): void {
		$adapter = new MockPlatformAdapter(
			categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ],
			fetch_failure: new \RuntimeException( 'boom' )
		);

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job    = $this->jobs->find_next_incomplete_for_run( $run_id );
		$job_id = (int) $job['id'];
		$manager->process_job( $job_id );

		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $job_id )['status'] );

		$this->assertTrue( $manager->retry( $job_id ) );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->jobs->find( $job_id )['status'] );

		if ( function_exists( 'as_has_scheduled_action' ) ) {
			// 再エンキューされていること（retry_jobがpendingに戻すだけで放置しない）。
			$this->assertTrue( as_has_scheduled_action( JobManager::ACTION_HOOK, [ 'job_id' => $job_id ], 'cart-bridge-jp' ) );
		}
	}

	public function test_start_run_rejects_platforms_whose_adapter_does_not_implement_fetching_yet(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters[ ColorMeAdapter::ID ] = new ColorMeAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$manager = $this->make_manager( new InMemoryWriter() );

		$this->expectException( \RuntimeException::class );

		$manager->start_run( 'dry_run', ColorMeAdapter::ID, [ 'product' ] );
	}
}
