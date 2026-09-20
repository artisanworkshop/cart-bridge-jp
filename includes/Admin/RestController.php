<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ColorMe\ColorMeOAuth;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\RunAlreadyInProgressException;
use CartBridgeJP\Sync\VerificationReport;
use CartBridgeJP\Woo\Support\MappingCandidates;
use CartBridgeJP\Woo\Tools\CleanupNotPermittedException;
use CartBridgeJP\Woo\Tools\MappingRebuilder;
use CartBridgeJP\Woo\Tools\PrefStateRepair;
use CartBridgeJP\Woo\Tools\RepairInterruptedException;
use CartBridgeJP\Woo\Tools\SampleCleanup;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API（namespace: `cbjp/v1`）。`docs/03-design-decisions.md` §6 のルート定義。
 *
 * connections/runs/logs/limits/settings/mappings はSync/Support層と接続済み。
 * ツール系（sample-cleanup/rebuild-mappings。D16）と移行後検証レポート（runs/{run_id}/verification。D17）は
 * F1-7 で実装（`Woo\Tools\*` / `Sync\VerificationReport`）。
 */
final class RestController {

	private const NAMESPACE = 'cbjp/v1';

	private const ENTITY_TYPES = [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ];

	/**
	 * `Woo\Support\MethodMap`が読む`cbjp_settings_{platform}`オプションのトップレベルキー。
	 * `category_map`のみカラーミー側の作成不可制約により向きが逆（Woo側カテゴリID→ASP側カテゴリID）
	 * だが、保存・検証ロジックは向きに依存しないため同じキー集合として扱える（03 §6 / E2-1）。
	 */
	private const SETTINGS_MAP_KEYS = [ 'category_map', 'payment_map', 'shipping_map', 'status_map' ];

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
					'callback'            => [ $this, 'save_connection' ],
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
				'callback'            => [ $this, 'get_authorize_url' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		// OAuthフローの「コード手動貼り付け」フォールバック（ローカル開発等、自動コールバックが
		// 使えない環境向け。03 §6のOAuthコールバック節を参照。認証済み管理画面からの呼び出しのため
		// 通常のnonce+capability保護でよく、独自のstate検証は不要）。
		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<platform>[a-z0-9_-]+)/exchange-code',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'exchange_code' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connect/(?P<platform>[a-z0-9_-]+)/callback',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_oauth_callback' ],
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
			'/runs/(?P<run_id>[a-zA-Z0-9-]+)/report',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_run_report' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'run_id'        => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'entity'        => [
						'type'     => 'string',
						'enum'     => self::ENTITY_TYPES,
						'required' => false,
					],
					'only_warnings' => [
						'type'     => 'boolean',
						'default'  => false,
						'required' => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<run_id>[a-zA-Z0-9-]+)/verification',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_run_verification' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'run_id' => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
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
					'callback'            => [ $this, 'get_settings_mappings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'save_settings_mappings' ],
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

		// ツール系の `platform` はパスではなくクエリ/ボディ由来のため、`args` スキーマ（type検証）で
		// 配列混入を REST 層に弾かせる（CLAUDE.md）。`sanitize_callback` を明示すると WP は既定の
		// `rest_parse_request_arg`（検証＋サニタイズ）を適用しなくなるため、`validate_callback` も明示する。
		$platform_arg = [
			'platform' => [
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];

		register_rest_route(
			self::NAMESPACE,
			'/tools/sample-cleanup',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'preview_sample_cleanup' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $platform_arg,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'run_sample_cleanup' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $platform_arg,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/tools/rebuild-mappings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rebuild_mappings' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => array_merge(
					$platform_arg,
					[
						// 管理画面は初回に `cursor: null` を送るため null を許容する（`type: string` のみだと
						// `rest_parse_request_arg` が JSON の null を型エラーとして 400 にする）。
						'cursor' => [
							'type'              => [ 'string', 'null' ],
							'required'          => false,
							'validate_callback' => 'rest_validate_request_arg',
						],
					]
				),
			]
		);
		// 県コード修復ツール（issue #46）。Scan（GET・読取専用）と Repair（POST）をメソッドで分け、
		// 「書き込むか」を真偽値パラメータにしない（欠損・型違いが書込み側に倒れるのを構造的に防ぐ。
		// `sample-cleanup` の preview/run と同じ流儀）。
		$repair_args = array_merge(
			$platform_arg,
			[
				'cursor' => [
					'type'              => [ 'string', 'null' ],
					'required'          => false,
					'validate_callback' => 'rest_validate_request_arg',
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/tools/repair-states',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'scan_state_repair' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $repair_args,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'run_state_repair' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $repair_args,
				],
			]
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function get_connections(): WP_REST_Response {
		$connections = [];

		foreach ( AdapterRegistry::all() as $id => $adapter ) {
			$token_store = new TokenStore( $id );

			// connection_fields()は外部フィルター経由で登録され得るアダプタ（Pro拡張含む）の
			// 実装依存であり、契約違反（ConnectionField以外の混入）でエンドポイント全体を
			// 落とさないよう、AdapterRegistry::all()同様に防御的にフィルタする。
			// array_filter()はキーを保持するため、不正要素の除外で数値キーが飛ぶと
			// wp_json_encode()がJSON配列ではなくオブジェクトとして直列化し、UI側の
			// connection_fields.filter()がクラッシュする。array_values()で詰め直す。
			$connection_fields = array_values(
				array_filter(
					$adapter->connection_fields(),
					static fn( $field ): bool => $field instanceof ConnectionField
				)
			);
			$has_oauth         = [] !== array_filter(
				$connection_fields,
				static fn( ConnectionField $field ): bool => 'oauth_button' === $field->type
			);

			$connections[] = [
				'platform'          => $id,
				'label'             => $adapter->label(),
				'connected'         => $token_store->is_connected(),
				'needs_reconnect'   => $token_store->needs_reconnect(),
				// OAuth完了前にclient_id/secret等だけが保存されている状態。UI側は
				// これを見て、未接続でも資格情報の削除操作を出せるようにする。
				'has_settings'      => [] !== $token_store->settings(),
				'masked_token'      => $token_store->masked_access_token(),
				'capabilities'      => $adapter->capabilities()->to_array(),
				// OAuth型アダプタ向け: ASP側アプリ登録フォームに入力するコールバックURI。
				// client_id/secretの有無に関わらず算出できる静的な値のため、認可URL取得
				// （認証情報必須）より前の、アプリ登録の段階から提示できるようにする。
				'callback_url'      => $has_oauth ? $this->oauth_callback_url( $id ) : null,
				'connection_fields' => array_map(
					static fn( ConnectionField $field ): array => [
						'key'      => $field->key,
						'label'    => $field->label,
						'type'     => $field->type,
						'required' => $field->required,
						'help'     => $field->help,
					],
					$connection_fields
				),
			];
		}

		return rest_ensure_response( $connections );
	}

	public function delete_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->platform_param( $request );

		if ( ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( $platform );
		}

		( new TokenStore( $platform ) )->delete();

		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * 接続設定を保存する（makeshop: endpoint+token / colorme・base: client_id+secret。03 §6）。
	 * アダプタが `connection_fields()` で宣言したキー（`oauth_button`型を除く）のみを受け付ける
	 * （外部フィルター経由で登録され得るアダプタの契約違反への防御。§18のCLAUDE.md方針と同様）。
	 */
	public function save_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->platform_param( $request );
		$adapter  = AdapterRegistry::get( $platform );

		if ( null === $adapter ) {
			return $this->unknown_platform_error( $platform );
		}

		$body = $request->get_params();

		$allowed_keys = array_map(
			static fn( ConnectionField $field ): string => $field->key,
			array_filter(
				$adapter->connection_fields(),
				static fn( $field ): bool => $field instanceof ConnectionField && 'oauth_button' !== $field->type
			)
		);

		$settings = [];

		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $body[ $key ] ) || ! is_scalar( $body[ $key ] ) ) {
				continue;
			}

			// client_secret等の資格情報は表示用テキストではなく不透明な値。
			// sanitize_text_field()は%エンコード列（%3D等）やHTML風の文字列を
			// 除去してしまい、正しく貼り付けたシークレットを壊すため使わない。
			// 前後空白と制御文字の除去のみ行い、値そのものは保持する
			// （エスケープは出力時に行う）。
			$settings[ $key ] = (string) preg_replace(
				'/[\x00-\x1F\x7F]/',
				'',
				trim( (string) $body[ $key ] )
			);
		}

		if ( [] === $settings ) {
			return new WP_Error(
				'cbjp_invalid_request',
				__( 'No recognized connection fields were provided.', 'cart-bridge-jp' ),
				[ 'status' => 400 ]
			);
		}

		try {
			( new TokenStore( $platform ) )->save_settings( $settings );
		} catch ( RuntimeException $exception ) {
			return new WP_Error(
				'cbjp_save_conflict',
				__( 'The connection settings changed while saving. Please try again.', 'cart-bridge-jp' ),
				[ 'status' => 409 ]
			);
		}

		return rest_ensure_response( [ 'saved' => true ] );
	}

	/**
	 * カテゴリ/決済/配送/注文ステータスのマッピング設定を返す（`Woo\Support\MethodMap`が読む
	 * `cbjp_settings_{platform}`オプション。03 §6）。未設定時は4種とも空マップを返す。
	 * `asp_candidates`/`woo_candidates`（E2-1・D19）はUIが選択肢を描画するための候補一覧で、
	 * 保存済みマップ本体とは独立に毎回最新を取得する。
	 */
	public function get_settings_mappings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->platform_param( $request );

		if ( ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( $platform );
		}

		$response                   = $this->settings_mappings_response( $this->read_settings_mappings( $platform ) );
		$response['asp_candidates'] = $this->asp_mapping_candidates( $platform );
		$response['woo_candidates'] = $this->woo_mapping_candidates();

		return rest_ensure_response( $response );
	}

	/**
	 * カテゴリ/決済/配送/注文ステータスのマッピング設定を保存する。`category_map`/`payment_map`/
	 * `shipping_map`/`status_map`はそれぞれ独立に全置換する（キー自体を省略したマップは既存値を
	 * 保持する。例えばUIが決済方法だけを編集した場合に配送方法の設定を意図せず消さないため）。
	 * 値はASP/Wooのカテゴリ・決済ゲートウェイ・配送方法インスタンスID（`flat_rate:5`のように
	 * コロンを含みうる）・注文ステータススラッグという不透明な内部IDのため、`save_connection()`の
	 * 資格情報と同じ理由で`sanitize_text_field()`ではなく制御文字除去のみに留める
	 * （`sanitize_key()`は`:`等を除去し配送方法インスタンスIDを壊すため使わない）。
	 */
	public function save_settings_mappings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->platform_param( $request );

		if ( ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( $platform );
		}

		$body    = $request->get_params();
		$current = $this->read_settings_mappings( $platform );
		$updated = $current;

		foreach ( self::SETTINGS_MAP_KEYS as $map_key ) {
			if ( ! array_key_exists( $map_key, $body ) ) {
				continue;
			}

			// `is_array()`だけではJSONリスト（例: `["a","b"]`）も通ってしまい、
			// `sanitize_settings_map()`が連番インデックスをキーとして扱うため
			// `{"0":"a","1":"b"}`という意味のないマッピングが無警告で保存されてしまう。
			// 空配列（`{}`＝全クリアの意図）は`array_is_list()`がtrueを返すが正当な入力のため、
			// 空でないリストのみを拒否する。`{"0":"x"}`（キーが"0"のみの1要素オブジェクト）は
			// PHP側では`["x"]`と同じ配列表現になり区別できないため、こちらも道連れで拒否
			// されるが、この規約ではプラットフォーム側method_idが0になることは実運用上
			// ないため許容できる制約とする。
			if ( ! is_array( $body[ $map_key ] ) || ( [] !== $body[ $map_key ] && array_is_list( $body[ $map_key ] ) ) ) {
				return new WP_Error(
					'cbjp_invalid_request',
					/* translators: %s: settings map key (category_map/payment_map/shipping_map/status_map) */
					sprintf( __( '"%s" must be an object mapping one id to another.', 'cart-bridge-jp' ), $map_key ),
					[ 'status' => 400 ]
				);
			}

			$validated = $this->validate_settings_map( $body[ $map_key ] );

			if ( null === $validated ) {
				// `sanitize_settings_map()`（読取専用。DB内の既存値が壊れていても
				// エンドポイント自体を落とさないための寛容な読み取り用）を書込みにも
				// 流用すると、不正な値（例: `{"1094475":[]}`のような非スカラー値）を
				// 含むエントリだけを黙って読み飛ばして「保存成功（200）」を返してしまう。
				// 送信されたマップが1件しか無ければ結果は空マップでの全置換となり、
				// 見た目は成功しているのに正当な既存マッピングが消える（Codexレビュー指摘）。
				// 書込み側は1件でも不正なエントリがあればリクエスト全体を拒否する。
				return new WP_Error(
					'cbjp_invalid_request',
					/* translators: %s: settings map key (category_map/payment_map/shipping_map/status_map) */
					sprintf( __( '"%s" contains an invalid entry.', 'cart-bridge-jp' ), $map_key ),
					[ 'status' => 400 ]
				);
			}

			$updated[ $map_key ] = $validated;
		}

		update_option( "cbjp_settings_{$platform}", $updated, false );

		// candidates（ASP/Woo双方の選択肢一覧）はここでは返さない。GET同様に含めるとColorMe側は
		// 保存のたびに`categories.json`/`payments.json`/`deliveries.json`の3リクエストが追加され、
		// 実行中のインポート/エクスポートジョブと`RateLimiter`のバケットを奪い合う（レート制限枯渇で
		// ジョブが一時停止しうる）。候補一覧はマッピング設定の保存操作そのものでは変化しないため、
		// フロントは直前のGETで取得した候補をそのまま使い回せばよい。
		return rest_ensure_response( $this->settings_mappings_response( $updated ) );
	}

	/**
	 * ASP側のマッピング候補一覧（D19）。アダプタ未接続等で取得に失敗した場合、マップ本体の
	 * 読み書き自体は独立して機能させたいため、エンドポイント全体を失敗させず空配列に倒す
	 * （候補が空でもUIは「未接続かもしれない」と気付けるが、保存済みマッピングの表示・編集は
	 * 妨げない）。失敗時は例外クラス名のみ（個人情報禁止ルール）を`Logger`に記録し、原因不明のまま
	 * 「候補が空」だけがUIに残るのを避ける。`PlatformAdapter::mapping_candidates()`の契約上、
	 * 非対応キーは省略されうるため常に4キー（category/payment/shipping/status）を揃えて返す。
	 *
	 * @return array<string,array<int,array{id:string,name:string}>>
	 */
	private function asp_mapping_candidates( string $platform ): array {
		$adapter = AdapterRegistry::get( $platform );

		try {
			$candidates = null !== $adapter ? $adapter->mapping_candidates() : [];
		} catch ( Throwable $exception ) {
			( new Logger() )->warning(
				'Failed to fetch ASP-side mapping candidates.',
				[
					'platform'  => $platform,
					'exception' => $exception::class,
				]
			);

			$candidates = [];
		}

		$normalized = [];

		foreach ( [ 'category', 'payment', 'shipping', 'status' ] as $key ) {
			$normalized[ $key ] = self::normalized_candidate_list( $candidates[ $key ] ?? null );
		}

		return $normalized;
	}

	/**
	 * `PlatformAdapter::mapping_candidates()`はPro拡張等の外部アダプタが実装しうる拡張点のため、
	 * 戻り値の型はdocblock上の契約でしかない（アーキテクチャ原則8）。`connection_fields()`が
	 * `instanceof ConnectionField`で防御しているのと同じ理由で、各要素が期待する形
	 * （`id`/`name`とも非空文字列化できるスカラー）かをここで検証し、不正な要素は黙って除外する
	 * （1要素でも配列でない・オブジェクトが混在すると、フロントの`Array.prototype.map`や
	 * Reactの子要素描画がそのまま例外を投げ、Exportタブ全体が落ちる）。
	 *
	 * @param mixed $value
	 * @return array<int,array{id:string,name:string}>
	 */
	private static function normalized_candidate_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['name'] ) || ! is_scalar( $item['id'] ) || ! is_scalar( $item['name'] ) ) {
				continue;
			}

			// `sanitize_settings_map()`/`validate_settings_map()`と同じ正規化を先取りして適用する。
			// 制御文字・前後の空白しか持たない値（例: `"\n"`）は素の`(string)`キャストでは非空に
			// 見えるが、保存時にはこの正規化を経て空文字列になり拒否される（またはキーだけ変わって
			// 選んだはずの項目が保存後に消える）。候補一覧の時点で保存後と同じ形に揃えておくことで、
			// 「選べるのに保存できない/違う項目として保存される」食い違いを防ぐ（G2指摘）。
			$id   = self::normalize_mapping_token( $item['id'] );
			$name = self::normalize_mapping_token( $item['name'] );

			// `id`が空文字列化する値（`''`/`false`/空白のみ等）は`SelectControl`の「未マッピング」
			// placeholder（空文字列）と衝突し、選択してもUNMAPPEDと区別できずPUT側の
			// `validate_settings_map()`が拒否する。`name`が空だと選択肢に空欄の行が並ぶ。
			// ドキュメント上の契約（非空文字列化できるスカラー）どおり、どちらか一方でも空なら
			// 要素ごと除外する。
			if ( '' === $id || '' === $name ) {
				continue;
			}

			$normalized[] = [
				'id'   => $id,
				'name' => $name,
			];
		}

		return $normalized;
	}

	/**
	 * Woo側のマッピング候補一覧（プラットフォーム非依存。D19）。
	 *
	 * @return array<string,array<int,array{id:string,name:string}>>
	 */
	private function woo_mapping_candidates(): array {
		return [
			'category' => MappingCandidates::categories(),
			'payment'  => MappingCandidates::payment_gateways(),
			'shipping' => MappingCandidates::shipping_methods(),
			'status'   => MappingCandidates::order_statuses(),
		];
	}

	/**
	 * `/runs/{run_id}/...` のパスパラメータ。`platform_param()` と同じ理由でパス由来の値だけを読む
	 * （`get_param()` はGETでもクエリ文字列の同名値をパスより優先する）。
	 */
	private function run_id_param( WP_REST_Request $request ): string {
		$run_id = $request->get_url_params()['run_id'] ?? null;

		return is_string( $run_id ) ? $run_id : '';
	}

	/**
	 * URLパスが名指ししたプラットフォーム（`(?P<platform>[a-z0-9_-]+)`）を、リクエストの
	 * 他の場所にある同名パラメータに惑わされず取得する。`WP_REST_Request::get_param()`は
	 * リクエストパラメータ種別（GET: URLよりクエリ文字列が優先、PUT/DELETE等: URLより
	 * ボディが優先。`WP_REST_Request::get_parameter_order()`参照）を跨いで同名キーを
	 * 1つにマージするため、クエリ文字列やボディに`platform`（配列値だけでなくスカラー値でも）
	 * を渡すとURLパスが指すリソースとは異なるプラットフォーム（`cbjp_token_{platform}`/
	 * `cbjp_settings_{platform}`等）を読み書きしうる（これらのルートは`args`スキーマを
	 * 定義していないため型検証がここでしか行われない）。
	 * URLキャプチャそのもの（`get_url_params()`）だけを見ることで、リクエストデータによる
	 * リソースの取り違えを構造的に防ぐ。
	 */
	private function platform_param( WP_REST_Request $request ): string {
		$platform = $request->get_url_params()['platform'] ?? null;

		return is_string( $platform ) ? $platform : '';
	}

	/**
	 * `payment_map`等が空の場合、PHPの空配列は`wp_json_encode()`でJSON配列`[]`になり、
	 * 値がある場合のJSONオブジェクト`{"3":"bacs"}`と型が食い違う（クライアント側が
	 * `Record<string,string>`として一貫した型を期待できない）。空でも常にJSONオブジェクトで
	 * 返すよう`stdClass`へキャストする（内部の配列表現はマージ処理のため`array`のまま保つ）。
	 *
	 * @param array{category_map:array<string,string>,payment_map:array<string,string>,shipping_map:array<string,string>,status_map:array<string,string>} $mappings
	 * @return array{category_map:object,payment_map:object,shipping_map:object,status_map:object}
	 */
	private function settings_mappings_response( array $mappings ): array {
		return array_map( static fn ( array $map ): object => (object) $map, $mappings );
	}

	/**
	 * @return array{category_map:array<string,string>,payment_map:array<string,string>,shipping_map:array<string,string>,status_map:array<string,string>}
	 */
	private function read_settings_mappings( string $platform ): array {
		$stored = get_option( "cbjp_settings_{$platform}", [] );
		$stored = is_array( $stored ) ? $stored : [];

		$result = [];

		foreach ( self::SETTINGS_MAP_KEYS as $map_key ) {
			$result[ $map_key ] = $this->sanitize_settings_map( $stored[ $map_key ] ?? null );
		}

		return $result;
	}

	/**
	 * DBに保存済みの値を**読む**専用の寛容なサニタイズ。過去のバグ・手動編集等でオプションが
	 * 壊れていてもエンドポイント自体を落とさないよう、不正なエントリは黙って読み飛ばす。
	 * PUTの入力検証には使わないこと（`validate_settings_map()`参照。不正なエントリを
	 * 黙って読み飛ばすと、PUTでは「1件しか送っていないのに保存後は空マップ」のような
	 * 気付きにくいデータ消失になる）。
	 *
	 * @param mixed $value
	 * @return array<string,string>
	 */
	private function sanitize_settings_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$map = [];

		foreach ( $value as $key => $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$key_string  = self::normalize_mapping_token( $key );
			$item_string = self::normalize_mapping_token( $item );

			if ( '' === $key_string || '' === $item_string ) {
				continue;
			}

			$map[ $key_string ] = $item_string;
		}

		return $map;
	}

	/**
	 * `category_map`/`payment_map`/`shipping_map`/`status_map`のキー・値、および
	 * `mapping_candidates()`が返す候補のid/nameに共通して適用する正規化（制御文字除去+前後空白除去）。
	 * `sanitize_settings_map()`（読取）・`validate_settings_map()`（書込み検証）・
	 * `normalized_candidate_list()`（候補一覧）の3箇所で同じ正規化を使うことで、候補一覧の時点で
	 * 「保存後と同じ形」を保証し、制御文字・空白のみの値が保存時にだけ空文字列化してエントリが
	 * 消える／別のキーとして保存される食い違いを防ぐ（G2指摘）。
	 */
	private static function normalize_mapping_token( mixed $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) );
	}

	/**
	 * PUTで送られてきたマップを**書き込み用に**検証する。`sanitize_settings_map()`と異なり
	 * 1件でも不正なエントリ（非スカラー値、制御文字除去後に空文字列になるキー/値）があれば
	 * 黙って読み飛ばさずnullを返し、呼び出し元にリクエスト全体を拒否させる
	 * （境界データはフェイルクローズで検証する。CLAUDE.md参照。Codexレビュー指摘）。
	 *
	 * @param array<int|string,mixed> $value
	 * @return ?array<string,string>
	 */
	private function validate_settings_map( array $value ): ?array {
		$map = [];

		foreach ( $value as $key => $item ) {
			if ( ! is_scalar( $item ) ) {
				return null;
			}

			$key_string  = self::normalize_mapping_token( $key );
			$item_string = self::normalize_mapping_token( $item );

			if ( '' === $key_string || '' === $item_string ) {
				return null;
			}

			$map[ $key_string ] = $item_string;
		}

		return $map;
	}

	public function test_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->platform_param( $request );
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

	/**
	 * OAuth型プラットフォーム（現状: colorme）の認可URLを返す。`mode=oob` はローカル開発等、
	 * 自動コールバックのhttpsリダイレクトURIを用意できない環境向けのフォールバック
	 * （要検証#7が未確定のため、自動リダイレクトが使えるかは環境依存。03 §9）。
	 */
	public function get_authorize_url( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->platform_param( $request );
		$oauth    = $this->oauth_for( $platform );

		if ( null === $oauth ) {
			return $this->unknown_platform_error( $platform );
		}

		$is_oob       = 'oob' === $request->get_param( 'mode' );
		$redirect_uri = $is_oob ? ColorMeOAuth::OOB_REDIRECT_URI : $this->oauth_callback_url( $platform );

		try {
			$url = $oauth->authorize_url( $redirect_uri, $is_oob ? null : get_current_user_id() );
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'cbjp_oauth_not_configured', $exception->getMessage(), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'url'          => $url,
				'redirect_uri' => $redirect_uri,
			]
		);
	}

	/**
	 * ASPからの外部リダイレクト（`__return_true`、nonce・capability対象外）。
	 * `state` ワンタイムトークンのみで検証する（03 §6）。302 + Locationヘッダーで
	 * 管理画面のConnectionsタブへ戻す（`exit`を伴う`wp_safe_redirect()`は使わず、
	 * REST応答として返すことでユニットテスト可能にしている）。
	 */
	public function handle_oauth_callback( WP_REST_Request $request ): WP_REST_Response {
		$platform = $this->platform_param( $request );
		$oauth    = $this->oauth_for( $platform );

		if ( null === $oauth ) {
			return $this->redirect_to_connections( [ 'cbjp_connect_error' => __( 'Unknown platform.', 'cart-bridge-jp' ) ] );
		}

		$code  = (string) ( $this->scalar_query_param( $request, 'code' ) ?? '' );
		$state = (string) ( $this->scalar_query_param( $request, 'state' ) ?? '' );

		// stateはcodeの有無より先に検証・消費する。ユーザーが認可を拒否した等の
		// code無しコールバックでstateを放置すると、TTLが切れるまで再利用可能なまま残る。
		$state_valid = '' !== $state && $oauth->verify_state( $state );

		if ( '' === $code || ! $state_valid ) {
			return $this->redirect_to_connections(
				[ 'cbjp_connect_error' => __( 'The connection request could not be verified. Please try again, or use the manual code entry fallback.', 'cart-bridge-jp' ) ]
			);
		}

		try {
			$oauth->exchange_code( $code, $this->oauth_callback_url( $platform ) );
		} catch ( Throwable $exception ) {
			return $this->redirect_to_connections( [ 'cbjp_connect_error' => $exception->getMessage() ] );
		}

		return $this->redirect_to_connections( [ 'cbjp_connected' => $platform ] );
	}

	/**
	 * OAuthコード手動貼り付けフォールバック。認証済み管理画面からのPOSTのため
	 * 通常のnonce+capability保護のみで、独自のstate検証は行わない。
	 */
	public function exchange_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->platform_param( $request );
		$oauth    = $this->oauth_for( $platform );

		if ( null === $oauth ) {
			return $this->unknown_platform_error( $platform );
		}

		$input = (string) ( $this->scalar_query_param( $request, 'code' ) ?? '' );

		if ( '' === $input ) {
			return new WP_Error(
				'cbjp_invalid_request',
				__( 'A code (or the authorization URL containing it) is required.', 'cart-bridge-jp' ),
				[ 'status' => 400 ]
			);
		}

		try {
			$oauth->exchange_code( $oauth->extract_code_from_input( $input ), ColorMeOAuth::OOB_REDIRECT_URI );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'cbjp_oauth_exchange_failed', $exception->getMessage(), [ 'status' => 400 ] );
		}

		return rest_ensure_response( [ 'connected' => true ] );
	}

	/**
	 * 登録済みプラットフォームのOAuthハンドラを返す。OAuth非対応（makeshop等）や未登録platformはnull。
	 */
	private function oauth_for( string $platform ): ?ColorMeOAuth {
		return match ( $platform ) {
			ColorMeAdapter::ID => ColorMeOAuth::for_platform(),
			default => null,
		};
	}

	private function oauth_callback_url( string $platform ): string {
		return rest_url( self::NAMESPACE . '/connect/' . $platform . '/callback' );
	}

	/**
	 * @param array<string,string> $query_args
	 */
	private function redirect_to_connections( array $query_args ): WP_REST_Response {
		$url = add_query_arg( $query_args, admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) . '#/connections';

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );

		return $response;
	}

	public function start_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// このルートは`args`スキーマ（type検証）を定義していないため、`type`/`platform`は
		// `?type[]=import`のように配列でも渡り得る。配列を`(string)`キャストすると
		// "Array to string conversion" 警告付きでリテラル文字列"Array"になり、意図しない
		// 404/400を誤答してしまう（CLAUDE.md参照）。スカラーのみ受け付け、それ以外は
		// 「未指定」として下流の検証（400）に委ねる。
		$type         = (string) ( $this->scalar_query_param( $request, 'type' ) ?? '' );
		$platform     = (string) ( $this->scalar_query_param( $request, 'platform' ) ?? '' );
		$entities_raw = $request->get_param( 'entities' );
		$entities     = is_array( $entities_raw ) ? array_values( array_filter( $entities_raw, 'is_scalar' ) ) : [];

		if ( ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( $platform );
		}

		// エクスポート実行前の本番書込み警告（D17）のサーバー側担保: 無料版のサンプル10件でも
		// ASP本番環境へ実際に書き込むため、UI（E2-4の確認ダイアログ）が確認を得たことを示す
		// フラグを必須にする。dry-run（`dry_run_export`）は何も書き込まないため対象外。
		// プラットフォーム存在チェックの後に置く: 不正なplatform + type=exportのリクエストが
		// 「未確認」ではなく「不明なプラットフォーム」として先に誤答されないようにする。
		if ( JobManager::TYPE_EXPORT === $type && ! $this->acknowledged_production_write( $request ) ) {
			return new WP_Error(
				'cbjp_export_not_acknowledged',
				__( 'Exporting writes to the connected shop right away. Confirm the warning before running an export.', 'cart-bridge-jp' ),
				[ 'status' => 400 ]
			);
		}

		try {
			$run_id = JobManager::create()->start_run( $type, $platform, array_map( 'strval', $entities ) );
		} catch ( RunAlreadyInProgressException ) {
			return $this->run_in_progress_error();
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
		$run_id = $this->run_id_param( $request );
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

	/**
	 * dry-run結果のCSVダウンロード（D17）。JSON化してJS側でBlob化する方式は採らず、
	 * サーバー側で`Content-Disposition`を吐いてストリーミングする
	 * （数万行を配列/JSON文字列として複数回メモリに載せるのを避けるため）。
	 *
	 * `<a download href>`はカスタムヘッダー（`X-WP-Nonce`）を送れないため、WP REST の
	 * cookie認証が受け付ける`?_wpnonce=`クエリパラメータ経由での呼び出しを想定する
	 * （`rest_cookie_check_errors`）。`fetch`+Blob経路（`X-WP-Nonce`ヘッダー）でも同じ
	 * ルートがそのまま使える。
	 */
	public function get_run_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run_id = $this->run_id_param( $request );

		if ( [] === ( new JobRepository() )->find_by_run( $run_id ) ) {
			return new WP_Error( 'cbjp_run_not_found', __( 'Run not found.', 'cart-bridge-jp' ), [ 'status' => 404 ] );
		}

		$entity        = $this->scalar_query_param( $request, 'entity' );
		$entity        = is_string( $entity ) && in_array( $entity, self::ENTITY_TYPES, true ) ? $entity : null;
		$only_warnings = (bool) $request->get_param( 'only_warnings' );

		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_file_name( "cart-bridge-jp-dry-run-{$run_id}.csv" ) . '"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );

		$exporter = new DryRunReportCsv( new DryRunItemRepository() );

		// `rest_pre_serve_request` はWP RESTでJSON以外のレスポンスボディ（CSV等）を返すための
		// 正規の手段。このルートのレスポンスに限定してJSONシリアライズを迂回する。
		// `rest_pre_serve_request`は`WP_REST_Server::serve_request()`経由のリクエストでのみ
		// 発火し、`rest_do_request()`/`$server->dispatch()`直接呼び出し（PHPUnitのREST
		// テスト等）では発火しないため、その場合コールバックは`remove_filter()`されないまま
		// 残留する。「1回発火したら自身を外す」方式だけに頼ると、残留したコールバックが
		// 同一PHPプロセス内の後続の無関係なリクエスト（次に`rest_pre_serve_request`が
		// 実際に発火したとき）のCSVを誤って横取りしてしまう。この応答オブジェクト自身
		// （`$response`、オブジェクト同一性で判定）宛のときだけ動作するようガードし、
		// 一致しない呼び出しには手を出さず素通りさせる。第2引数の型は`WP_REST_Response`ではなく
		// コア側のフィルター契約通り`WP_HTTP_Response`にする: `rest_post_dispatch`は任意の
		// プラグイン/ルートがフックできる汎用フィルターで、他ルートの応答が素の
		// `WP_HTTP_Response`（`WP_REST_Response`のサブクラスでない）としてここに渡ってくる
		// ことがありうる。`WP_REST_Response`で型宣言すると、この残留コールバックが無関係な
		// ルートで発火した際、`!==`比較に達する前に`TypeError`で落ちてしまう。
		$callback = null;
		$callback = static function ( bool $served, WP_HTTP_Response $result ) use ( $exporter, $run_id, $entity, $only_warnings, $response, &$callback ): bool {
			if ( $result !== $response ) {
				return $served;
			}

			remove_filter( 'rest_pre_serve_request', $callback );
			$exporter->stream( $run_id, $entity, $only_warnings );

			return true;
		};
		add_filter( 'rest_pre_serve_request', $callback, 10, 2 );

		return $response;
	}

	public function cancel_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run_id     = $this->run_id_param( $request );
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
		$id         = (int) ( $request->get_url_params()['id'] ?? 0 );
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
	 * `acknowledge_production_write`パラメータの検証（D17の本番書込み警告のサーバー側担保）。
	 * JSONボディ経由ではPHP boolに、クエリ/フォーム経由では文字列になりうるため両方を扱うが、
	 * `(bool)`キャストは使わない（`(bool)'0'`がfalse、`(bool)'false'`がtrueになる罠。CLAUDE.md）。
	 * 既知の肯定値以外（未指定・`'0'`・`'false'`・非スカラー等）はすべてフェイルクローズでfalseにする。
	 */
	private function acknowledged_production_write( WP_REST_Request $request ): bool {
		$value = $this->scalar_query_param( $request, 'acknowledge_production_write' );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( $value, [ '1', 'true' ], true );
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

	/**
	 * `GET /runs/{run_id}/verification`: 移行後検証レポート（D17）。import の run のみ対象
	 * （dry-run は Woo 側に何も書かないため突合対象が無い）。
	 */
	public function get_run_verification( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run_id = $this->run_id_param( $request );
		$report = ( new VerificationReport( new JobRepository(), new MappingRepository() ) )->build( $run_id );

		if ( null === $report ) {
			return new WP_Error( 'cbjp_run_not_found', __( 'Run not found.', 'cart-bridge-jp' ), [ 'status' => 404 ] );
		}

		if ( JobManager::TYPE_IMPORT !== $report['type'] ) {
			return new WP_Error(
				'cbjp_verification_unavailable',
				__( 'The verification report is only available for import runs.', 'cart-bridge-jp' ),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( $report );
	}

	/**
	 * `GET /tools/sample-cleanup?platform=`: 削除件数のプレビュー（§10.3「実行前に削除件数を表示して確認を取る」）。
	 */
	public function preview_sample_cleanup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->tool_platform( $request );

		if ( $platform instanceof WP_Error ) {
			return $platform;
		}

		$preview = ( new SampleCleanup( new MappingRepository() ) )->preview( $platform );

		return rest_ensure_response(
			array_merge(
				[
					'platform'        => $platform,
					'run_in_progress' => ( new JobRepository() )->has_active_job_for_platform( $platform ),
				],
				$preview
			)
		);
	}

	/**
	 * `POST /tools/sample-cleanup`: 1バッチ分の削除。`has_more` が true の間、UI が繰り返し呼ぶ。
	 */
	public function run_sample_cleanup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->tool_platform( $request );

		if ( $platform instanceof WP_Error ) {
			return $platform;
		}

		// 実行中の import と同時に削除すると、mappings を消した直後に Importer が同じ remote_id を
		// 「未作成」とみなして作り直す等の競合が起きるため、進行中のジョブがある間は拒否する。
		if ( ( new JobRepository() )->has_active_job_for_platform( $platform ) ) {
			return $this->run_in_progress_error();
		}

		try {
			$result = ( new SampleCleanup( new MappingRepository() ) )->run( $platform );
		} catch ( CleanupNotPermittedException ) {
			return new WP_Error(
				'cbjp_cleanup_forbidden',
				__( 'Customer accounts created by the import can only be deleted by a user who is allowed to delete users. Ask an administrator to run the cleanup.', 'cart-bridge-jp' ),
				[ 'status' => 403 ]
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * `POST /tools/rebuild-mappings`: 1バッチ分の再構築。`cursor` が null になるまで UI が繰り返し呼ぶ。
	 */
	public function rebuild_mappings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = $this->tool_platform( $request );

		if ( $platform instanceof WP_Error ) {
			return $platform;
		}

		if ( ( new JobRepository() )->has_active_job_for_platform( $platform ) ) {
			return $this->run_in_progress_error();
		}

		$cursor = $request->get_param( 'cursor' );

		try {
			$result = ( new MappingRebuilder( new MappingRepository() ) )->run( $platform, is_string( $cursor ) ? $cursor : null );
		} catch ( InvalidArgumentException ) {
			return new WP_Error( 'cbjp_invalid_cursor', __( 'The rebuild cursor is invalid.', 'cart-bridge-jp' ), [ 'status' => 400 ] );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * `GET /tools/repair-states?platform=&cursor=`: 県コード修復の Scan（読取専用）。補正が必要な件数を数える。
	 * `cursor` が null になるまで UI が繰り返し呼ぶ。
	 */
	public function scan_state_repair( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->repair_states( $request, false );
	}

	/**
	 * `POST /tools/repair-states`: 県コード修復の実行（`state` のみ補正）。`cursor` が null になるまで UI が繰り返し呼ぶ。
	 */
	public function run_state_repair( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->repair_states( $request, true );
	}

	private function repair_states( WP_REST_Request $request, bool $apply ): WP_REST_Response|WP_Error {
		$platform = $this->tool_platform( $request );

		if ( $platform instanceof WP_Error ) {
			return $platform;
		}

		// 進行中のジョブとは ASP のレート制限（プラットフォーム単位で共有）を奪い合い、import が同じ
		// 実体を書いている最中に補正すると競合しうるため、Scan（読取専用）も含めて拒否する。
		if ( ( new JobRepository() )->has_active_job_for_platform( $platform ) ) {
			return $this->run_in_progress_error();
		}

		$adapter = AdapterRegistry::get( $platform );

		if ( null === $adapter ) {
			return $this->unknown_platform_error( $platform );
		}

		$cursor = $request->get_param( 'cursor' );

		try {
			$result = ( new PrefStateRepair( new MappingRepository(), $adapter ) )->run( $platform, $apply, is_string( $cursor ) ? $cursor : null );
		} catch ( InvalidArgumentException ) {
			return new WP_Error( 'cbjp_invalid_cursor', __( 'The repair cursor is invalid.', 'cart-bridge-jp' ), [ 'status' => 400 ] );
		} catch ( UnsupportedOperationException ) {
			return $this->repair_not_applicable_error();
		}

		if ( null !== $result['interruption'] ) {
			return $this->repair_interrupted_response( $result );
		}

		return rest_ensure_response(
			[
				'platform' => $platform,
				'apply'    => $apply,
				'counts'   => $result['counts'],
				'cursor'   => $result['cursor'],
			]
		);
	}

	/**
	 * ASP への照会に失敗して中断した場合の応答。エラーとして返しつつ、処理済みの件数と失敗した行を指す
	 * cursor をボディに含める（UI は件数を失わず、同じ位置から再開できる。処理は冪等）。
	 *
	 * @param array{counts:array<string,array<string,int>>,cursor:?string,interruption:?string} $result
	 */
	private function repair_interrupted_response( array $result ): WP_REST_Response|WP_Error {
		$reason = (string) $result['interruption'];

		if ( RepairInterruptedException::UNSUPPORTED === $reason ) {
			return $this->repair_not_applicable_error();
		}

		[ $code, $status, $message ] = match ( $reason ) {
			RepairInterruptedException::RATE_LIMITED  => [
				'cbjp_rate_limited',
				503,
				__( 'The platform API rate limit was reached. Wait a minute, then continue; it resumes where it stopped.', 'cart-bridge-jp' ),
			],
			RepairInterruptedException::NOT_CONNECTED => [
				'cbjp_not_connected',
				409,
				__( 'The platform connection is missing or has expired. Reconnect it on the Connections tab, then continue.', 'cart-bridge-jp' ),
			],
			default                                   => [
				'cbjp_platform_api_error',
				502,
				__( 'The platform API returned an error. Try again in a moment; it resumes where it stopped.', 'cart-bridge-jp' ),
			],
		};

		$response = new WP_REST_Response(
			[
				'code'    => $code,
				'message' => $message,
				'data'    => [
					'status'       => $status,
					'counts'       => $result['counts'],
					'cursor'       => $result['cursor'],
					'interruption' => $reason,
				],
			],
			$status
		);

		if ( RepairInterruptedException::RATE_LIMITED === $reason ) {
			$response->header( 'Retry-After', '60' );
		}

		return $response;
	}

	private function repair_not_applicable_error(): WP_Error {
		return new WP_Error(
			'cbjp_repair_not_applicable',
			__( 'This platform does not need prefecture repair.', 'cart-bridge-jp' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * ツール系ルートの `platform`（クエリ/ボディ由来。`args` スキーマで文字列であることは REST 層が保証済み）。
	 */
	private function tool_platform( WP_REST_Request $request ): string|WP_Error {
		$platform = $request->get_param( 'platform' );

		if ( ! is_string( $platform ) || ! AdapterRegistry::has( $platform ) ) {
			return $this->unknown_platform_error( is_string( $platform ) ? $platform : '' );
		}

		return $platform;
	}

	private function run_in_progress_error(): WP_Error {
		return new WP_Error(
			'cbjp_run_in_progress',
			__( 'A run is already in progress for this platform.', 'cart-bridge-jp' ),
			[ 'status' => 409 ]
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
