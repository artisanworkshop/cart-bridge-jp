<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\ExportOptions;
use CartBridgeJP\Support\PlatformLock;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * REST の同時実行ガードとプラットフォーム単位ロック（R3-0i・issue #57）。
 *
 * 別の要求がロックを保持している状況は、テストの中で `PlatformLock::acquire()` を呼んで作る（同じリクエストの
 * 中で入れ子に取ると内側は取得に失敗する＝別の要求が保持中と同じ）。
 */
final class RestControllerLockTest extends WP_UnitTestCase {

	private const BUSY_MESSAGE = 'Another operation is still in progress for this platform. Try again in a moment.';

	private const DISCONNECT_RUN_MESSAGE = 'A run on this platform has not finished yet. Cancel it on the Tools tab (or the Import or Export tab) first, then try again.';

	private WP_REST_Server $server;
	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

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
			case 'rebuild mappings':
				$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/rebuild-mappings' );
				$request->set_body_params( [ 'platform' => 'mock' ] );

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
			case 'disconnect':
				return new WP_REST_Request( 'DELETE', '/cbjp/v1/connections/mock' );
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
			'rebuild mappings'    => [ 'rebuild mappings', 'mock' ],
			'export options'      => [ 'export options', 'mock' ],
			'push intent resolve' => [ 'push intent resolve', 'mock' ],
			'disconnect'          => [ 'disconnect', 'mock' ],
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
			case 'disconnect':
				( new TokenStore( 'mock' ) )->save_settings( [ 'client_id' => 'kept-client-id' ] );

				return fn () => $this->assertSame( [ 'client_id' => 'kept-client-id' ], ( new TokenStore( 'mock' ) )->settings() );
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
		$request   = $this->guarded_request( $name );
		$untouched = $this->untouched_probe( $name );
		$job_id    = $this->jobs->create( 'run-cancelled', 'import', $platform, 'product' );
		$this->jobs->cancel_run( 'run-cancelled' );
		$action = as_enqueue_async_action( JobManager::ACTION_HOOK, [ 'job_id' => $job_id ], JobManager::ACTION_GROUP );
		\ActionScheduler::store()->log_execution( $action );

		$this->assert_busy( $this->server->dispatch( $request ) );
		$untouched();
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
	}

	/**
	 * 一覧に run があるときの 409 は従来どおりの文言（ロック・処理中のアクションの文言と区別する）。
	 */
	public function test_a_run_in_progress_keeps_the_run_message_and_lists_the_run(): void {
		$start = $this->server->dispatch( $this->guarded_request( 'start run' ) );
		$this->assertSame( 200, $start->get_status() );

		$error = $this->server->dispatch( $this->guarded_request( 'rebuild mappings' ) )->as_error();

		$this->assertSame( 'A run is already in progress for this platform.', $error->get_error_message() );
		$this->assertSame( [ $start->get_data()['run_id'] ], array_column( $error->get_error_data()['active_runs'], 'run_id' ) );
	}

	/**
	 * run の実行中は接続を解除しない（R3-0p）。Connections タブには run の進捗もキャンセルも無いので、文言でキャンセルする場所（どの接続状態でも
	 * run を並べる Tools タブ）を案内する。
	 */
	public function test_disconnect_is_refused_while_a_run_is_in_progress_and_points_to_the_cancel(): void {
		( new TokenStore( 'mock' ) )->save_settings( [ 'client_id' => 'kept-client-id' ] );
		$start = $this->server->dispatch( $this->guarded_request( 'start run' ) );
		$this->assertSame( 200, $start->get_status() );

		$response = $this->server->dispatch( $this->guarded_request( 'disconnect' ) );
		$error    = $response->as_error();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'cbjp_run_in_progress', $error->get_error_code() );
		$this->assertSame( self::DISCONNECT_RUN_MESSAGE, $error->get_error_message() );
		$this->assertSame( [ $start->get_data()['run_id'] ], array_column( $error->get_error_data()['active_runs'], 'run_id' ) );
		$this->assertSame( [ 'client_id' => 'kept-client-id' ], ( new TokenStore( 'mock' ) )->settings() );
	}

	/**
	 * run をキャンセルすれば接続を解除できる（解除の手段が残っている）。
	 */
	public function test_disconnect_succeeds_after_the_run_is_cancelled(): void {
		( new TokenStore( 'mock' ) )->save_settings( [ 'client_id' => 'kept-client-id' ] );
		$run_id = $this->server->dispatch( $this->guarded_request( 'start run' ) )->get_data()['run_id'];
		$this->assertSame( 200, $this->server->dispatch( new WP_REST_Request( 'POST', "/cbjp/v1/runs/{$run_id}/cancel" ) )->get_status() );

		$response = $this->server->dispatch( $this->guarded_request( 'disconnect' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], ( new TokenStore( 'mock' ) )->settings() );
	}

	/**
	 * 要再接続（保存済みのトークンを復号できない）で止まった run があっても同じ 409 で、案内先は Tools タブ（R1-1）。
	 * Import/Export タブは接続済みのプラットフォームしか並べず、この状態では切断が唯一の復旧手段なので、案内が辿れることが要る。
	 */
	public function test_disconnect_while_reconnect_is_needed_points_to_the_tools_tab(): void {
		update_option( 'cbjp_token_mock', 'not-decryptable' );
		$store = new TokenStore( 'mock' );
		$this->assertTrue( $store->needs_reconnect() );
		$this->assertFalse( $store->is_connected() );
		$this->failed_job( 'mock' );
		$this->jobs->create( 'run-failed', 'import', 'mock', 'product' );

		$response = $this->server->dispatch( $this->guarded_request( 'disconnect' ) );
		$error    = $response->as_error();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( self::DISCONNECT_RUN_MESSAGE, $error->get_error_message() );
		$this->assertSame( [ 'run-failed' ], array_column( $error->get_error_data()['active_runs'], 'run_id' ) );
		$this->assertSame( 'not-decryptable', get_option( 'cbjp_token_mock' ) );
	}

	/**
	 * 別の要求がロックを持っている間に一覧に run があるときも、切断用の文言（R1-L1。ロックを取れなかった側の分岐）。
	 */
	public function test_disconnect_refused_by_the_lock_keeps_the_disconnect_message_while_a_run_is_listed(): void {
		( new TokenStore( 'mock' ) )->save_settings( [ 'client_id' => 'kept-client-id' ] );
		$run_id = $this->server->dispatch( $this->guarded_request( 'start run' ) )->get_data()['run_id'];
		( new PlatformLock() )->acquire( 'mock', PlatformLock::TTL_SHORT );

		$error = $this->server->dispatch( $this->guarded_request( 'disconnect' ) )->as_error();

		$this->assertSame( self::DISCONNECT_RUN_MESSAGE, $error->get_error_message() );
		$this->assertSame( [ $run_id ], array_column( $error->get_error_data()['active_runs'], 'run_id' ) );
		$this->assertSame( [ 'client_id' => 'kept-client-id' ], ( new TokenStore( 'mock' ) )->settings() );
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
