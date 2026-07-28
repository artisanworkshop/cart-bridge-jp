<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe;

use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\HttpClient;
use CartBridgeJP\Support\RateLimiter;
use CartBridgeJP\Support\TokenStore;

/**
 * カラーミーショップ OAuth2 認可コードフロー（`01-plan-colorme.md` §1 / swagger.json 添付ドキュメント）。
 *
 * - アクセストークンは無期限（リフレッシュ処理不要。取得したら `TokenStore` にそのまま保存する）
 * - 認可コードは発行から10分・1回のみ交換可能
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
	 * 01-plan-colorme.md §1で確定したスコープ。
	 */
	private const SCOPE = 'read_products write_products read_sales write_sales read_shop_coupons';

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
	 * @throws \RuntimeException client_id が未設定の場合。
	 */
	public function authorize_url( string $redirect_uri, ?int $state_user_id = null ): string {
		$client_id = (string) ( $this->token_store->settings()['client_id'] ?? '' );

		if ( '' === $client_id ) {
			throw new \RuntimeException( 'ColorMe client_id is not configured yet.' );
		}

		$args = [
			'response_type' => 'code',
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'scope'         => self::SCOPE,
		];

		// stateはコールバックでのCSRF検証用（03 §6）。OOBフローは我々への redirect が発生しないため
		// 検証しようがなく、呼び出し側（$state_user_id省略）で生成をスキップできるようにする。
		if ( null !== $state_user_id ) {
			$args['state'] = $this->issue_state( $state_user_id );
		}

		// add_query_arg()は値をurlencodeしない（WP側の既知の挙動）ため、PHP標準の
		// http_build_query()でクエリ文字列を組み立てる。
		return self::AUTHORIZE_URL . '?' . http_build_query( $args );
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
	 */
	public function verify_state( string $state, int $user_id ): bool {
		$key         = $this->state_transient_key( $state );
		$stored_user = get_transient( $key );

		delete_transient( $key );

		return false !== $stored_user && (int) $stored_user === $user_id;
	}

	private function state_transient_key( string $state ): string {
		return 'cbjp_colorme_oauth_state_' . $state;
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
	 * @throws ApiException トークン交換に失敗した場合。
	 */
	public function exchange_code( string $code, string $redirect_uri ): void {
		$settings      = $this->token_store->settings();
		$client_id     = (string) ( $settings['client_id'] ?? '' );
		$client_secret = (string) ( $settings['client_secret'] ?? '' );

		if ( '' === $client_id || '' === $client_secret ) {
			throw new \RuntimeException( 'ColorMe client_id/client_secret are not configured yet.' );
		}

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

		// settings（client_id/secret）を含む既存payloadを保持したままaccess_tokenだけ更新する
		// （save()は部分更新ではなく丸ごと置き換えのため、ここでマージしないと再接続に必要な
		// client_id/secretが消えてしまう）。
		$existing = $this->token_store->get() ?? [];

		$this->token_store->save( array_merge( $existing, [ 'access_token' => $decoded['access_token'] ] ) );
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
