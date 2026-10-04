<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * `JobRepository::find_active_runs_for_platform()`（進行中 run の発見。R3-0i・issue #70）と、
 * 条件付きの状態遷移・処理中のアクションの判定（issue #57）。
 */
final class JobRepositoryTest extends WP_UnitTestCase {

	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->jobs = new JobRepository();
	}

	/**
	 * @param array<int,array{0:string,1:string}> $entities_and_statuses
	 * @return array<int,int> 作成したジョブの id（引数の順）。
	 */
	private function create_run( string $run_id, string $type, string $platform, array $entities_and_statuses ): array {
		$ids = [];

		foreach ( $entities_and_statuses as [ $entity, $status ] ) {
			$id = $this->jobs->create( $run_id, $type, $platform, $entity );

			if ( JobRepository::STATUS_FAILED === $status ) {
				$this->jobs->mark_failed(
					$id,
					[
						'code'    => 'exception',
						'message' => 'boom',
					]
				);
			} elseif ( JobRepository::STATUS_PENDING !== $status ) {
				$this->jobs->update_status( $id, $status );
			}

			$ids[] = $id;
		}

		return $ids;
	}

	private function set_timestamps( int $id, string $created_at, string $updated_at ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'cbjp_jobs',
			[
				'created_at' => $created_at,
				'updated_at' => $updated_at,
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public function test_returns_nothing_when_every_job_has_finished(): void {
		$this->create_run(
			'run-done',
			'import',
			'mock',
			[
				[ 'product', JobRepository::STATUS_COMPLETED ],
				[ 'customer', JobRepository::STATUS_FAILED ],
				[ 'order', JobRepository::STATUS_CANCELLED ],
			]
		);

		$this->assertSame( [], $this->jobs->find_active_runs_for_platform( 'mock' ) );
	}

	public function test_summarizes_each_active_run_from_all_of_its_jobs(): void {
		$this->create_run(
			'run-a',
			'dry_run',
			'mock',
			[
				[ 'product', JobRepository::STATUS_COMPLETED ],
				[ 'customer', JobRepository::STATUS_RUNNING ],
				[ 'order', JobRepository::STATUS_PENDING ],
			]
		);

		$runs = $this->jobs->find_active_runs_for_platform( 'mock' );

		$this->assertCount( 1, $runs );
		$this->assertSame( 'run-a', $runs[0]['run_id'] );
		$this->assertSame( 'dry_run', $runs[0]['type'] );
		$this->assertSame( JobRepository::STATUS_RUNNING, $runs[0]['status'] );
		// 終了済みのジョブ（product）のエンティティも含める。
		$this->assertSame( [ 'product', 'customer', 'order' ], $runs[0]['entities'] );
		$this->assertFalse( $runs[0]['has_failed_job'] );
	}

	/**
	 * @return array<string,array{0:array<int,string>,1:string}>
	 */
	public static function status_precedence_provider(): array {
		return [
			'running wins over paused and pending' => [ [ JobRepository::STATUS_PENDING, JobRepository::STATUS_PAUSED, JobRepository::STATUS_RUNNING ], JobRepository::STATUS_RUNNING ],
			'paused wins over pending'             => [ [ JobRepository::STATUS_PENDING, JobRepository::STATUS_PAUSED ], JobRepository::STATUS_PAUSED ],
			'pending alone'                        => [ [ JobRepository::STATUS_COMPLETED, JobRepository::STATUS_PENDING ], JobRepository::STATUS_PENDING ],
		];
	}

	/**
	 * @dataProvider status_precedence_provider
	 *
	 * @param array<int,string> $statuses
	 */
	public function test_run_status_follows_running_then_paused_then_pending( array $statuses, string $expected ): void {
		$entities = [ 'category', 'product', 'customer' ];
		$jobs     = [];

		foreach ( $statuses as $index => $status ) {
			$jobs[] = [ $entities[ $index ], $status ];
		}

		$this->create_run( 'run-s', 'import', 'mock', $jobs );

		$runs = $this->jobs->find_active_runs_for_platform( 'mock' );

		$this->assertCount( 1, $runs );
		$this->assertSame( $expected, $runs[0]['status'] );
	}

	public function test_a_run_stalled_by_a_failed_job_is_active_and_flagged(): void {
		// `start_run()` は全エンティティのジョブを pending で作るため、最初のジョブが失敗すると
		// 兄弟ジョブが pending のまま残り、プラットフォームを塞ぎ続ける（issue #70 の発見対象）。
		$this->create_run(
			'run-stalled',
			'import',
			'mock',
			[
				[ 'product', JobRepository::STATUS_FAILED ],
				[ 'customer', JobRepository::STATUS_PENDING ],
			]
		);

		$runs = $this->jobs->find_active_runs_for_platform( 'mock' );

		$this->assertCount( 1, $runs );
		$this->assertSame( JobRepository::STATUS_PENDING, $runs[0]['status'] );
		$this->assertTrue( $runs[0]['has_failed_job'] );
		$this->assertSame( [ 'product', 'customer' ], $runs[0]['entities'] );
	}

	public function test_ignores_other_platforms_and_the_excluded_run(): void {
		$this->create_run( 'run-other-platform', 'import', 'other', [ [ 'product', JobRepository::STATUS_RUNNING ] ] );
		$this->create_run( 'run-own', 'import', 'mock', [ [ 'product', JobRepository::STATUS_FAILED ], [ 'customer', JobRepository::STATUS_PENDING ] ] );
		$this->create_run( 'run-b', 'export', 'mock', [ [ 'product', JobRepository::STATUS_RUNNING ] ] );

		$this->assertSame( [ 'run-own', 'run-b' ], array_column( $this->jobs->find_active_runs_for_platform( 'mock' ), 'run_id' ) );
		$this->assertSame( [ 'run-b' ], array_column( $this->jobs->find_active_runs_for_platform( 'mock', 'run-own' ), 'run_id' ) );
		$this->assertSame( [ 'run-other-platform' ], array_column( $this->jobs->find_active_runs_for_platform( 'other' ), 'run_id' ) );
	}

	public function test_lists_runs_oldest_first_with_the_earliest_and_latest_timestamps(): void {
		$first  = $this->create_run( 'run-first', 'import', 'mock', [ [ 'product', JobRepository::STATUS_RUNNING ], [ 'customer', JobRepository::STATUS_PENDING ] ] );
		$second = $this->create_run( 'run-second', 'dry_run', 'mock', [ [ 'product', JobRepository::STATUS_PENDING ] ] );

		$this->set_timestamps( $first[0], '2026-10-01 09:00:00', '2026-10-01 09:30:00' );
		$this->set_timestamps( $first[1], '2026-10-01 09:00:01', '2026-10-01 09:10:00' );
		$this->set_timestamps( $second[0], '2026-10-01 08:00:00', '2026-10-01 08:00:00' );

		$runs = $this->jobs->find_active_runs_for_platform( 'mock' );

		// 並びは作成時刻ではなくジョブの登録順（id）。
		$this->assertSame( [ 'run-first', 'run-second' ], array_column( $runs, 'run_id' ) );
		$this->assertSame( '2026-10-01 09:00:00', $runs[0]['created_at'] );
		$this->assertSame( '2026-10-01 09:30:00', $runs[0]['updated_at'] );
	}

	public function test_transition_changes_the_status_only_from_the_expected_states(): void {
		$id = $this->jobs->create( 'run-t', 'import', 'mock', 'product' );

		$this->assertTrue( $this->jobs->transition( $id, [ JobRepository::STATUS_PENDING ], JobRepository::STATUS_RUNNING ) );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->jobs->find( $id )['status'] );

		// 2 回目は今の状態（running）が期待した状態（pending）と違うので何もしない（二重 Retry・二重起動の 2 本目）。
		$this->assertFalse( $this->jobs->transition( $id, [ JobRepository::STATUS_PENDING ], JobRepository::STATUS_RUNNING ) );

		$this->assertTrue( $this->jobs->transition( $id, [ JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING ], JobRepository::STATUS_COMPLETED ) );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $this->jobs->find( $id )['status'] );
	}

	public function test_transition_does_not_overwrite_a_cancelled_job(): void {
		[ $id ] = $this->create_run( 'run-c', 'import', 'mock', [ [ 'product', JobRepository::STATUS_CANCELLED ] ] );

		$this->assertFalse( $this->jobs->transition( $id, [ JobRepository::STATUS_RUNNING ], JobRepository::STATUS_COMPLETED ) );
		$this->assertSame( JobRepository::STATUS_CANCELLED, $this->jobs->find( $id )['status'] );
	}

	/**
	 * @return array<string,array{0:list<string>,1:string}>
	 */
	public static function invalid_transitions(): array {
		return [
			'no source state'                => [ [], JobRepository::STATUS_RUNNING ],
			'target among the source states' => [ [ JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING ], JobRepository::STATUS_RUNNING ],
		];
	}

	/**
	 * @dataProvider invalid_transitions
	 * @param list<string> $from
	 */
	public function test_transition_rejects_source_states_that_include_the_target( array $from, string $to ): void {
		$id = $this->jobs->create( 'run-i', 'import', 'mock', 'product' );

		$this->expectException( InvalidArgumentException::class );
		$this->jobs->transition( $id, $from, $to );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function mark_failed_cases(): array {
		return [
			'pending'   => [ JobRepository::STATUS_PENDING, true ],
			'running'   => [ JobRepository::STATUS_RUNNING, true ],
			'paused'    => [ JobRepository::STATUS_PAUSED, true ],
			'cancelled' => [ JobRepository::STATUS_CANCELLED, false ],
			'completed' => [ JobRepository::STATUS_COMPLETED, false ],
		];
	}

	/**
	 * @dataProvider mark_failed_cases
	 */
	public function test_mark_failed_only_records_a_failure_on_an_unfinished_job( string $status, bool $recorded ): void {
		[ $id ] = $this->create_run( 'run-f', 'import', 'mock', [ [ 'product', $status ] ] );

		$result = $this->jobs->mark_failed(
			$id,
			[
				'code'    => 'exception',
				'message' => 'boom',
			]
		);

		$job = $this->jobs->find( $id );
		$this->assertSame( $recorded, $result );
		$this->assertSame( $recorded ? JobRepository::STATUS_FAILED : $status, $job['status'] );
		$this->assertSame( $recorded, null !== $job['error_json'] );
	}

	public function test_cancel_run_cancels_only_the_unfinished_jobs_of_the_run(): void {
		$ids   = $this->create_run(
			'run-x',
			'import',
			'mock',
			[
				[ 'category', JobRepository::STATUS_COMPLETED ],
				[ 'tag', JobRepository::STATUS_FAILED ],
				[ 'product', JobRepository::STATUS_RUNNING ],
				[ 'customer', JobRepository::STATUS_PAUSED ],
				[ 'order', JobRepository::STATUS_PENDING ],
			]
		);
		$other = $this->create_run( 'run-y', 'import', 'mock', [ [ 'product', JobRepository::STATUS_RUNNING ] ] );

		$this->assertSame( 3, $this->jobs->cancel_run( 'run-x' ) );

		$this->assertSame(
			[
				JobRepository::STATUS_COMPLETED,
				JobRepository::STATUS_FAILED,
				JobRepository::STATUS_CANCELLED,
				JobRepository::STATUS_CANCELLED,
				JobRepository::STATUS_CANCELLED,
			],
			array_map( fn ( int $id ): string => $this->jobs->find( $id )['status'], $ids )
		);
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->jobs->find( $other[0] )['status'] );
	}

	/**
	 * Action Scheduler の `process_job()` のアクションを作り、実行中（in-progress）にする。
	 */
	private function start_action_for( int $job_id ): int {
		$action_id = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $job_id ], JobManager::ACTION_GROUP );
		\ActionScheduler::store()->log_execution( $action_id );

		return $action_id;
	}

	/**
	 * キャンセル済みのジョブでも、処理中のページを書き終えるまでは同時実行の判定で「進行中」に数える。
	 */
	public function test_a_cancelled_job_whose_page_is_still_being_processed_keeps_the_platform_busy(): void {
		[ $id ] = $this->create_run( 'run-a', 'import', 'mock', [ [ 'product', JobRepository::STATUS_CANCELLED ] ] );

		$this->assertFalse( $this->jobs->is_platform_busy( 'mock' ) );

		$action_id = $this->start_action_for( $id );

		$this->assertFalse( $this->jobs->has_active_job_for_platform( 'mock' ) );
		$this->assertTrue( $this->jobs->has_in_flight_job_for_platform( 'mock' ) );
		$this->assertTrue( $this->jobs->is_platform_busy( 'mock' ) );
		// 処理中のアクションの判定には run の除外を適用しない（retry の対象ジョブ自身の run でも書込み中は待つ）。
		$this->assertTrue( $this->jobs->is_platform_busy( 'mock', 'run-a' ) );

		\ActionScheduler::store()->mark_complete( $action_id );

		$this->assertFalse( $this->jobs->is_platform_busy( 'mock' ) );
	}

	public function test_in_flight_ignores_queued_actions_and_other_platforms(): void {
		[ $queued ]    = $this->create_run( 'run-q', 'import', 'mock', [ [ 'product', JobRepository::STATUS_CANCELLED ] ] );
		[ $elsewhere ] = $this->create_run( 'run-e', 'import', 'other', [ [ 'product', JobRepository::STATUS_CANCELLED ] ] );

		as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $queued ], JobManager::ACTION_GROUP );
		$this->start_action_for( $elsewhere );

		$this->assertFalse( $this->jobs->has_in_flight_job_for_platform( 'mock' ) );
		$this->assertTrue( $this->jobs->has_in_flight_job_for_platform( 'other' ) );
	}

	public function test_is_platform_busy_excludes_the_given_run_from_the_active_jobs(): void {
		$this->create_run( 'run-a', 'import', 'mock', [ [ 'product', JobRepository::STATUS_PENDING ] ] );

		$this->assertTrue( $this->jobs->is_platform_busy( 'mock' ) );
		$this->assertFalse( $this->jobs->is_platform_busy( 'mock', 'run-a' ) );
		$this->assertTrue( $this->jobs->is_platform_busy( 'mock', 'run-b' ) );
	}
}
