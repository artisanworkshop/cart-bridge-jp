<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\OrphanedRunRecovery;
use WP_UnitTestCase;

/**
 * Action Scheduler のアクションを失って進まなくなった run の回復（R3-9・issue #121）と、致命的エラーでのジョブの失敗。
 */
final class OrphanedRunRecoveryTest extends WP_UnitTestCase {

	private JobRepository $jobs;

	private OrphanedRunRecovery $recovery;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		// tests サイトの実行で残ったコミット済みのアクションが、このテストのジョブを「生きている」に見せないように（テストの中で取り消すので、終われば戻る）。
		as_unschedule_all_actions( JobManager::ACTION_HOOK );

		$this->jobs     = new JobRepository();
		$this->recovery = new OrphanedRunRecovery( $this->jobs );
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		parent::tear_down();
	}

	/**
	 * @param array<int,array{0:string,1:string}> $entities_and_statuses
	 * @param int                                 $age_seconds 全ジョブの `updated_at` を今からこれだけ前にする。
	 * @return array<int,int>
	 */
	private function create_run( string $run_id, string $platform, array $entities_and_statuses, int $age_seconds ): array {
		$ids = [];

		foreach ( $entities_and_statuses as [ $entity, $status ] ) {
			$id = $this->jobs->create( $run_id, 'import', $platform, $entity );

			if ( JobRepository::STATUS_PENDING !== $status ) {
				$this->jobs->update_status( $id, $status );
			}

			$this->set_updated_at( $id, $age_seconds );
			$ids[] = $id;
		}

		return $ids;
	}

	private function set_updated_at( int $id, int $age_seconds ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'cbjp_jobs',
			[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ) ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	private function stale(): int {
		return OrphanedRunRecovery::GRACE_SECONDS + 120;
	}

	private function fresh(): int {
		return OrphanedRunRecovery::GRACE_SECONDS - 120;
	}

	private function status( int $id ): string {
		return (string) $this->jobs->find( $id )['status'];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function error( int $id ): ?array {
		$json = $this->jobs->find( $id )['error_json'];

		return null === $json ? null : json_decode( (string) $json, true );
	}

	public function test_a_stale_running_job_without_actions_fails_and_its_siblings_stay_pending(): void {
		[ $running, $pending ] = $this->create_run(
			'run-a',
			'mock',
			[
				[ 'category', JobRepository::STATUS_RUNNING ],
				[ 'product', JobRepository::STATUS_PENDING ],
			],
			$this->stale()
		);

		$this->assertSame( 1, $this->recovery->recover( 'mock' ) );

		$this->assertSame( JobRepository::STATUS_FAILED, $this->status( $running ) );
		$this->assertSame( OrphanedRunRecovery::ERROR_ACTION_LOST, $this->error( $running )['code'] ?? null );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->status( $pending ) );
		$this->assertFalse( $this->jobs->is_platform_busy( 'mock', 'run-a' ) );
	}

	/**
	 * レート制限で一時停止していたジョブ（遅延のアクションを失った）と、Retry で pending に戻したジョブ（アクションを失った）も回復する。
	 * 失敗にするのは run の最初の未終了のジョブ。
	 */
	public function test_a_paused_job_and_a_retried_pending_job_are_recovered(): void {
		[ $paused ]                        = $this->create_run( 'run-p', 'mock', [ [ 'category', JobRepository::STATUS_PAUSED ] ], $this->stale() );
		[ $completed, $retried, $waiting ] = $this->create_run(
			'run-r',
			'mock',
			[
				[ 'category', JobRepository::STATUS_COMPLETED ],
				[ 'product', JobRepository::STATUS_PENDING ],
				[ 'stock', JobRepository::STATUS_PENDING ],
			],
			$this->stale()
		);

		$this->assertSame( 2, $this->recovery->recover( 'mock' ) );

		$this->assertSame( JobRepository::STATUS_FAILED, $this->status( $paused ) );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $this->status( $completed ) );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->status( $retried ) );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->status( $waiting ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function live_action_cases(): array {
		return [
			'queued action of the job'            => [ 'queued-self' ],
			'in-progress action of the job'       => [ 'running-self' ],
			'in-progress action of a sibling'     => [ 'running-sibling' ],
			'queued action of a finished sibling' => [ 'queued-sibling' ],
		];
	}

	/**
	 * run のどのジョブにも生きたアクションがあれば回復しない（前のジョブのアクションが in-progress のまま次のジョブを始める
	 * `complete_job_and_advance()` の区間を含む）。
	 *
	 * @dataProvider live_action_cases
	 */
	public function test_a_run_with_a_live_action_on_any_job_is_left_alone( string $live_action ): void {
		[ $completed, $running ] = $this->create_run(
			'run-l',
			'mock',
			[
				[ 'category', JobRepository::STATUS_COMPLETED ],
				[ 'product', JobRepository::STATUS_RUNNING ],
			],
			$this->stale()
		);

		$target    = str_ends_with( $live_action, '-self' ) ? $running : $completed;
		$action_id = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $target ], JobManager::ACTION_GROUP );

		if ( str_starts_with( $live_action, 'running-' ) ) {
			\ActionScheduler::store()->log_execution( $action_id );
		}

		$this->assertSame( 0, $this->recovery->recover( 'mock' ) );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->status( $running ) );
	}

	/**
	 * 最後の活動が猶予の内なら回復しない。run の判定は全ジョブの最新の `updated_at`（直前に終わった兄弟を含む）で行う。
	 */
	public function test_a_run_active_within_the_grace_period_is_left_alone(): void {
		[ $recent ] = $this->create_run( 'run-f', 'mock', [ [ 'category', JobRepository::STATUS_RUNNING ] ], $this->fresh() );

		[ $just_completed, $next ] = $this->create_run(
			'run-n',
			'mock',
			[
				[ 'category', JobRepository::STATUS_COMPLETED ],
				[ 'product', JobRepository::STATUS_RUNNING ],
			],
			$this->stale()
		);
		$this->set_updated_at( $just_completed, $this->fresh() );

		$this->assertSame( 0, $this->recovery->recover( 'mock' ) );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->status( $recent ) );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->status( $next ) );
	}

	/**
	 * 失敗したジョブを持つ run（兄弟が pending のまま止まった、設計どおりの状態）と、別のプラットフォームの run には触れない。
	 */
	public function test_runs_with_a_failed_job_and_runs_on_other_platforms_are_left_alone(): void {
		[ $failed, $pending ] = $this->create_run(
			'run-x',
			'mock',
			[
				[ 'category', JobRepository::STATUS_FAILED ],
				[ 'product', JobRepository::STATUS_PENDING ],
			],
			$this->stale()
		);
		[ $elsewhere ]        = $this->create_run( 'run-o', 'other', [ [ 'category', JobRepository::STATUS_RUNNING ] ], $this->stale() );

		$this->assertSame( 0, $this->recovery->recover( 'mock' ) );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->status( $failed ) );
		$this->assertNull( $this->error( $failed ) );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->status( $pending ) );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->status( $elsewhere ) );
	}

	/**
	 * Action Scheduler の照会が失敗したら、アクションが無いと確かめられないので何もしない（フェイルクローズ）。
	 */
	public function test_nothing_is_recovered_when_the_action_query_fails(): void {
		[ $running ] = $this->create_run( 'run-q', 'mock', [ [ 'category', JobRepository::STATUS_RUNNING ] ], $this->stale() );

		add_filter(
			'query',
			static function ( string $query ): string {
				return str_starts_with( $query, 'SELECT a.action_id FROM' ) ? 'SELECT cbjp_no_such_column FROM cbjp_no_such_table' : $query;
			}
		);
		$suppress = $GLOBALS['wpdb']->suppress_errors( true );

		try {
			$this->assertSame( 0, $this->recovery->recover( 'mock' ) );
		} finally {
			$GLOBALS['wpdb']->suppress_errors( $suppress );
			remove_all_filters( 'query' );
		}

		$this->assertSame( JobRepository::STATUS_RUNNING, $this->status( $running ) );
	}

	/**
	 * 判定と書換えの間にハートビート（ページの処理が続いていた）が入れば、書き換えずに負ける。
	 */
	public function test_a_job_touched_between_the_check_and_the_write_is_not_failed(): void {
		[ $running ] = $this->create_run( 'run-c', 'mock', [ [ 'category', JobRepository::STATUS_RUNNING ] ], $this->stale() );

		$filter = null;
		$filter = function ( string $query ) use ( $running, &$filter ): string {
			if ( str_contains( $query, 'AND updated_at <=' ) ) {
				remove_filter( 'query', $filter );
				$this->jobs->touch( $running );
			}

			return $query;
		};
		add_filter( 'query', $filter );

		$this->assertSame( 0, $this->recovery->recover( 'mock' ) );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->status( $running ) );
	}

	/**
	 * @param array<string,mixed> $error
	 */
	private function simulate_fatal_error( int $action_id, array $error ): void {
		do_action( 'action_scheduler_unexpected_shutdown', $action_id, $error );
	}

	/**
	 * Action Scheduler が PHP の致命的エラーでアクションを失敗にしたら（`action_scheduler_unexpected_shutdown`。`Plugin::boot()` が登録する）、
	 * 猶予を待たずにそのジョブを失敗にする。message はエラーの 1 行目で、WordPress のディレクトリを外す。
	 */
	public function test_a_fatal_error_fails_the_job_of_the_action_with_the_first_line_of_the_error(): void {
		[ $running, $pending ] = $this->create_run(
			'run-fatal',
			'mock',
			[
				[ 'category', JobRepository::STATUS_RUNNING ],
				[ 'product', JobRepository::STATUS_PENDING ],
			],
			0
		);
		$action_id             = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $running ], JobManager::ACTION_GROUP );

		$this->simulate_fatal_error(
			$action_id,
			[
				'type'    => E_ERROR,
				'message' => 'Uncaught Error: Call to undefined function cbjp_missing() in ' . ABSPATH . "wp-content/plugins/example/example.php:12\nStack trace:\n#0 {main}",
				'file'    => ABSPATH . 'wp-content/plugins/example/example.php',
				'line'    => 12,
			]
		);

		$this->assertSame( JobRepository::STATUS_FAILED, $this->status( $running ) );
		$this->assertSame(
			[
				'code'    => OrphanedRunRecovery::ERROR_FATAL,
				'message' => 'Uncaught Error: Call to undefined function cbjp_missing() in wp-content/plugins/example/example.php:12',
			],
			$this->error( $running )
		);
		$this->assertSame( JobRepository::STATUS_PENDING, $this->status( $pending ) );
	}

	public function test_a_long_fatal_error_message_is_cut(): void {
		[ $running ] = $this->create_run( 'run-long', 'mock', [ [ 'category', JobRepository::STATUS_RUNNING ] ], 0 );
		$action_id   = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $running ], JobManager::ACTION_GROUP );

		$this->assertTrue(
			$this->recovery->fail_after_fatal_error(
				$action_id,
				[
					'type'    => E_ERROR,
					'message' => str_repeat( 'あ', 500 ),
				]
			)
		);

		$this->assertSame( str_repeat( 'あ', 300 ), $this->error( $running )['message'] ?? null );
	}

	/**
	 * 別の hook・group のアクション、終了したジョブ、読めない値には触れない。
	 */
	public function test_a_fatal_error_ignores_other_actions_finished_jobs_and_unreadable_values(): void {
		[ $running, $completed ] = $this->create_run(
			'run-other',
			'mock',
			[
				[ 'category', JobRepository::STATUS_RUNNING ],
				[ 'product', JobRepository::STATUS_COMPLETED ],
			],
			0
		);
		$error                   = [
			'type'    => E_ERROR,
			'message' => 'Allowed memory size exhausted',
		];

		$other_hook  = as_enqueue_async_action( 'cbjp_other_hook', [ 'job_id' => $running ], JobManager::ACTION_GROUP );
		$other_group = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $running ], 'another-plugin' );
		$string_id   = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => (string) $running ], JobManager::ACTION_GROUP );
		$finished    = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $completed ], JobManager::ACTION_GROUP );

		$this->assertFalse( $this->recovery->fail_after_fatal_error( $other_hook, $error ) );
		$this->assertFalse( $this->recovery->fail_after_fatal_error( $other_group, $error ) );
		$this->assertFalse( $this->recovery->fail_after_fatal_error( $string_id, $error ) );
		$this->assertFalse( $this->recovery->fail_after_fatal_error( $finished, $error ) );
		$this->assertFalse( $this->recovery->fail_after_fatal_error( 'not-an-id', $error ) );
		$this->assertFalse( $this->recovery->fail_after_fatal_error( 999999999, $error ) );

		$this->assertSame( JobRepository::STATUS_RUNNING, $this->status( $running ) );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $this->status( $completed ) );
	}

	/**
	 * エラーの値が読めなくても、ジョブは失敗にする（message は空）。
	 */
	public function test_a_fatal_error_without_a_readable_message_still_fails_the_job(): void {
		[ $running ] = $this->create_run( 'run-unreadable', 'mock', [ [ 'category', JobRepository::STATUS_RUNNING ] ], 0 );
		$action_id   = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $running ], JobManager::ACTION_GROUP );

		$this->assertTrue( $this->recovery->fail_after_fatal_error( (string) $action_id, new \stdClass() ) );

		$this->assertSame(
			[
				'code'    => OrphanedRunRecovery::ERROR_FATAL,
				'message' => '',
			],
			$this->error( $running )
		);
	}
}
