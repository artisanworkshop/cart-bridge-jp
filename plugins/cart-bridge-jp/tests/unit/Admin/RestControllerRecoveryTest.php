<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\PlatformLock;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\OrphanedRunRecovery;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * REST の入口での孤立した run の回復（R3-9・issue #121）: 画面が照会する `GET /runs` と、ロックで囲むツール（`run_exclusively()`）。
 */
final class RestControllerRecoveryTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		// tests サイトの実行で残ったコミット済みのアクションが、このテストのジョブを「生きている」に見せないように。
		as_unschedule_all_actions( JobManager::ACTION_HOOK );

		remove_all_filters( 'cbjp/adapters/register' );
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->jobs = new JobRepository();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		PlatformLock::release_all();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		parent::tear_down();
	}

	/**
	 * run（先頭のジョブが running、残りは pending）を、Action Scheduler のアクションを持たず、最後の活動が `$age_seconds` 前の状態で作る。
	 *
	 * @param array<int,string> $entities
	 * @return array<int,int>
	 */
	private function create_run( string $run_id, int $age_seconds, array $entities = [ 'category' ] ): array {
		global $wpdb;

		$ids = [];

		foreach ( $entities as $entity ) {
			$id = $this->jobs->create( $run_id, 'import', 'mock', $entity );

			if ( [] === $ids ) {
				$this->jobs->update_status( $id, JobRepository::STATUS_RUNNING );
			}

			$wpdb->update(
				$wpdb->prefix . 'cbjp_jobs',
				[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ) ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);
			$ids[] = $id;
		}

		return $ids;
	}

	private function list_runs(): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/cbjp/v1/runs' );
		$request->set_query_params( [ 'platform' => 'mock' ] );

		return $this->server->dispatch( $request );
	}

	private function rebuild_mappings(): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/rebuild-mappings' );
		$request->set_body_params( [ 'platform' => 'mock' ] );

		return $this->server->dispatch( $request );
	}

	/**
	 * 画面が照会する進行中の run の一覧は、アクションを失った run を失敗にしてから返す（Retry・キャンセルの対象として出る）。
	 */
	public function test_listing_runs_fails_an_orphaned_run_first(): void {
		[ $job_id, $sibling ] = $this->create_run( 'run-orphan', OrphanedRunRecovery::GRACE_SECONDS + 120, [ 'category', 'product' ] );

		$response = $this->list_runs();

		$this->assertSame( 200, $response->get_status() );
		$runs = $response->get_data()['runs'];
		$this->assertCount( 1, $runs );
		$this->assertSame( 'run-orphan', $runs[0]['run_id'] );
		$this->assertTrue( $runs[0]['has_failed_job'] );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $job_id )['status'] );
		$this->assertSame( JobRepository::STATUS_PENDING, $this->jobs->find( $sibling )['status'] );
	}

	/**
	 * 猶予の内の run は、一覧でも従来どおり進行中のまま。
	 */
	public function test_listing_runs_keeps_a_recently_active_run_running(): void {
		[ $job_id ] = $this->create_run( 'run-recent', OrphanedRunRecovery::GRACE_SECONDS - 120 );

		$runs = $this->list_runs()->get_data()['runs'];

		$this->assertSame( JobRepository::STATUS_RUNNING, $runs[0]['status'] );
		$this->assertFalse( $runs[0]['has_failed_job'] );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->jobs->find( $job_id )['status'] );
	}

	/**
	 * ロックで囲むツールは、アクションを失った run を判定の前に失敗にして進む。
	 */
	public function test_a_locked_tool_fails_an_orphaned_run_and_proceeds(): void {
		[ $job_id ] = $this->create_run( 'run-orphan', OrphanedRunRecovery::GRACE_SECONDS + 120 );

		$response = $this->rebuild_mappings();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $job_id )['status'] );
	}

	/**
	 * 猶予の内の run があれば、ツールは従来どおり 409 で、run には触れない。
	 */
	public function test_a_locked_tool_is_still_refused_while_a_run_is_recently_active(): void {
		[ $job_id ] = $this->create_run( 'run-recent', OrphanedRunRecovery::GRACE_SECONDS - 120 );

		$response = $this->rebuild_mappings();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( JobRepository::STATUS_RUNNING, $this->jobs->find( $job_id )['status'] );
	}
}
