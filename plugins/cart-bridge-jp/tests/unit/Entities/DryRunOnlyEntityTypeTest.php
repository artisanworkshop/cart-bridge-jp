<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Entities;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Sync\DryRunOnlyException;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Tests\Fixtures\Gizmo\GizmoType;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * 拡張が自分の種類を dry-run だけにする汎用の口（`EntityType::dry_run_only_reason()`。R3-8b・`docs/03` §10.7「守ること」1〜3）。
 * Pro アドオンはキーを有効化していない間、顧客・受注・クーポンをこの口で dry-run だけにする（判定は Pro。無料版はライセンスを知らない）。
 *
 * - 宣言: `GET /connections` の `entities` の各項目に `dry_run_only`・`dry_run_only_reason`。拡張が無いときは全部偽（無料版の画面に何も出ない）。
 * - 拒否: 実際の取込み・エクスポートの run の作成と、その種類の失敗したジョブの Retry（ジョブもアクションも作らない）。dry-run は通す。
 * - 無料版の種類は拡張の都合で止めない（呼ばずに null）。拡張の例外は理由の無い制限に倒す（原則 9）。
 */
final class DryRunOnlyEntityTypeTest extends WP_UnitTestCase {

	use RegistersEntityTypes;

	private GizmoType $gizmo;

	private WP_REST_Server $server;

	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$adapter = new MockPlatformAdapter( push_products_supported: true, push_stocks_supported: true );
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$this->gizmo = new GizmoType();
		$this->jobs  = new JobRepository();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。

		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->forget_entity_types();
		parent::tear_down();
		$this->forget_entity_types();
	}

	/**
	 * 種類を登録した後に REST を組み立てる（report の enum が登録の時点のレジストリから作られるため。CLAUDE.md の rules）。
	 */
	private function register_gizmo(): void {
		$this->register_entity_types( [ $this->gizmo ] );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function mock_connection(): array {
		$connections = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data();

		return array_values( array_filter( $connections, static fn ( array $item ): bool => 'mock' === $item['platform'] ) )[0];
	}

	private function job_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- テストの件数の確認。
	}

	/**
	 * `process_job()` のアクションのうち、まだ実行されていないもの（ブートストラップが残したものを含みうるので、差で見る）。
	 */
	private function queued_actions(): int {
		return count(
			as_get_scheduled_actions(
				[
					'hook'     => JobManager::ACTION_HOOK,
					'group'    => JobManager::ACTION_GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			)
		);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function post_run( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	private function failed_job( string $type, string $entity ): int {
		$job_id = $this->jobs->create( wp_generate_uuid4(), $type, 'mock', $entity );
		$this->jobs->update_status( $job_id, JobRepository::STATUS_FAILED );

		return $job_id;
	}

	public function test_nothing_is_dry_run_only_without_an_extension(): void {
		$this->register_entity_types( [] );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );

		$connection = $this->mock_connection();

		foreach ( [ 'import', 'export' ] as $direction ) {
			$this->assertNotSame( [], $connection['entities'][ $direction ] );
			$this->assertSame( [ false ], array_values( array_unique( array_column( $connection['entities'][ $direction ], 'dry_run_only' ) ) ), $direction );
			$this->assertSame( [ '' ], array_values( array_unique( array_column( $connection['entities'][ $direction ], 'dry_run_only_reason' ) ) ), $direction );
		}

		$this->assertSame( [], EntityTypeRegistry::dry_run_only( AdapterRegistry::get( 'mock' ), EntityTypeRegistry::keys() ) );
	}

	public function test_a_free_type_is_never_dry_run_only_even_if_an_instance_claims_it(): void {
		// 無料版の種類のキーを持つインスタンスが理由を返しても、レジストリは呼ばずに null にする（無料版自身の機能を拡張の都合で止めない）。
		$impostor = new class() extends EntityType {
			public function key(): string {
				return 'product';
			}

			public function label(): string {
				return 'Products';
			}

			public function position(): int {
				return 30;
			}

			public function dry_run_only_reason( PlatformAdapter $adapter ): ?string {
				return 'Blocked.';
			}
		};

		$this->assertNull( EntityTypeRegistry::dry_run_only_reason( $impostor, AdapterRegistry::get( 'mock' ) ) );
	}

	public function test_connections_declare_the_restriction_and_its_reason(): void {
		$this->gizmo->dry_run_only = " <strong>Activate</strong> the add-on.\n";
		$this->register_gizmo();

		$connection = $this->mock_connection();

		foreach ( [ 'import', 'export' ] as $direction ) {
			$this->assertSame(
				[
					'product' => false,
					'gizmo'   => true,
					'stock'   => false,
				],
				array_intersect_key(
					array_column( $connection['entities'][ $direction ], 'dry_run_only', 'key' ),
					[
						'product' => 1,
						'gizmo'   => 1,
						'stock'   => 1,
					]
				),
				$direction
			);
			$this->assertSame( 'Activate the add-on.', array_column( $connection['entities'][ $direction ], 'dry_run_only_reason', 'key' )['gizmo'], $direction );
		}
	}

	public function test_a_failing_restriction_check_is_a_restriction_without_a_reason(): void {
		$this->gizmo->dry_run_only = new RuntimeException( 'License storage is broken.' );
		$this->register_gizmo();

		$import = array_column( $this->mock_connection()['entities']['import'], null, 'key' )['gizmo'];
		$this->assertTrue( $import['dry_run_only'] );
		$this->assertSame( '', $import['dry_run_only_reason'] );

		try {
			JobManager::create()->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'gizmo' ] );
			$this->fail( 'The run must be refused.' );
		} catch ( DryRunOnlyException $exception ) {
			$this->assertSame( [ 'gizmo' => '' ], $exception->reasons() );
		}
	}

	public function test_import_and_export_runs_with_a_restricted_type_are_refused_without_side_effects(): void {
		$this->gizmo->dry_run_only = 'Activate the add-on.';
		$this->register_gizmo();

		$jobs    = $this->job_count();
		$actions = $this->queued_actions();

		foreach ( [ JobManager::TYPE_IMPORT, JobManager::TYPE_EXPORT ] as $type ) {
			try {
				JobManager::create()->start_run( $type, 'mock', [ 'product', 'gizmo', 'stock' ] );
				$this->fail( "The {$type} run must be refused." );
			} catch ( DryRunOnlyException $exception ) {
				$this->assertSame( [ 'gizmo' => 'Activate the add-on.' ], $exception->reasons(), $type );
			}
		}

		// 制限のある種類を黙って外して残りで始めない（ジョブもアクションも作らない）。
		$this->assertSame( $jobs, $this->job_count() );
		$this->assertSame( $actions, $this->queued_actions() );
	}

	public function test_dry_runs_and_runs_without_the_restricted_type_still_start(): void {
		$this->gizmo->dry_run_only = 'Activate the add-on.';
		$this->register_gizmo();
		$manager = JobManager::create();

		foreach ( [ JobManager::TYPE_DRY_RUN, JobManager::TYPE_DRY_RUN_EXPORT ] as $type ) {
			$run_id = $manager->start_run( $type, 'mock', [ 'product', 'gizmo' ] );
			$this->assertSame( [ 'product', 'gizmo' ], array_column( $this->jobs->find_by_run( $run_id ), 'entity' ), $type );
			$this->jobs->cancel_run( $run_id );
		}

		$run_id = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'product' ] );
		$this->assertSame( [ 'product' ], array_column( $this->jobs->find_by_run( $run_id ), 'entity' ) );
	}

	public function test_the_rest_api_refuses_the_restricted_type_and_names_it(): void {
		$this->gizmo->dry_run_only = 'Activate the add-on.';
		$this->register_gizmo();
		$jobs = $this->job_count();

		foreach ( [ JobManager::TYPE_IMPORT, JobManager::TYPE_EXPORT ] as $type ) {
			$response = $this->post_run(
				[
					'type'                         => $type,
					'platform'                     => 'mock',
					'entities'                     => [ 'product', 'gizmo' ],
					'acknowledge_production_write' => true,
				]
			);

			$this->assertSame( 400, $response->get_status(), $type );
			$this->assertSame( 'cbjp_dry_run_only', $response->get_data()['code'], $type );
			$this->assertSame( [ 'gizmo' ], $response->get_data()['data']['entities'], $type );
			$this->assertSame( 'Only a dry run is available for Gizmos on this site. Activate the add-on.', $response->get_data()['message'], $type );
		}

		$this->assertSame( $jobs, $this->job_count() );

		$dry_run = $this->post_run(
			[
				'type'     => JobManager::TYPE_DRY_RUN,
				'platform' => 'mock',
				'entities' => [ 'product', 'gizmo' ],
			]
		);
		$this->assertSame( 200, $dry_run->get_status() );
	}

	public function test_the_message_without_a_reason_names_the_types_only(): void {
		$this->gizmo->dry_run_only = '';
		$this->register_gizmo();

		$response = $this->post_run(
			[
				'type'     => JobManager::TYPE_IMPORT,
				'platform' => 'mock',
				'entities' => [ 'gizmo' ],
			]
		);

		$this->assertSame( 'cbjp_dry_run_only', $response->get_data()['code'] );
		$this->assertSame( 'Only a dry run is available for Gizmos on this site.', $response->get_data()['message'] );
	}

	public function test_retrying_a_failed_import_or_export_job_of_a_restricted_type_is_refused(): void {
		$this->register_gizmo();
		$import                    = $this->failed_job( JobManager::TYPE_IMPORT, 'gizmo' );
		$export                    = $this->failed_job( JobManager::TYPE_EXPORT, 'gizmo' );
		$this->gizmo->dry_run_only = 'Activate the add-on.';
		$actions                   = $this->queued_actions();

		try {
			JobManager::create()->retry( $import );
			$this->fail( 'The retry must be refused.' );
		} catch ( DryRunOnlyException $exception ) {
			$this->assertSame( [ 'gizmo' => 'Activate the add-on.' ], $exception->reasons() );
		}

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/cbjp/v1/jobs/{$export}/retry" ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'cbjp_dry_run_only', $response->get_data()['code'] );

		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $import )['status'] );
		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $export )['status'] );
		$this->assertSame( $actions, $this->queued_actions() );
	}

	public function test_retrying_dry_run_jobs_or_unrestricted_jobs_still_works(): void {
		$this->register_gizmo();
		$this->gizmo->dry_run_only = 'Activate the add-on.';
		$manager                   = JobManager::create();

		foreach ( [ JobManager::TYPE_DRY_RUN, JobManager::TYPE_DRY_RUN_EXPORT ] as $type ) {
			$job_id = $this->failed_job( $type, 'gizmo' );
			$this->assertTrue( $manager->retry( $job_id ), $type );
			// 次の Retry が「進行中の別の run」で断られないよう、戻した run を止める。
			$this->jobs->cancel_run( (string) $this->jobs->find( $job_id )['run_id'] );
		}

		$this->gizmo->dry_run_only = null;
		$this->assertTrue( $manager->retry( $this->failed_job( JobManager::TYPE_IMPORT, 'gizmo' ) ) );
	}

	public function test_a_job_whose_type_is_no_longer_registered_is_not_judged(): void {
		// 拡張が無効になった種類のジョブは「dry-run だけ」と断らず、従来どおり処理の中で失敗させる（理由として誤りになるため）。
		$this->register_gizmo();
		$job_id = $this->failed_job( JobManager::TYPE_IMPORT, 'widget' );

		$this->assertTrue( JobManager::create()->retry( $job_id ) );
	}
}
