<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe;

use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\HttpClient;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\RateLimiter;
use CartBridgeJP\Support\TokenStore;
use Throwable;

/**
 * カラーミーショップ OAuth2 認可コードフロー（`01-plan-colorme.md` §1 / swagger.json 添付ドキュメント）。
 *
 * - アクセストークンは無期限（リフレッシュ処理不要。取得したら `TokenStore` にそのまま保存する）
 * - 認可コードは発行から10分・1回のみ交換可能
 * - スコープは無料版が使う商品のものだけを要求し、拡張（Pro アドオン）が `cbjp/oauth/scopes` で足す。付与されたスコープをトークンと一緒に
 *   記録し、要求するスコープが足りない接続には再接続を促す（R3-6c2。`docs/03-design-decisions.md` §10.0 決め残し 9）
 * - リダイレクトURIに `urn:ietf:wg:oauth:2.0:oob`（{@see self::OOB_REDIRECT_URI}）を指定した場合、
 *   カラーミー側は自サイトの `https://api.shop-pro.jp/oauth/authorize/{code}` へリダイレクトし、
 *   コード末尾がそのまま認可コードになる。これをローカル開発等、httpsの公開コールバックURLを
 *   用意できない環境向けの「コード手動貼り付け」フォールバックとして利用する
 *   （要検証#7=ローカル開発でのhttpリダイレクトURI可否は本実装時点で未確定。詳細は 03 §9）
 */
final class ColorMeOAuth {

	private const AUTHORIZE_URL = 'https://api.shop-pro.jp/oauth/authorize';
	private const TOKEN_URL     = 'https://api.shop-pro.jp/oauth/token';

	/**
	 * 無料版が要求するスコープ（商品・在庫の読取りと更新）。拡張（`cbjp/oauth/scopes`）でも外せない（R3-6c2。`docs/03` §10.0 決め残し 9）。
	 */
	public const BASE_SCOPES = [ 'read_products', 'write_products' ];

	/**
	 * R3-6c2 より前の全版が要求したスコープ。付与されたスコープを記録していない（その版が保存した）トークンはこれを持つとみなす
	 * （ColorMe の認可画面はスコープを選ばせないので、要求したものがそのまま付与された）。
	 */
	public const LEGACY_SCOPES = [ 'read_products', 'write_products', 'read_sales', 'write_sales', 'read_shop_coupons' ];

	/**
	 * ColorMe のスコープ（`tests/fixtures/colorme/swagger.json` の `info.description` の表）。拡張が足せるのはこれだけで、要求はこの順に並べる。
	 */
	public const KNOWN_SCOPES = [
		'read_products',
		'write_products',
		'read_sales',
		'write_sales',
		'read_shop_coupons',
		'write_shop_coupons',
		'read_templates',
		'write_templates',
	];

	/**
	 * 要求するスコープを足すフィルター（`( array $scopes, string $platform )`）。Pro アドオンが顧客・受注・クーポンのスコープを足す口。
	 */
	public const SCOPES_FILTER = 'cbjp/oauth/scopes';

	private const RATE_LIMIT_PER_MINUTE = 100;

	private const STATE_TTL_SECONDS = 600;

	public const OOB_REDIRECT_URI = 'urn:ietf:wg:oauth:2.0:oob';

	public function __construct(
		private readonly TokenStore $token_store,
		private readonly HttpClient $http_client
	) {}

	public static function for_platform(): self {
		return new self(
			new TokenStore( 'colorme' ),
			new HttpClient( new RateLimiter( 'colorme', self::RATE_LIMIT_PER_MINUTE ) )
		);
	}

	public function has_credentials(): bool {
		$settings = $this->token_store->settings();

		return '' !== ( $settings['client_id'] ?? '' ) && '' !== ( $settings['client_secret'] ?? '' );
	}

	public function save_credentials( string $client_id, string $client_secret ): void {
		$this->token_store->save_settings(
			[
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			]
		);
	}

	/**
	 * 認可で要求するスコープ（R3-6c2）。無料版の `BASE_SCOPES` に、拡張が `cbjp/oauth/scopes` で足したものを加える
	 * （Pro アドオンは有効なときに顧客・受注・クーポンのスコープを足す。無料版だけのサイトは使わない受注・顧客への権限を求めない）。
	 *
	 * フィルターの戻り値は信用しない（原則 8）: 例外・配列でない戻り値は拡張の分を捨てて `BASE_SCOPES` に倒し、`KNOWN_SCOPES` に無い値は
	 * その値だけ捨てる（スコープは互いに独立で、要求しなかったスコープは、それを要る拡張の機能が使えないだけ。別の拡張の誤りで正しい拡張の
	 * スコープまで捨てない）。`BASE_SCOPES` は戻り値に無くても外さない。並びは `KNOWN_SCOPES` の順で、同じ組なら同じ文字列になる。
	 *
	 * @return array<int,string>
	 */
	public static function scopes(): array {
		try {
			// `self::SCOPES_FILTER`。PHPCS がフック名の接頭辞を定数では検査できないので文字列で書く。
			$filtered = apply_filters( 'cbjp/oauth/scopes', self::BASE_SCOPES, ColorMeAdapter::ID );
		} catch ( Throwable $exception ) {
			self::log_rejected_scopes( $exception::class );

			return self::BASE_SCOPES;
		}

		if ( ! is_array( $filtered ) ) {
			self::log_rejected_scopes( 'not_an_array' );

			return self::BASE_SCOPES;
		}

		$requested = self::BASE_SCOPES;
		$unknown   = 0;

		foreach ( $filtered as $scope ) {
			if ( ! is_string( $scope ) || ! in_array( $scope, self::KNOWN_SCOPES, true ) ) {
				++$unknown;

				continue;
			}

			$requested[] = $scope;
		}

		// 1 回の呼び出しにつき 1 行（`GET /connections` はタブを開くたびに呼ばれる。値ごとに書くと Logs タブが埋まる。R3-6c2 review-loop R1-4）。
		if ( $unknown > 0 ) {
			self::log_rejected_scopes( 'unknown_scope', $unknown );
		}

		return array_values( array_intersect( self::KNOWN_SCOPES, $requested ) );
	}

	/**
	 * `$store` のトークンに付与されたスコープ（R3-6c2）。付与されたスコープを記録していない接続済みのトークン（R3-6c2 より前の版が保存した）は
	 * `LEGACY_SCOPES`（その版はすべて 5 つを要求した）。
	 *
	 * @return array<int,string>|null 未接続（要再接続を含む）は null。
	 */
	public static function granted_scopes_in( TokenStore $store ): ?array {
		if ( ! $store->is_connected() ) {
			return null;
		}

		return $store->granted_scopes() ?? self::LEGACY_SCOPES;
	}

	/**
	 * 要求するスコープ（{@see self::scopes()}）のうち、保存したトークンに付与されていないもの（R3-6c2）。空でなければ管理画面が再接続を促す
	 * （`GET /connections` の `missing_scopes`）。未接続は空（未接続・要再接続の案内は別にある）。
	 *
	 * @return array<int,string>
	 */
	public function missing_scopes(): array {
		$granted = self::granted_scopes_in( $this->token_store );

		if ( null === $granted ) {
			return [];
		}

		return array_values( array_diff( self::scopes(), $granted ) );
	}

	private static function log_rejected_scopes( string $reason, int $count = 1 ): void {
		( new Logger() )->warning(
			'Ignored OAuth scopes added by an extension.',
			[
				'platform' => ColorMeAdapter::ID,
				'reason'   => $reason,
				'count'    => $count,
			]
		);
	}

	/**
	 * @throws \RuntimeException client_id/client_secret が未設定の場合。
	 */
	public function authorize_url( string $redirect_uri, ?int $state_user_id = null ): string {
		$settings      = $this->token_store->settings();
		$client_id     = (string) ( $settings['client_id'] ?? '' );
		$client_secret = (string) ( $settings['client_secret'] ?? '' );

		if ( '' === $client_id || '' === $client_secret ) {
			throw new \RuntimeException( 'ColorMe client_id/client_secret are not configured yet.' );
		}

		$scopes = self::scopes();
		$args   = [
			'response_type' => 'code',
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'scope'         => implode( ' ', $scopes ),
		];

		// stateはコールバックでのCSRF検証用（03 §6）。OOBフローは我々への redirect が発生しないため
		// 検証しようがなく、呼び出し側（$state_user_id省略）で生成をスキップできるようにする。
		if ( null !== $state_user_id ) {
			$args['state'] = $this->issue_state( $state_user_id );
		}

		// トークン応答に `scope` が無いときに記録する「要求したスコープ」を、この認可の単位で控える（交換の時点で求め直すと、その間に拡張を
		// 有効・無効にしたとき実際の要求とずれる。R3-6c2 G1-1）。リダイレクトは state ごと、OOB（state が無い）はコードを貼り付ける管理者
		// （今のユーザー）ごと。期限は認可コードと同じ 10 分。
		set_transient( $this->requested_scopes_key( $args['state'] ?? null ), $scopes, self::STATE_TTL_SECONDS );

		// add_query_arg()は値をurlencodeしない（WP側の既知の挙動）ため、PHP標準の
		// http_build_query()でクエリ文字列を組み立てる。セパレータは明示（ini設定
		// arg_separator.output=&amp; の環境で `amp;client_id` になるのを防ぐ）。
		return self::AUTHORIZE_URL . '?' . http_build_query( $args, '', '&' );
	}

	/**
	 * `state` を一度きりの transient として発行する（管理ユーザーIDに紐付け、10分）。
	 */
	private function issue_state( int $user_id ): string {
		$state = wp_generate_password( 32, false );

		set_transient( $this->state_transient_key( $state ), $user_id, self::STATE_TTL_SECONDS );

		return $state;
	}

	/**
	 * 一度きりの検証（成功・失敗いずれの場合もtransientを消費する）。
	 *
	 * ASPからの外部リダイレクトで叩かれるコールバックはREST cookie認証のnonceを
	 * 持たないため、WordPressは`get_current_user_id()`をリクエスト単位で0にリセットする
	 * （`rest_cookie_check_errors()`）。よって「発行時の管理ユーザーIDと突き合わせる」検証は
	 * 本番のコールバックで必ず失敗する。stateトークン自体が`get_authorize_url()`
	 * （nonce+capability保護下）でのみ発行される一度きりの乱数のため、存在確認のみで
	 * CSRF対策として十分である。
	 */
	public function verify_state( string $state ): bool {
		$key         = $this->state_transient_key( $state );
		$stored_user = get_transient( $key );

		delete_transient( $key );

		return false !== $stored_user;
	}

	private function state_transient_key( string $state ): string {
		return 'cbjp_colorme_oauth_state_' . $state;
	}

	/**
	 * 認可で要求したスコープの控え（{@see self::authorize_url()}）の transient のキー。`$state` が null（OOB）なら今のユーザーごと。
	 */
	private function requested_scopes_key( ?string $state ): string {
		return 'cbjp_colorme_oauth_scopes_' . ( null === $state ? 'user_' . get_current_user_id() : $state );
	}

	/**
	 * 認可で要求したスコープの控えを読んで消す（一度きり）。無い・期限切れ・読めない控えは空（何を要求したか分からない。トークン応答に
	 * `scope` も無ければ何も付与されていない扱いになり、管理画面が再接続を促す。フェイルクローズ）。
	 *
	 * @return array<int,string>
	 */
	private function take_requested_scopes( ?string $state ): array {
		$key    = $this->requested_scopes_key( $state );
		$scopes = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $scopes ) || ! array_is_list( $scopes ) ) {
			return [];
		}

		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) ) {
				return [];
			}
		}

		return $scopes;
	}

	/**
	 * カラーミー側のOOBリダイレクト先 `https://api.shop-pro.jp/oauth/authorize/{code}` や、
	 * ユーザーが貼り付けた生の認可コードの両方を受け付ける。
	 */
	public function extract_code_from_input( string $input ): string {
		$trimmed = trim( $input );
		$path    = (string) wp_parse_url( $trimmed, PHP_URL_PATH );

		if ( '' === $path ) {
			return $trimmed;
		}

		$segments = explode( '/', rtrim( $path, '/' ) );

		return (string) end( $segments );
	}

	/**
	 * @param string      $code         認可コード。
	 * @param string      $redirect_uri 認可で使ったリダイレクト URI。
	 * @param string|null $state        リダイレクトの認可の state（{@see self::verify_state()} で確かめた後）。OOB は null（今のユーザーの控えを使う）。
	 * @throws ApiException トークン交換に失敗した場合。
	 */
	public function exchange_code( string $code, string $redirect_uri, ?string $state = null ): void {
		$settings      = $this->token_store->settings();
		$client_id     = (string) ( $settings['client_id'] ?? '' );
		$client_secret = (string) ( $settings['client_secret'] ?? '' );

		if ( '' === $client_id || '' === $client_secret ) {
			throw new \RuntimeException( 'ColorMe client_id/client_secret are not configured yet.' );
		}

		// 応答に `scope` が無いときに記録する値（要求したとおりに付与された。RFC 6749 §5.1）。認可を始めたときに控えたもの（R3-6c2 G1-1。
		// ColorMe の応答は `scope` を含む〔swagger の説明〕ので、通常はこちらを使わない）。
		$requested = $this->take_requested_scopes( $state );

		$body = [
			'grant_type'    => 'authorization_code',
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'code'          => $code,
			'redirect_uri'  => $redirect_uri,
		];

		try {
			$response = $this->http_client->request(
				'POST',
				self::TOKEN_URL,
				[
					'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
					'body'    => http_build_query( $body, '', '&' ),
				]
			);
		} catch ( ApiException $exception ) {
			throw $this->translate_exception( $exception );
		}

		$decoded = json_decode( $response['body'], true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['access_token'] ) || ! is_string( $decoded['access_token'] ) || '' === $decoded['access_token'] ) {
			throw new ApiException( 'ColorMe token exchange returned an unexpected response.', $response['status'] );
		}

		// トークンPOSTの往復中に管理者が資格情報を削除・変更している可能性がある。
		// 交換開始時に読んだスナップショットを書き戻すと、削除済み資格情報の復活や
		// 新しい設定の上書きが起きるため、TokenStore側のCAS（トークン取得に使った
		// client_id/secretが現在も保存されている場合のみ原子的に保存。あわせて
		// 前のショップのextrasを破棄）に委ねる。付与されたスコープも同じ書込みで記録する（R3-6c2）。
		$saved = $this->token_store->save_token_if_credentials_match(
			$client_id,
			$client_secret,
			$decoded['access_token'],
			self::granted_scopes_from( $decoded, $requested )
		);

		if ( ! $saved ) {
			throw new \RuntimeException(
				'The stored credentials were changed or removed while the token exchange was in flight. Please try connecting again.'
			);
		}
	}

	/**
	 * トークン応答から、付与されたスコープを読む（R3-6c2）。`scope` が無い（null を含む）ときは要求したとおりに付与されている（RFC 6749 §5.1）。
	 * 文字列でない `scope` は読めないので、何も付与されていない扱いにする（フェイルクローズ。管理画面が再接続を促す側に倒れる）。
	 *
	 * @param array<mixed>      $response  トークン応答。
	 * @param array<int,string> $requested 要求したスコープ。
	 * @return array<int,string>
	 */
	private static function granted_scopes_from( array $response, array $requested ): array {
		if ( ! isset( $response['scope'] ) ) {
			return $requested;
		}

		if ( ! is_string( $response['scope'] ) ) {
			return [];
		}

		$scopes = preg_split( '/\s+/', $response['scope'], -1, PREG_SPLIT_NO_EMPTY );

		return false === $scopes ? [] : array_values( array_unique( $scopes ) );
	}

	/**
	 * {@see ColorMeClient::translate_exception()} と同じカラーミーエラー形式の変換。
	 * トークンエンドポイントは `/v1/` 配下のClientを経由しないため個別に持つ。
	 */
	private function translate_exception( ApiException $exception ): ApiException {
		$body    = $exception->context()['body'] ?? null;
		$decoded = is_string( $body ) ? json_decode( $body, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['errors'][0] ) || ! is_array( $decoded['errors'][0] ) ) {
			return $exception;
		}

		$first = $decoded['errors'][0];

		return new ApiException(
			isset( $first['message'] ) ? (string) $first['message'] : $exception->getMessage(),
			$exception->status_code(),
			array_merge(
				$exception->context(),
				[ 'colorme_error_code' => isset( $first['code'] ) ? (int) $first['code'] : 0 ]
			),
			$exception
		);
	}
}
