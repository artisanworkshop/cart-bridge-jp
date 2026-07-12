<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\RunAlreadyInProgressException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API（namespace: `cbjp/v1`）。`docs/03-design-decisions.md` §6 のルート定義。
 *
 * connections/runs/logs/limits はSync/Support層と接続済み。OAuth・設定マッピング・
 * ツール系（sample-cleanup/rebuild-mappings）はPhase 1以降の実装のため501を返す。
 */
final class RestController {

	private const NAMESPACE = 'cbjp/v1';

	private const ENTITY_TYPES = [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ];

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/connections',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_connections' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<platform>[a-z0-9_-]+)',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'not_implemented' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_connection' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<platform>[a-z0-9_-]+)/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test_connection' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<platform>[a-z0-9_-]+)/authorize-url',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'not_implemented' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connect/(?P<platform>[a-z0-9_-]+)/callback',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'not_implemented' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'start_run' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<run_id>[a-zA-Z0-9-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_run' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<run_id>[a-zA-Z0-9-]+)/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_run' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/retry',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'retry_job' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_logs' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/mappings/(?P<platform>[a-z0-9_-]+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'not_implemented' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'not_implemented' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/limits',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_limits' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/tools/sample-cleanup',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'not_implemented' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/tools/rebuild-mappings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'not_implemented' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function not_implemented(): WP_Error {
		return new WP_Error(
			'cbjp_not_implemented',
			__( 'This endpoint is not implemented yet.', 'cart-bridge-jp' ),
			[ 'status' => 501 ]
		);
	}

	public function get_connections(): WP_REST_Response {
		$connections = [];

		foreach ( AdapterRegistry::all() as $id => $adapter ) {
			$token_store = new TokenStore( $id );

			$connections[] = [
				'platform'          => $id,
				'label'             => $adapter->label(),
				'connected'         => $token_store->is_connected(),
				'needs_reconnect'   => $token_store->needs_reconnect(),
				'masked_token'      => $token_store->masked_access_token(),
				'capabilities'      => $adapter->capabilities()->to_array(),
				// connection_fields()は外部フィルター経由で登録され得るアダプタ（Pro拡張含む）の
				// 実装依存であり、契約違反（ConnectionField以外の混入）でエンドポイント全体を
				// 落とさないよう、AdapterRegistry::all()同様に防御的にフィルタする。
				'connection_fields' => array_map(
					static fn( ConnectionField $field ): array => [
						'key'      => $field->key,
						'label'    => $field->label,
						'type'     => $field->type,
						'required' => $field->required,
						'help'     => $field->help,
					],
					array_filter(
						$adapter->connection_fields(),
						static fn( $field ): bool => $field instanceof ConnectionField
					)
				),
			];
		}

		return rest_ensure_response( $connections );
	}

	public function delete_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = (string) $request->get_param( 'platform' );

		if ( ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( $platform );
		}

		( new TokenStore( $platform ) )->delete();

		return rest_ensure_response( [ 'deleted' => true ] );
	}

	public function test_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = (string) $request->get_param( 'platform' );
		$adapter  = AdapterRegistry::get( $platform );

		if ( null === $adapter ) {
			return $this->unknown_platform_error( $platform );
		}

		try {
			$result = $adapter->test_connection();
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'cbjp_connection_test_failed',
				__( 'Connection test failed.', 'cart-bridge-jp' ),
				[
					'status' => 500,
					'detail' => $exception->getMessage(),
				]
			);
		}

		return rest_ensure_response(
			[
				'ok'        => $result->ok,
				'shop_name' => $result->shop_name,
				'message'   => $result->message,
			]
		);
	}

	public function start_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type     = (string) $request->get_param( 'type' );
		$platform = (string) $request->get_param( 'platform' );
		$entities = (array) ( $request->get_param( 'entities' ) ?? [] );

		if ( JobManager::TYPE_DRY_RUN !== $type ) {
			return new WP_Error(
				'cbjp_not_implemented',
				__( 'Only dry-run is supported until platform adapters ship in Phase 1.', 'cart-bridge-jp' ),
				[ 'status' => 501 ]
			);
		}

		if ( ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( $platform );
		}

		try {
			$run_id = JobManager::create()->start_run( $type, $platform, array_map( 'strval', $entities ) );
		} catch ( RunAlreadyInProgressException ) {
			return new WP_Error(
				'cbjp_run_in_progress',
				__( 'A run is already in progress for this platform.', 'cart-bridge-jp' ),
				[ 'status' => 409 ]
			);
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'cbjp_invalid_run',
				__( 'The run request is invalid.', 'cart-bridge-jp' ),
				[
					'status' => 400,
					'detail' => $exception->getMessage(),
				]
			);
		}

		return rest_ensure_response( [ 'run_id' => $run_id ] );
	}

	public function get_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run_id = (string) $request->get_param( 'run_id' );
		$jobs   = ( new JobRepository() )->find_by_run( $run_id );

		if ( [] === $jobs ) {
			return new WP_Error( 'cbjp_run_not_found', __( 'Run not found.', 'cart-bridge-jp' ), [ 'status' => 404 ] );
		}

		$formatted = array_map(
			static function ( array $job ): array {
				$totals = json_decode( (string) $job['totals_json'], true );

				return [
					'id'     => (int) $job['id'],
					'entity' => $job['entity'],
					'status' => $job['status'],
					'totals' => is_array( $totals ) ? $totals : [],
					'error'  => null !== $job['error_json'] ? json_decode( (string) $job['error_json'], true ) : null,
				];
			},
			$jobs
		);

		return rest_ensure_response(
			[
				'run_id' => $run_id,
				'jobs'   => $formatted,
			]
		);
	}

	public function cancel_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run_id     = (string) $request->get_param( 'run_id' );
		$repository = new JobRepository();
		$jobs       = $repository->find_by_run( $run_id );

		if ( [] === $jobs ) {
			return new WP_Error( 'cbjp_run_not_found', __( 'Run not found.', 'cart-bridge-jp' ), [ 'status' => 404 ] );
		}

		$terminal = [ JobRepository::STATUS_COMPLETED, JobRepository::STATUS_FAILED, JobRepository::STATUS_CANCELLED ];

		foreach ( $jobs as $job ) {
			if ( ! in_array( $job['status'], $terminal, true ) ) {
				$repository->update_status( (int) $job['id'], JobRepository::STATUS_CANCELLED );
			}
		}

		return rest_ensure_response( [ 'cancelled' => true ] );
	}

	public function retry_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id         = (int) $request->get_param( 'id' );
		$repository = new JobRepository();
		$job        = $repository->find( $id );

		if ( null === $job ) {
			return new WP_Error( 'cbjp_job_not_found', __( 'Job not found.', 'cart-bridge-jp' ), [ 'status' => 404 ] );
		}

		if ( ! JobManager::create()->retry( $id ) ) {
			return new WP_Error( 'cbjp_invalid_job_state', __( 'Only failed jobs can be retried.', 'cart-bridge-jp' ), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'id'     => $id,
				'status' => JobRepository::STATUS_PENDING,
			]
		);
	}

	public function list_logs( WP_REST_Request $request ): WP_REST_Response {
		// `/logs` はargsスキーマを持たないため、job_id/level/pageは配列（例: `?job_id[]=1`）
		// になり得る。スカラー以外は不正な指定として無視する（配列を(int)/(string)キャストすると
		// 意図しないフィルタとして働き、全件除外やページ指定ミスにつながるため）。
		$job_id         = $this->scalar_query_param( $request, 'job_id' );
		$level          = $this->scalar_query_param( $request, 'level' );
		$requested_page = $this->scalar_query_param( $request, 'page' );
		$page           = max( 1, (int) ( $requested_page ? $requested_page : 1 ) );

		// 空のクエリパラメータ（`?job_id=` / `?level=`）は「フィルタなし」として扱う
		// （空文字を 0 / '' にキャストすると存在しない条件で全ログが除外されるため）。
		$logs = ( new \CartBridgeJP\Sync\LogRepository() )->list(
			null !== $job_id && '' !== $job_id ? (int) $job_id : null,
			null !== $level && '' !== $level ? (string) $level : null,
			$page
		);

		return rest_ensure_response( $logs );
	}

	/**
	 * argsスキーマ未定義のクエリパラメータをスカラー値としてのみ受け付ける。
	 * 配列等の非スカラー値は「未指定」として扱う。
	 */
	private function scalar_query_param( WP_REST_Request $request, string $key ): string|int|float|bool|null {
		$value = $request->get_param( $key );

		return is_scalar( $value ) ? $value : null;
	}

	/**
	 * 無料版上限・使用状況・Pro解除状態（アップセル表示用、D15/§10.2）。
	 * `platform` は任意: 指定時は使用状況（mappings累積カウント）と残数を含める。
	 */
	public function get_limits( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = (string) ( $request->get_param( 'platform' ) ?? '' );

		if ( '' !== $platform && ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( $platform );
		}

		$mappings = new MappingRepository();
		$limits   = new LimitPolicy( $mappings );

		$entities = [];

		foreach ( self::ENTITY_TYPES as $entity ) {
			$limit = $limits->limit_for( $entity );

			$entities[ $entity ] = [
				'limit'     => $limit,
				'unlocked'  => null === $limit,
				'used'      => '' !== $platform ? $mappings->count( $platform, $entity ) : null,
				'remaining' => '' !== $platform ? $limits->remaining( $platform, $entity ) : null,
			];
		}

		// 全エンティティが解除済み＝Pro解除状態。
		$all_unlocked = ! in_array( false, array_column( $entities, 'unlocked' ), true );

		return rest_ensure_response(
			[
				'unlocked' => $all_unlocked,
				'entities' => $entities,
			]
		);
	}

	private function unknown_platform_error( string $platform ): WP_Error {
		return new WP_Error(
			'cbjp_unknown_platform',
			/* translators: %s: platform id */
			sprintf( __( 'Unknown or unregistered platform "%s".', 'cart-bridge-jp' ), $platform ),
			[ 'status' => 404 ]
		);
	}
}
