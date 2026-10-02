<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\JobRepository;
use WP_UnitTestCase;

/**
 * `JobRepository::find_active_runs_for_platform()`（進行中 run の発見。R3-0i・issue #70）。
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
}
