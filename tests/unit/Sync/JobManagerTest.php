<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
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
		AdapterRegistry::reset_cache();
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
			$writer
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
}
