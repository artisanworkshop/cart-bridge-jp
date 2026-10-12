<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\PlatformLock;
use CartBridgeJP\Sync\FixedWooWriterFactory;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\JobHeartbeat;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\OrphanedRunRecovery;
use CartBridgeJP\Sync\RunAlreadyInProgressException;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\InMemoryWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use Closure;
use RuntimeException;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * `JobManager` の孤立した run の回復の入口・Retry の残ったアクションの取消し・処理中のハートビート（R3-9・issue #121）。
 */
final class JobManagerRecoveryTest extends WP_UnitTestCase {

	private const OLD = '2020-01-01 00:00:00';

	private JobRepository $jobs;

	private MappingRepository $mappings;

	private int $now;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		// tests サイトの実行で残ったコミット済みのアクションが、このテストのジョブを「生きている」に見せないように。
		as_unschedule_all_actions( JobManager::ACTION_HOOK );

		$this->jobs     = new JobRepository();
		$this->mappings = new MappingRepository();
		$this->now      = time();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		PlatformLock::release_all();
		parent::tear_down();
	}

	private function register_adapter( MockPlatformAdapter $adapter ): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
	}

	private function make_manager( ?WooWriter $writer = null ): JobManager {
		return new JobManager(
			$this->jobs,
			new Importer( $this->mappings ),
			new FixedWooWriterFactory( $writer ?? new InMemoryWriter() ),
			clock: fn (): int => $this->now
		);
	}

	/**
	 * Action Scheduler のアクションを持たない、最後の活動が猶予より古い run（停止中にアクションを失った run）を作る。
	 *
	 * @param array<int,array{0:string,1:string}> $entities_and_statuses
	 * @return array<int,int>
	 */
	private function create_orphaned_run( string $run_id, array $entities_and_statuses ): array {
		global $wpdb;

		$ids = [];

		foreach ( $entities_and_statuses as [ $entity, $status ] ) {
			$id = $this->jobs->create( $run_id, 'import', 'mock', $entity );

			if ( JobRepository::STATUS_PENDING !== $status ) {
				$this->jobs->update_status( $id, $status );
			}

			$wpdb->update(
				$wpdb->prefix . 'cbjp_jobs',
				[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - OrphanedRunRecovery::GRACE_SECONDS - 120 ) ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);
			$ids[] = $id;
		}

		return $ids;
	}

	private function queued_actions_for( int $job_id ): int {
		return count(
			as_get_scheduled_actions(
				[
					'hook'     => JobManager::ACTION_HOOK,
					'args'     => [ 'job_id' => $job_id ],
					'group'    => JobManager::ACTION_GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			)
		);
	}

	private function make_old( int $job_id ): void {
		global $wpdb;

		$wpdb->update( $wpdb->prefix . 'cbjp_jobs', [ 'updated_at' => self::OLD ], [ 'id' => $job_id ], [ '%s' ], [ '%d' ] );
	}

	private function touched( int $job_id ): bool {
		return self::OLD !== $this->jobs->find( $job_id )['updated_at'];
	}

	/**
	 * 停止中にアクションを失った run があっても、新しい run を始められる（その run は action_lost で失敗になり、Retry できる）。
	 */
	public function test_start_run_recovers_an_orphaned_run_and_starts(): void {
		$this->register_adapter( new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] ) );
		[ $orphan ] = $this->create_orphaned_run( 'run-orphan', [ [ 'category', JobRepository::STATUS_RUNNING ] ] );

		$run_id = $this->make_manager()->start_run( 'import', 'mock', [ 'category' ] );

		$this->assertNotSame( 'run-orphan', $run_id );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $orphan )['status'] );
		$this->assertSame( OrphanedRunRecovery::ERROR_ACTION_LOST, json_decode( (string) $this->jobs->find( $orphan )['error_json'], true )['code'] ?? null );
	}

	/**
	 * 古くても生きたアクションがある run（キューが動いていないだけ）は回復せず、従来どおり拒否して何も変えない。
	 */
	public function test_start_run_still_refuses_a_stale_run_with_a_queued_action(): void {
		$this->register_adapter( new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] ) );
		[ $waiting ] = $this->create_orphaned_run( 'run-waiting', [ [ 'category', JobRepository::STATUS_RUNNING ] ] );
		as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $waiting ], JobManager::ACTION_GROUP );

		try {
			$this->make_manager()->start_run( 'import', 'mock', [ 'category' ] );
			$this->fail( 'RunAlreadyInProgressException should be thrown.' );
		} catch ( RunAlreadyInProgressException ) {
			$this->assertSame( JobRepository::STATUS_RUNNING, $this->jobs->find( $waiting )['status'] );
			$this->assertSame( [ $waiting ], array_map( 'intval', array_column( $this->jobs->find_by_run( 'run-waiting' ), 'id' ) ) );
		}
	}

	/**
	 * 別の run がアクションを失っていても Retry できる（その run を先に失敗にする）。
	 */
	public function test_retry_recovers_an_orphaned_run_of_another_run_and_retries(): void {
		$this->register_adapter( new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] ) );
		$manager = $this->make_manager();
		$run_id  = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job_id  = (int) $this->jobs->find_by_run( $run_id )[0]['id'];
		$this->jobs->mark_failed(
			$job_id,
			[
				'code'    => 'exception',
				'message' => 'boom',
			]
		);
		as_unschedule_all_actions( JobManager::ACTION_HOOK );
		[ $orphan ] = $this->create_orphaned_run( 'run-orphan', [ [ 'category', JobRepository::STATUS_RUNNING ] ] );

		$this->assertTrue( $manager->retry( $job_id ) );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->jobs->find( $job_id )['status'] );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $orphan )['status'] );
	}

	/**
	 * Retry は、失敗していた間に残った pending のアクションを取り消してから 1 本だけ積む（2 本の連鎖で同じジョブを進めない）。
	 */
	public function test_retry_cancels_leftover_queued_actions_and_queues_one(): void {
		$this->register_adapter( new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] ) );
		$manager = $this->make_manager();
		$job_id  = (int) $this->jobs->find_by_run( $manager->start_run( 'import', 'mock', [ 'category' ] ) )[0]['id'];
		$this->jobs->mark_failed(
			$job_id,
			[
				'code'    => 'action_lost',
				'message' => 'lost',
			]
		);
		as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $job_id ], JobManager::ACTION_GROUP );
		$this->assertSame( 2, $this->queued_actions_for( $job_id ) );

		$this->assertTrue( $manager->retry( $job_id ) );

		$this->assertSame( 1, $this->queued_actions_for( $job_id ) );
	}

	/**
	 * 取込みのページは、開始時・アイテムごと・HTTP の応答ごとにハートビートを打ち、終われば HTTP のフックを外す。
	 */
	public function test_an_import_page_beats_at_the_start_per_item_and_per_http_response(): void {
		$observed = [];
		$job_id   = 0;
		$this->register_adapter(
			new MockPlatformAdapter(
				categories: [
					CanonicalFactory::category( 'c1', 'Category 1' ),
					CanonicalFactory::category( 'c2', 'Category 2' ),
				],
				on_fetch: function () use ( &$observed, &$job_id ): void {
					$observed['start'] = $this->touched( $job_id );
				}
			)
		);
		$writer  = new class( function ( int $index ) use ( &$observed, &$job_id ): void {
			if ( 0 === $index ) {
				$this->make_old( $job_id );
				$this->now += JobHeartbeat::INTERVAL_SECONDS;
				do_action( 'http_api_debug', [], 'response', 'Requests', [], 'https://example.com/' );
				$observed['http'] = $this->touched( $job_id );

				$this->make_old( $job_id );
				$this->now += JobHeartbeat::INTERVAL_SECONDS;

				return;
			}

			$observed['item'] = $this->touched( $job_id );
		} ) implements WooWriter {
			private int $calls = 0;

			public function __construct( private readonly Closure $on_write ) {}

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				( $this->on_write )( $this->calls++ );

				return new WriteResult( 100 + $this->calls, WriteResult::OPERATION_CREATED );
			}
		};
		$manager = $this->make_manager( $writer );
		$job_id  = (int) $this->jobs->find_by_run( $manager->start_run( 'import', 'mock', [ 'category' ] ) )[0]['id'];
		$this->make_old( $job_id );
		$had_http_hook = has_action( 'http_api_debug' );

		$manager->process_job( $job_id );

		$this->assertSame(
			[
				'start' => true,
				'http'  => true,
				'item'  => true,
			],
			$observed
		);
		$this->assertSame( $had_http_hook, has_action( 'http_api_debug' ) );
	}

	/**
	 * ページが例外で失敗しても、HTTP のフックを外す。
	 */
	public function test_the_http_hook_is_removed_when_the_page_fails(): void {
		$this->register_adapter( new MockPlatformAdapter( fetch_failure: new RuntimeException( 'boom' ) ) );
		$manager       = $this->make_manager();
		$job_id        = (int) $this->jobs->find_by_run( $manager->start_run( 'import', 'mock', [ 'category' ] ) )[0]['id'];
		$had_http_hook = has_action( 'http_api_debug' );

		$manager->process_job( $job_id );

		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $job_id )['status'] );
		$this->assertSame( $had_http_hook, has_action( 'http_api_debug' ) );
	}

	/**
	 * エクスポートのページもアイテムごとにハートビートを打つ。
	 */
	public function test_an_export_page_beats_per_item(): void {
		$observed = [];
		$job_id   = 0;
		$pushes   = 0;
		$this->register_adapter(
			new MockPlatformAdapter(
				push_products_supported: true,
				on_push_product: function () use ( &$observed, &$job_id, &$pushes ): void {
					if ( 0 === $pushes++ ) {
						$this->make_old( $job_id );
						$this->now += JobHeartbeat::INTERVAL_SECONDS;

						return;
					}

					$observed[] = $this->touched( $job_id );
				}
			)
		);

		foreach ( [ 'A', 'B' ] as $suffix ) {
			$product = new WC_Product_Simple();
			$product->set_name( "Product {$suffix}" );
			$product->set_sku( "SKU-{$suffix}" );
			$product->set_regular_price( '1000' );
			$product->save();
		}

		$manager = $this->make_manager();
		$job_id  = (int) $this->jobs->find_by_run( $manager->start_run( 'export', 'mock', [ 'product' ] ) )[0]['id'];

		$manager->process_job( $job_id );

		$this->assertSame( 2, $pushes );
		$this->assertSame( [ true ], $observed );
	}
}
