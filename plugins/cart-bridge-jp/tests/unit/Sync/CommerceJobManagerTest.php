<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\FixedWooWriterFactory;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\InMemoryWriter;
use CartBridgeJP\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersCommerceAdapters;
use WP_UnitTestCase;

/**
 * 顧客・受注の取込みを無料版の `JobManager` で通す（R3-6c1 で `JobManagerTest` から分けた）。種類は `CommerceAdapters` から接続先の
 * `CommerceAdapter` を引き、無料版のカーソル走査で全ページを取り込む。
 */
final class CommerceJobManagerTest extends WP_UnitTestCase {

	use RegistersCommerceAdapters;

	private JobRepository $jobs;
	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->jobs     = new JobRepository();
		$this->mappings = new MappingRepository();

		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->forget_commerce_adapters();
		parent::tear_down();
		$this->forget_commerce_adapters();
	}

	public function test_import_walks_customers_and_orders_across_pages(): void {
		$this->register_commerce_adapter(
			new MockCommerceAdapter(
				customers: array_map( static fn ( int $n ) => CanonicalFactory::customer( "c{$n}", "c{$n}@example.com" ), range( 1, 12 ) ),
				orders: array_map( static fn ( int $n ) => CanonicalFactory::order( (string) ( 1000 + $n ), "c{$n}", [ 'p1' ] ), range( 1, 12 ) )
			)
		);

		$manager = new JobManager( $this->jobs, new Importer( $this->mappings ), new FixedWooWriterFactory( new InMemoryWriter() ) );
		$run_id  = $manager->start_run( 'import', 'mock', [ 'product', 'customer', 'order' ] );
		$manager->run_to_completion( $run_id );

		foreach ( $this->jobs->find_by_run( $run_id ) as $job ) {
			$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		}

		$this->assertSame( [ 'product', 'customer', 'order' ], array_column( $this->jobs->find_by_run( $run_id ), 'entity' ) );
		$this->assertSame( 12, $this->mappings->count( 'mock', 'customer' ) );
		$this->assertSame( 12, $this->mappings->count( 'mock', 'order' ) );
	}

	/**
	 * 接続先に `CommerceAdapter` が無いと、顧客・受注・クーポンは選んでもジョブにならない（無料版の商品系だけになる）。
	 */
	public function test_a_platform_without_a_commerce_adapter_runs_only_the_free_entities(): void {
		$manager = new JobManager( $this->jobs, new Importer( $this->mappings ), new FixedWooWriterFactory( new InMemoryWriter() ) );
		$run_id  = $manager->start_run( 'import', 'mock', [ 'product', 'customer', 'order', 'coupon' ] );

		$this->assertSame( [ 'product' ], array_column( $this->jobs->find_by_run( $run_id ), 'entity' ) );
	}
}
