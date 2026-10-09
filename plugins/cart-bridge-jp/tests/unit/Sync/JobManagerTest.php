<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\FixedWooWriterFactory;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
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

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job    = $this->jobs->find_by_run( $run_id )[0];
		$totals = json_decode( (string) $job['totals_json'], true );
		$this->assertSame( 5, $totals['total'] );
	}

	public function test_starting_a_second_run_while_one_is_in_progress_throws(): void {
		$this->register_adapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] );

		$manager = $this->make_manager( new InMemoryWriter() );
		$manager->start_run( 'import', 'mock', [ 'category' ] );

		$this->expectException( RunAlreadyInProgressException::class );
		$manager->start_run( 'import', 'mock', [ 'category' ] );
	}

	/**
	 * D27（R3-6a）: 無料版に件数の上限は無い。D15 の上限（商品 50・顧客 10・受注 10）を超える件数を、
	 * カーソル走査で全件取り込む（サンプルの選定・ID 指定取得の経路は無い）。
	 */
	public function test_import_has_no_count_limit(): void {
		$products  = array_map( static fn ( int $n ) => CanonicalFactory::product( "p{$n}", "SKU-{$n}" ), range( 1, 55 ) );
		$customers = array_map( static fn ( int $n ) => CanonicalFactory::customer( "c{$n}", "c{$n}@example.com" ), range( 1, 12 ) );
		$orders    = array_map( static fn ( int $n ) => CanonicalFactory::order( (string) ( 1000 + $n ), "c{$n}", [ "p{$n}" ] ), range( 1, 12 ) );
		$this->register_adapter( $products, $customers, $orders );

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );

		$run_id = $manager->start_run( 'import', 'mock', [ 'product', 'customer', 'order' ] );
		$manager->run_to_completion( $run_id );

		foreach ( $this->jobs->find_by_run( $run_id ) as $job ) {
			$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		}

		$this->assertSame( 55, $this->mappings->count( 'mock', 'product' ) );
		$this->assertSame( 12, $this->mappings->count( 'mock', 'customer' ) );
		$this->assertSame( 12, $this->mappings->count( 'mock', 'order' ) );
	}

	/**
	 * 旧版の上限のフィルター（`cbjp/limits/{entity}`）は何も絞らない。ガイドライン 5 のため、上限を外すためだけの
	 * フィルターを無料版に置かない（D27）。上限を再び足す退行を検出する。
	 */
	public function test_former_limit_filters_do_not_restrict_imports(): void {
		foreach ( [ 'product', 'customer', 'order', 'stock' ] as $entity ) {
			add_filter( "cbjp/limits/{$entity}", static fn () => 1 );
		}

		$products  = array_map( static fn ( int $n ) => CanonicalFactory::product( "p{$n}", "SKU-{$n}" ), range( 1, 3 ) );
		$customers = array_map( static fn ( int $n ) => CanonicalFactory::customer( "c{$n}", "c{$n}@example.com" ), range( 1, 3 ) );
		$orders    = array_map( static fn ( int $n ) => CanonicalFactory::order( (string) ( 1000 + $n ), null, [ "p{$n}" ] ), range( 1, 3 ) );
		$this->register_adapter( $products, $customers, $orders );

		$manager = $this->make_manager( new InMemoryWriter() );

		$run_id = $manager->start_run( 'import', 'mock', [ 'product', 'customer', 'order', 'stock' ] );
		$manager->run_to_completion( $run_id );

		$this->assertSame( 3, $this->mappings->count( 'mock', 'product' ) );
		$this->assertSame( 3, $this->mappings->count( 'mock', 'customer' ) );
		$this->assertSame( 3, $this->mappings->count( 'mock', 'order' ) );
		$this->assertSame( 3, $this->mappings->count( 'mock', 'stock' ) );
	}

	public function test_stock_import_walks_all_products(): void {
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1' ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
		];
		$orders   = [ CanonicalFactory::order( '1001', null, [ 'p1' ] ) ];

		$this->register_adapter( products: $products, orders: $orders );

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
		$this->assertSame( 2, $totals['unchanged'] );
	}

	/**
	 * issue #55: `unchanged`はページをまたいで累積され、ジョブの`totals_json`に載る（Pro 案内が
	 * dry-runの`created + updated + unchanged`を「移行できる件数」として読む）。
	 */
	public function test_unchanged_accumulates_across_pages_in_a_dry_run(): void {
		// MockPlatformAdapterのページサイズは2件。5件で3ページに跨がせる。
		$products = [
			CanonicalFactory::product( 'p1', 'SKU-1' ),
			CanonicalFactory::product( 'p2', 'SKU-2' ),
			CanonicalFactory::product( 'p3', 'SKU-3' ),
			CanonicalFactory::product( 'p4', 'SKU-4' ),
			CanonicalFactory::product( 'p5', 'SKU-5' ),
		];
		$this->register_adapter( products: $products );

		// 先に全件を実インポートし、checksumをキャッシュさせる。

		$writer  = new InMemoryWriter();
		$manager = $this->make_manager( $writer );
		$manager->run_to_completion( $manager->start_run( 'import', 'mock', [ 'product' ] ) );

		$dry_run = $manager->start_run( 'dry_run', 'mock', [ 'product' ] );
		$manager->run_to_completion( $dry_run );

		$totals = json_decode( (string) $this->jobs->find_by_run( $dry_run )[0]['totals_json'], true );
		$this->assertSame( 5, $totals['processed'] );
		$this->assertSame( 5, $totals['unchanged'] );
		$this->assertSame( 5, $totals['skipped'] );
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

	/**
	 * `fetch_categories()`のみ失敗させ`fetch_tags()`は常に空配列を返す（`MockPlatformAdapter`が
	 * `fetch_failure`をtagには適用しない）ことを利用し、同一run内で1件目（category）が失敗し
	 * 2件目（tag）が未処理のまま`pending`で残る状態を作る。この兄弟ジョブは`retry()`の
	 * ガード（issue #54）に誤検知されないこと（同一run_idは判定から除外される）を確認する。
	 */
	public function test_retry_succeeds_when_only_sibling_jobs_of_the_same_run_are_pending(): void {
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

		$run_id      = $manager->start_run( 'import', 'mock', [ 'category', 'tag' ] );
		$run_jobs    = $this->jobs->find_by_run( $run_id );
		$category_id = (int) $run_jobs[0]['id'];
		$tag_id      = (int) $run_jobs[1]['id'];

		$manager->process_job( $category_id );

		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $category_id )['status'] );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->jobs->find( $tag_id )['status'] );

		$this->assertTrue( $manager->retry( $category_id ) );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->jobs->find( $category_id )['status'] );
	}

	public function test_retry_throws_when_a_different_run_is_active_for_the_platform(): void {
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

		$run_a = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job_a = (int) $this->jobs->find_by_run( $run_a )[0]['id'];
		$manager->process_job( $job_a );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $job_a )['status'] );

		// run Aの唯一のジョブが失敗してterminalになったため、同プラットフォームで新規run Bを
		// 開始できる（`start_run()`の同時実行ガードには引っかからない）。run Bの先頭ジョブは
		// `running`のまま（`process_job()`を呼ばず未処理）にしておき、「別runが進行中」を再現する。
		$manager->start_run( 'import', 'mock', [ 'category' ] );

		$this->expectException( RunAlreadyInProgressException::class );
		$manager->retry( $job_a );
	}
}
