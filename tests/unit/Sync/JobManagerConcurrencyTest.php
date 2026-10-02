<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\PlatformBusyException;
use CartBridgeJP\Support\PlatformLock;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\FixedWooWriterFactory;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\RunAlreadyInProgressException;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WooWriterFactory;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\InMemoryWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use RuntimeException;
use Throwable;
use WP_UnitTestCase;

/**
 * 同時実行の判定とロック、条件付きの状態遷移（R3-0i・issue #57）。
 *
 * 2 つの要求が本当に同時に走る状況はユニットテストでは作れない（テストのトランザクションは別の接続から
 * 見えない）。「判定と状態変更の間に別の要求が割り込んだ」状況は、`query` フィルターで特定の UPDATE の直前に
 * 割り込みの操作を実行して作る。
 */
final class JobManagerConcurrencyTest extends WP_UnitTestCase {

	private JobRepository $jobs;
	private MappingRepository $mappings;
	private MockPlatformAdapter $adapter;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->jobs     = new JobRepository();
		$this->mappings = new MappingRepository();
		$this->adapter  = new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] );

		$adapter = $this->adapter;
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
		remove_all_filters( 'query' );
		AdapterRegistry::reset_cache();
		PlatformLock::release_all();
		parent::tear_down();
	}

	private function make_manager( ?WooWriterFactory $factory = null ): JobManager {
		return new JobManager(
			$this->jobs,
			new LimitPolicy( $this->mappings ),
			new Importer( $this->mappings ),
			$factory ?? new FixedWooWriterFactory( new InMemoryWriter() )
		);
	}

	/**
	 * `$needle` を含む SQL が実行される直前に 1 回だけ `$interrupt` を実行する（別の要求の割り込み）。
	 */
	private function before_query( string $needle, callable $interrupt ): void {
		$filter = null;
		$filter = static function ( string $query ) use ( $needle, $interrupt, &$filter ): string {
			if ( str_contains( $query, $needle ) ) {
				remove_filter( 'query', $filter );
				$interrupt();
			}

			return $query;
		};
		add_filter( 'query', $filter );
	}

	/**
	 * このジョブの `process_job()` のアクションのうち、まだ実行されていないものの数。
	 */
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

	private function fail_first_job( JobManager $manager, string $run_id ): int {
		$job_id = (int) $this->jobs->find_by_run( $run_id )[0]['id'];
		$this->jobs->mark_failed(
			$job_id,
			[
				'code'    => 'exception',
				'message' => 'boom',
			]
		);

		return $job_id;
	}

	public function test_start_run_is_refused_without_creating_jobs_while_the_platform_lock_is_held(): void {
		( new PlatformLock() )->acquire( 'mock', PlatformLock::TTL_SHORT );

		try {
			$this->make_manager()->start_run( 'import', 'mock', [ 'category' ] );
			$this->fail( 'PlatformBusyException should be thrown.' );
		} catch ( PlatformBusyException $exception ) {
			$this->assertSame( 'mock', $exception->platform() );
		}

		$this->assertSame( [], $this->jobs->find_active_runs_for_platform( 'mock' ) );
	}

	public function test_start_run_releases_the_platform_lock(): void {
		$this->make_manager()->start_run( 'import', 'mock', [ 'category' ] );

		$this->assertNotNull( ( new PlatformLock() )->acquire( 'mock', PlatformLock::TTL_SHORT ) );
	}

	/**
	 * キャンセルした run は、処理中のページを書き終えるまで新しい run の開始を止める。
	 */
	public function test_start_run_is_refused_while_a_cancelled_run_is_still_writing_a_page(): void {
		$manager = $this->make_manager();
		$run_id  = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job_id  = (int) $this->jobs->find_by_run( $run_id )[0]['id'];
		$this->jobs->cancel_run( $run_id );

		$actions = as_get_scheduled_actions(
			[
				'hook'  => JobManager::ACTION_HOOK,
				'args'  => [ 'job_id' => $job_id ],
				'group' => JobManager::ACTION_GROUP,
			],
			'ids'
		);
		\ActionScheduler::store()->log_execution( (int) reset( $actions ) );

		$this->expectException( RunAlreadyInProgressException::class );
		$manager->start_run( 'import', 'mock', [ 'category' ] );
	}

	/**
	 * 作成した直後（先頭のジョブを始める前）にキャンセルされた run は始めない。
	 */
	public function test_start_run_does_not_start_a_run_cancelled_right_after_it_was_created(): void {
		$run_id = null;
		$this->before_query(
			"SET status = 'running'",
			function () use ( &$run_id ): void {
				global $wpdb;

				$run_id = (string) $wpdb->get_var( "SELECT run_id FROM {$wpdb->prefix}cbjp_jobs ORDER BY id DESC LIMIT 1" );
				$this->jobs->cancel_run( $run_id );
			}
		);

		$returned = $this->make_manager()->start_run( 'import', 'mock', [ 'category' ] );
		$job      = $this->jobs->find_by_run( $returned )[0];

		$this->assertSame( $returned, $run_id );
		$this->assertSame( JobRepository::STATUS_CANCELLED, $job['status'] );
		$this->assertSame( 0, $this->queued_actions_for( (int) $job['id'] ) );
	}

	public function test_retry_is_refused_while_the_platform_lock_is_held(): void {
		$manager = $this->make_manager();
		$job_id  = $this->fail_first_job( $manager, $manager->start_run( 'import', 'mock', [ 'category' ] ) );
		( new PlatformLock() )->acquire( 'mock', PlatformLock::TTL_SHORT );

		try {
			$manager->retry( $job_id );
			$this->fail( 'PlatformBusyException should be thrown.' );
		} catch ( PlatformBusyException ) {
			$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $job_id )['status'] );
		}
	}

	/**
	 * 同じ失敗ジョブへの Retry が 2 つほぼ同時に届き、相手が先に `failed → pending` にした場合、
	 * こちらは false を返してエンキューしない（同じページを 2 回 ASP へ書かない）。
	 */
	public function test_retry_loses_to_a_concurrent_retry_of_the_same_job_without_enqueueing(): void {
		$manager = $this->make_manager();
		$job_id  = $this->fail_first_job( $manager, $manager->start_run( 'import', 'mock', [ 'category' ] ) );
		$queued  = $this->queued_actions_for( $job_id );

		$this->before_query(
			"SET status = 'pending'",
			function () use ( $job_id ): void {
				$this->jobs->transition( $job_id, [ JobRepository::STATUS_FAILED ], JobRepository::STATUS_PENDING );
			}
		);

		$this->assertFalse( $manager->retry( $job_id ) );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->jobs->find( $job_id )['status'] );
		$this->assertSame( $queued, $this->queued_actions_for( $job_id ) );
	}

	/**
	 * ページの処理中にキャンセルされたジョブは `completed` で上書きせず、次のジョブも始めない
	 * （`f1-6-import-ui/R1-X1`）。そのページで書いた件数は残す。
	 */
	public function test_a_job_cancelled_while_its_page_is_written_stays_cancelled(): void {
		$run_id  = '';
		$jobs    = $this->jobs;
		$writer  = new class( static function () use ( &$run_id, $jobs ): void {
			$jobs->cancel_run( $run_id );
		} ) implements WooWriter {

			private bool $interrupted = false;

			/**
			 * @param \Closure():void $on_first_write
			 */
			public function __construct( private readonly \Closure $on_first_write ) {}

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				if ( ! $this->interrupted ) {
					$this->interrupted = true;
					( $this->on_first_write )();
				}

				return new WriteResult( 1, WriteResult::OPERATION_CREATED );
			}
		};
		$manager = $this->make_manager( new FixedWooWriterFactory( $writer ) );

		$run_id                 = $manager->start_run( 'import', 'mock', [ 'category', 'tag' ] );
		[ $category_job, $tag ] = $this->jobs->find_by_run( $run_id );
		$manager->process_job( (int) $category_job['id'] );

		$category_job = $this->jobs->find( (int) $category_job['id'] );
		$this->assertSame( JobRepository::STATUS_CANCELLED, $category_job['status'] );
		$this->assertSame( 1, json_decode( (string) $category_job['totals_json'], true )['processed'] );
		$this->assertSame( JobRepository::STATUS_CANCELLED, $this->jobs->find( (int) $tag['id'] )['status'] );
		$this->assertSame( 0, $this->queued_actions_for( (int) $tag['id'] ) );
	}

	/**
	 * 次のジョブが既に `running`（別のアクションが扱っている）なら、もう 1 本エンキューしない。
	 */
	public function test_advancing_does_not_enqueue_a_next_job_that_is_already_running(): void {
		$manager                = $this->make_manager();
		$run_id                 = $manager->start_run( 'import', 'mock', [ 'category', 'tag' ] );
		[ $category_job, $tag ] = $this->jobs->find_by_run( $run_id );
		$this->jobs->update_status( (int) $tag['id'], JobRepository::STATUS_RUNNING );

		$manager->process_job( (int) $category_job['id'] );

		$this->assertSame( JobRepository::STATUS_COMPLETED, $this->jobs->find( (int) $category_job['id'] )['status'] );
		$this->assertSame( 0, $this->queued_actions_for( (int) $tag['id'] ) );
	}

	/**
	 * 読んでから `running` にするまでの間にキャンセルされた paused のジョブは、処理しない。
	 */
	public function test_a_paused_job_cancelled_before_it_resumes_is_not_processed(): void {
		$manager = $this->make_manager();
		$run_id  = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job_id  = (int) $this->jobs->find_by_run( $run_id )[0]['id'];
		$this->jobs->update_status( $job_id, JobRepository::STATUS_PAUSED );

		$this->before_query(
			"SET status = 'running'",
			function () use ( $run_id ): void {
				$this->jobs->cancel_run( $run_id );
			}
		);
		$manager->process_job( $job_id );

		$this->assertSame( JobRepository::STATUS_CANCELLED, $this->jobs->find( $job_id )['status'] );
		$this->assertSame( 0, $this->adapter->fetch_calls );
	}

	/**
	 * @return array<string,array{0:Throwable,1:string}>
	 */
	public static function page_failures(): array {
		return [
			'rate limit exhausted' => [ new RateLimitExhaustedException( 'mock' ), 'paused' ],
			'exception'            => [ new RuntimeException( 'boom' ), 'failed' ],
		];
	}

	/**
	 * ページの処理中にキャンセルされた後で、レート制限の枯渇・例外で終わっても、`paused`/`failed` で上書きせず、
	 * 再開のエンキューもしない。
	 *
	 * @dataProvider page_failures
	 */
	public function test_a_job_cancelled_before_its_page_fails_stays_cancelled( Throwable $failure, string $overwritten_status ): void {
		$run_id  = '';
		$jobs    = $this->jobs;
		$factory = new class( static function () use ( &$run_id, $jobs ): void {
			$jobs->cancel_run( $run_id );
		}, $failure ) implements WooWriterFactory {

			/**
			 * @param \Closure():void $interrupt
			 */
			public function __construct( private readonly \Closure $interrupt, private readonly Throwable $failure ) {}

			public function for_platform( string $platform ): WooWriter {
				( $this->interrupt )();

				throw $this->failure;
			}

			public function for_dry_run( string $platform ): WooWriter {
				return $this->for_platform( $platform );
			}
		};
		$manager = $this->make_manager( $factory );
		$run_id  = $manager->start_run( 'import', 'mock', [ 'category' ] );
		$job_id  = (int) $this->jobs->find_by_run( $run_id )[0]['id'];
		$queued  = $this->queued_actions_for( $job_id );

		$manager->process_job( $job_id );

		$job = $this->jobs->find( $job_id );
		$this->assertNotSame( $overwritten_status, $job['status'] );
		$this->assertSame( JobRepository::STATUS_CANCELLED, $job['status'] );
		$this->assertNull( $job['error_json'] );
		$this->assertSame( $queued, $this->queued_actions_for( $job_id ) );
	}
}
