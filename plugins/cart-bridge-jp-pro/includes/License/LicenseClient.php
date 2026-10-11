<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

use WP_Error;

/**
 * WooCommerce API Manager（販売サイト）へのライセンスの要求（有効化・無効化・状態確認。R3-8a）。
 *
 * 手元の API Manager 3.6.0 で実測した振る舞い（2026-10-11。`tests/fixtures/api-manager/`）:
 * - エラーでも HTTP 200 で、本文が `{"success":false,"error":"…","data":{"error_code":"100",…}}` になる。成功は `success:true` と
 *   操作ごとの印（有効化 `activated:true`、無効化 `deactivated:true`、状態確認 `status_check` と `data.activated`）
 * - サーバーは `$_REQUEST` を読むので、パラメータは POST の本文で送る（公式のクライアントはクエリ文字列で送り、キーがアクセスログに残る）
 * - 期限が切れると、状態確認の時点でサーバーがそのサイトの有効化を消し（`inactive` を返す）、以後の無効化は失敗する。
 *   そのため有効化の状態はローカルの記録（`LicenseStore`）で決め、このクラスの結果では変えない（D14。`LicenseManager`）
 *
 * 応答はフェイルクローズで解釈する: HTTP 200・JSON のオブジェクト・`success === true`（実 bool）・操作ごとの印がそろったときだけ成功。
 */
final class LicenseClient {

	public const TIMEOUT = 15;

	/** サーバーの文を画面に出すときの上限（文字数）。 */
	private const MESSAGE_MAX_LENGTH = 300;

	public function __construct( private readonly LicenseConfig $config ) {}

	public function activate( string $api_key, string $instance ): LicenseResult {
		$body = $this->request(
			'activate',
			[
				'api_key'          => $api_key,
				'instance'         => $instance,
				'software_version' => CBJP_PRO_VERSION,
			]
		);

		if ( $body instanceof LicenseResult ) {
			return $body;
		}

		return true === ( $body['activated'] ?? null ) ? LicenseResult::success() : LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
	}

	public function deactivate( string $api_key, string $instance ): LicenseResult {
		$body = $this->request(
			'deactivate',
			[
				'api_key'  => $api_key,
				'instance' => $instance,
			]
		);

		if ( $body instanceof LicenseResult ) {
			return $body;
		}

		return true === ( $body['deactivated'] ?? null ) ? LicenseResult::success() : LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
	}

	/**
	 * 成功したときの値: `activated`（サーバーがこの instance を有効化済みと見ているか）、`expires`（期限の表示。サーバーの文。無ければ null）。
	 */
	public function status( string $api_key, string $instance ): LicenseResult {
		$body = $this->request(
			'status',
			[
				'api_key'  => $api_key,
				'instance' => $instance,
			]
		);

		if ( $body instanceof LicenseResult ) {
			return $body;
		}

		$data   = $body['data'] ?? null;
		$status = $body['status_check'] ?? null;

		if ( ! is_array( $data ) || ! is_bool( $data['activated'] ?? null ) || ! in_array( $status, [ 'active', 'inactive' ], true ) ) {
			return LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
		}

		// 2 つの印が食い違う応答は解釈しない。
		if ( ( 'active' === $status ) !== $data['activated'] ) {
			return LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
		}

		return LicenseResult::success(
			[
				'activated' => $data['activated'],
				'expires'   => self::expiration_text( $data['api_key_expirations'] ?? null ),
			]
		);
	}

	/**
	 * @param array<string,string> $params
	 * @return array<string,mixed>|LicenseResult 成功（`success === true`）の応答の本文、または失敗。
	 */
	private function request( string $action, array $params ): array|LicenseResult {
		$endpoint   = $this->config->endpoint();
		$product_id = $this->config->product_id();

		if ( null === $endpoint || null === $product_id ) {
			return LicenseResult::failure( LicenseResult::NOT_CONFIGURED );
		}

		$response = wp_safe_remote_post(
			$endpoint,
			[
				'timeout'     => self::TIMEOUT,
				// 転送先へ本文（キー）を運ばない。転送は失敗として扱う（接続先の定数を直す）。
				'redirection' => 0,
				'body'        => array_merge(
					$params,
					[
						'wc_am_action' => $action,
						'product_id'   => (string) $product_id,
						'object'       => self::site_object(),
					]
				),
			]
		);

		if ( $response instanceof WP_Error ) {
			return LicenseResult::failure( LicenseResult::HTTP_ERROR, self::clean_message( $response->get_error_message() ) );
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return LicenseResult::failure( LicenseResult::HTTP_ERROR, 'HTTP ' . (int) $status );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || array_is_list( $body ) ) {
			return LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
		}

		if ( false === ( $body['success'] ?? null ) ) {
			return LicenseResult::failure( LicenseResult::SERVER_ERROR, self::server_message( $body ) );
		}

		if ( true !== ( $body['success'] ?? null ) ) {
			return LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
		}

		return $body;
	}

	/**
	 * API Manager に送るサイトの識別（公式のクライアントと同じく、scheme を外した `home_url()`。サーバーの有効化の一覧に出る）。
	 */
	private static function site_object(): string {
		return (string) preg_replace( '#^https?://#i', '', home_url() );
	}

	/**
	 * `success: false` の応答の文（`error`、無ければ `data.error`）。
	 *
	 * @param array<string,mixed> $body
	 */
	private static function server_message( array $body ): string {
		$data = $body['data'] ?? null;

		foreach ( [ $body['error'] ?? null, is_array( $data ) ? ( $data['error'] ?? null ) : null ] as $candidate ) {
			if ( is_string( $candidate ) ) {
				$message = self::clean_message( $candidate );

				if ( '' !== $message ) {
					return $message;
				}
			}
		}

		return '';
	}

	/**
	 * 状態確認の応答の期限の表示（最初のリソースの `friendly_api_key_expiration_date`。例「January 9, 2027」「Expired」）。
	 */
	private static function expiration_text( mixed $expirations ): ?string {
		if ( ! is_array( $expirations ) ) {
			return null;
		}

		$resources = $expirations['non_wc_subs_resources'] ?? null;

		if ( ! is_array( $resources ) || ! array_is_list( $resources ) || ! is_array( $resources[0] ?? null ) ) {
			return null;
		}

		$text = $resources[0]['friendly_api_key_expiration_date'] ?? null;

		if ( ! is_string( $text ) ) {
			return null;
		}

		$text = self::clean_message( $text );

		return '' === $text ? null : $text;
	}

	/**
	 * サーバーの文から画面に出す文を作る（タグ〔「My Account」へのリンクなど〕と制御文字を除き、空白を詰め、長さを抑える）。
	 */
	private static function clean_message( string $message ): string {
		$text = wp_strip_all_tags( $message );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_substr( $text, 0, self::MESSAGE_MAX_LENGTH );
	}
}
