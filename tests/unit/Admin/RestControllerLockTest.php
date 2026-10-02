<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\ExportOptions;
use CartBridgeJP\Support\PlatformLock;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Writer\CustomerWriter;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * REST の同時実行ガードとプラットフォーム単位ロック（R3-0i・issue #57）。
 *
 * 別の要求がロックを保持している状況は、テストの中で `PlatformLock::acquire()` を呼んで作る（同じリクエストの
 * 中で入れ子に取ると内側は取得に失敗する＝別の要求が保持中と同じ）。`mock` と `colorme`（県コード修復は
 * `pref_id` スキームを持つ `colorme` だけが対象）の両方のキーに同じ mock アダプタを登録する。
 */
final class RestControllerLockTest extends WP_UnitTestCase {

	private const BUSY_MESSAGE = 'Another operation is still in progress for this platform. Try again in a moment.';

	private WP_REST_Server $server;
	private JobRepository $jobs;

	/**
	 * `colorme` キーの mock。県コード修復が ASP へ照会したか（`fetched_by_id`）を見るために持つ。
	 */
	private MockPlatformAdapter $colorme;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		// 県コード修復の対象になる顧客（ASP 側は pref_id=4＝秋田）。`untouched_probe()` が旧コードの値（JP04）で取り込んだ
		// Woo 側の顧客を用意したときだけ照会・補正の対象になる。
		$this->colorme = new MockPlatformAdapter(
			customers: [
				new CanonicalCustomer(
					'legacy@example.com',
					'Yamada Taro',
					null,
					null,
					null,
					[
						'postal'   => '1000001',
						'pref_id'  => 4,
						'address1' => 'Chiyoda 1-1-1',
						'country'  => 'JP',
					],
					'0312345678',
					null,
					null,
					null,
					[ 'remote_id' => 'C1' ]
				),
			],
			platform_id: 'colorme'
		);

		$colorme = $this->colorme;
		remove_all_filters( 'cbjp/adapters/register' );
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $colorme ) {
				$adapters['mock']    = new MockPlatformAdapter();
				$adapters['colorme'] = $colorme;

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

	private function lock_value( string $platform ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", PlatformLock::option_name( $platform ) ) );

		return null === $value ? null : (string) $value;
	}

	private function failed_job( string $platform ): int {
		$job_id = $this->jobs->create( 'run-failed', 'import', $platform, 'category' );
		$this->jobs->mark_failed(
			$job_id,
			[
				'code'    => 'exception',
				'message' => 'boom',
			]
		);

		return $job_id;
	}

	/**
	 * 同時実行ガードを持つ要求（状態を変える前に、このテストの中で作れる前提を用意する）。
	 */
	private function guarded_request( string $name ): WP_REST_Request {
		switch ( $name ) {
			case 'start run':
				$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
				$request->set_body_params(
					[
						'type'     => 'dry_run',
						'platform' => 'mock',
						'entities' => [ 'category' ],
					]
				);

				return $request;
			case 'retry job':
				return new WP_REST_Request( 'POST', '/cbjp/v1/jobs/' . $this->failed_job( 'mock' ) . '/retry' );
			case 'sample cleanup':
				$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/sample-cleanup' );
				$request->set_body_params( [ 'platform' => 'mock' ] );

				return $request;
			case 'rebuild mappings':
				$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/rebuild-mappings' );
				$request->set_body_params( [ 'platform' => 'mock' ] );

				return $request;
			case 'state repair scan':
				$request = new WP_REST_Request( 'GET', '/cbjp/v1/tools/repair-states' );
				$request->set_query_params( [ 'platform' => 'colorme' ] );

				return $request;
			case 'state repair run':
				$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/repair-states' );
				$request->set_body_params( [ 'platform' => 'colorme' ] );

				return $request;
			case 'export options':
				$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/export-options/mock' );
				$request->set_body_params( [ 'push_images' => false ] );

				return $request;
			case 'push intent resolve':
				$intents = new PushIntentRepository();
				$intents->begin( 'mock', 'product', 101, null, null );
				$request = new WP_REST_Request( 'POST', '/cbjp/v1/push-intents/mock/' . $intents->find_unresolved( 'mock' )[0]['id'] . '/resolve' );
				$request->set_body_params( [ 'action' => 'not_created' ] );

				return $request;
		}

		$this->fail( "Unknown request: {$name}" );
	}

	/**
	 * @return array<string,array{0:string,1:string}> [要求の名前, その要求のプラットフォーム]
	 */
	public static function guarded_requests(): array {
		return [
			'start run'           => [ 'start run', 'mock' ],
			'retry job'           => [ 'retry job', 'mock' ],
			'sample cleanup'      => [ 'sample cleanup', 'mock' ],
			'rebuild mappings'    => [ 'rebuild mappings', 'mock' ],
			'state repair scan'   => [ 'state repair scan', 'colorme' ],
			'state repair run'    => [ 'state repair run', 'colorme' ],
			'export options'      => [ 'export options', 'mock' ],
			'push intent resolve' => [ 'push intent resolve', 'mock' ],
		];
	}

	private function assert_busy( WP_REST_Response $response ): void {
		$error = $response->as_error();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'cbjp_run_in_progress', $error->get_error_code() );
		$this->assertSame( self::BUSY_MESSAGE, $error->get_error_message() );
		$this->assertSame( [], $error->get_error_data()['active_runs'] );
	}

	/**
	 * 要求が状態を変えたら観測できる前提を作り、変わっていないことを確かめる関数を返す（409 になった要求が、
	 * 判定の前・ロックの外で処理を済ませていないことを確かめるため）。
	 *
	 * @return callable():void
	 */
	private function untouched_probe( string $name ): callable {
		$mappings = new MappingRepository();

		switch ( $name ) {
			case 'start run':
				return fn () => $this->assertSame( [], $this->jobs->find_active_runs_for_platform( 'mock' ) );
			case 'retry job':
				$job_id = $this->jobs->find_by_run( 'run-failed' )[0]['id'] ?? 0;

				return fn () => $this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( (int) $job_id )['status'] );
			case 'sample cleanup':
				$mappings->upsert( 'mock', 'product', 'p1', 999999, null );

				return fn () => $this->assertSame( 1, $mappings->count( 'mock', 'product' ) );
			case 'rebuild mappings':
				$product = new WC_Product_Simple();
				$product->set_name( 'Owned' );
				$product->update_meta_data( '_cbjp_platform', 'mock' );
				$product->update_meta_data( '_cbjp_remote_id', 'p1' );
				$product->save();

				return fn () => $this->assertSame( 0, $mappings->count( 'mock', 'product' ) );
			case 'export options':
				ExportOptions::save_push_images( 'mock', true );

				return fn () => $this->assertTrue( ExportOptions::push_images_enabled( 'mock' ) );
			case 'push intent resolve':
				return fn () => $this->assertCount( 1, ( new PushIntentRepository() )->find_unresolved( 'mock' ) );
			case 'state repair scan':
			case 'state repair run':
				// 旧コードで取り込まれた顧客（pref_id=4＝秋田なのに JP04＝宮城）。Scan は ASP へ照会し、Run は JP05 へ補正する。
				$user_id = self::factory()->user->create( [ 'role' => 'customer' ] );

				foreach ( [ 'billing', 'shipping' ] as $side ) {
					update_user_meta( $user_id, "{$side}_state", 'JP04' );
					update_user_meta( $user_id, "{$side}_postcode", '1000001' );
					update_user_meta( $user_id, "{$side}_address_1", 'Chiyoda 1-1-1' );
					update_user_meta( $user_id, "{$side}_country", 'JP' );
				}

				update_user_meta( $user_id, '_cbjp_platform', 'colorme' );
				$mappings->upsert( 'colorme', 'customer', 'C1', $user_id, null );

				return function () use ( $user_id ): void {
					$this->assertSame( [], $this->colorme->fetched_by_id );
					$this->assertSame( 'JP04', get_user_meta( $user_id, 'billing_state', true ) );
				};
		}

		$this->fail( "Unknown request: {$name}" );
	}

	/**
	 * 別の操作が判定〜状態変更の区間を実行中（ロックを保持中）なら、どの要求も 409 で何もしない。
	 *
	 * @dataProvider guarded_requests
	 */
	public function test_a_guarded_request_is_refused_while_another_operation_holds_the_lock( string $name, string $platform ): void {
		$request   = $this->guarded_request( $name );
		$untouched = $this->untouched_probe( $name );
		$held      = ( new PlatformLock() )->acquire( $platform, PlatformLock::TTL_SHORT );

		$this->assert_busy( $this->server->dispatch( $request ) );
		$untouched();
		// 他者のロックを消していない。
		$this->assertSame( $held, $this->lock_value( $platform ) );
	}

	/**
	 * キャンセルした run が処理中のページを書き終えていない間（Action Scheduler のアクションが in-progress）も 409。
	 *
	 * @dataProvider guarded_requests
	 */
	public function test_a_guarded_request_is_refused_while_a_cancelled_run_is_still_writing_a_page( string $name, string $platform ): void {
		$request = $this->guarded_request( $name );
		$job_id  = $this->jobs->create( 'run-cancelled', 'import', $platform, 'product' );
		$this->jobs->cancel_run( 'run-cancelled' );
		$action = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $job_id ], JobManager::ACTION_GROUP );
		\ActionScheduler::store()->log_execution( $action );

		$this->assert_busy( $this->server->dispatch( $request ) );
	}

	/**
	 * 成功した要求の後にロックが残らない（残ると TTL の間、そのプラットフォームの操作がすべて 409 になる）。
	 *
	 * @dataProvider guarded_requests
	 */
	public function test_a_guarded_request_releases_the_lock_after_it_succeeds( string $name, string $platform ): void {
		$response = $this->server->dispatch( $this->guarded_request( $name ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertNull( $this->lock_value( $platform ) );
	}

	public function test_the_lock_is_released_after_a_tool_returns_an_error(): void {
		$invalid_cursor = new WP_REST_Request( 'POST', '/cbjp/v1/tools/rebuild-mappings' );
		$invalid_cursor->set_body_params(
			[
				'platform' => 'mock',
				'cursor'   => '{"entity":"nope","offset":0}',
			]
		);
		$this->assertSame( 400, $this->server->dispatch( $invalid_cursor )->get_status() );
		$this->assertNull( $this->lock_value( 'mock' ) );

		$missing_intent = new WP_REST_Request( 'POST', '/cbjp/v1/push-intents/mock/999999/resolve' );
		$missing_intent->set_body_params( [ 'action' => 'not_created' ] );
		$this->assertSame( 404, $this->server->dispatch( $missing_intent )->get_status() );
		$this->assertNull( $this->lock_value( 'mock' ) );

		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		update_user_meta( $customer_id, '_cbjp_platform', 'mock' );
		update_user_meta( $customer_id, '_cbjp_remote_id', 'cu1' );
		update_user_meta( $customer_id, CustomerWriter::CREATED_BY_IMPORT_META, 'mock' );
		( new MappingRepository() )->upsert( 'mock', 'customer', 'cu1', $customer_id, null );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cleanup = new WP_REST_Request( 'POST', '/cbjp/v1/tools/sample-cleanup' );
		$cleanup->set_body_params( [ 'platform' => 'mock' ] );
		$this->assertSame( 403, $this->server->dispatch( $cleanup )->get_status() );
		$this->assertNull( $this->lock_value( 'mock' ) );
	}

	/**
	 * 一覧に run があるときの 409 は従来どおりの文言（ロック・処理中のアクションの文言と区別する）。
	 */
	public function test_a_run_in_progress_keeps_the_run_message_and_lists_the_run(): void {
		$start = $this->server->dispatch( $this->guarded_request( 'start run' ) );
		$this->assertSame( 200, $start->get_status() );

		$error = $this->server->dispatch( $this->guarded_request( 'sample cleanup' ) )->as_error();

		$this->assertSame( 'A run is already in progress for this platform.', $error->get_error_message() );
		$this->assertSame( [ $start->get_data()['run_id'] ], array_column( $error->get_error_data()['active_runs'], 'run_id' ) );
	}

	public function test_the_cleanup_preview_reports_a_cancelled_run_still_writing_a_page(): void {
		$job_id = $this->jobs->create( 'run-cancelled', 'import', 'mock', 'product' );
		$this->jobs->cancel_run( 'run-cancelled' );
		$action = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $job_id ], JobManager::ACTION_GROUP );

		$preview = new WP_REST_Request( 'GET', '/cbjp/v1/tools/sample-cleanup' );
		$preview->set_query_params( [ 'platform' => 'mock' ] );
		$this->assertFalse( $this->server->dispatch( $preview )->get_data()['run_in_progress'] );

		\ActionScheduler::store()->log_execution( $action );

		$this->assertTrue( $this->server->dispatch( $preview )->get_data()['run_in_progress'] );
	}

	/**
	 * キャンセルは未終了のジョブだけを 1 文で止め、完了・失敗したジョブを書き換えない。
	 */
	public function test_cancel_run_leaves_finished_jobs_untouched(): void {
		$completed = $this->jobs->create( 'run-x', 'import', 'mock', 'category' );
		$this->jobs->update_status( $completed, JobRepository::STATUS_COMPLETED );
		$running = $this->jobs->create( 'run-x', 'import', 'mock', 'product' );
		$this->jobs->update_status( $running, JobRepository::STATUS_RUNNING );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/cbjp/v1/runs/run-x/cancel' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $this->jobs->find( $completed )['status'] );
		$this->assertSame( JobRepository::STATUS_CANCELLED, $this->jobs->find( $running )['status'] );
	}

	/**
	 * キャンセルはロックを取らない（ツールのバッチの実行中でも run を止められる）。
	 */
	public function test_cancel_run_is_not_blocked_by_the_lock(): void {
		$running = $this->jobs->create( 'run-x', 'import', 'mock', 'product' );
		$this->jobs->update_status( $running, JobRepository::STATUS_RUNNING );
		( new PlatformLock() )->acquire( 'mock', PlatformLock::TTL_SHORT );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/cbjp/v1/runs/run-x/cancel' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( JobRepository::STATUS_CANCELLED, $this->jobs->find( $running )['status'] );
	}
}
