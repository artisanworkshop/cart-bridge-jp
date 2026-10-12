<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

use WP_Error;

/**
 * WooCommerce API Manager（販売サイト）へのライセンスの要求（有効化・無効化・状態確認〔R3-8a〕と、更新の確認・詳細〔R3-8c〕）。
 *
 * 手元の API Manager 3.6.0 で実測した振る舞い（2026-10-11。`tests/fixtures/api-manager/`。3.8.1 でも応答の形と文は同じ〔2026-10-12〕）:
 * - エラーでも HTTP 200 で、本文が `{"success":false,"error":"…","data":{"error_code":"100",…}}` になる。成功は `success:true` と
 *   操作ごとの印（有効化 `activated:true`、無効化 `deactivated:true`、状態確認 `status_check` と `data.activated`）
 * - サーバーは `$_REQUEST` を読むので、パラメータは POST の本文で送る（公式のクライアントはクエリ文字列で送り、キーがアクセスログに残る）
 * - 期限が切れると、状態確認の時点でサーバーがそのサイトの有効化を消し（`inactive` を返す）、以後の無効化は失敗する。
 *   そのため有効化の状態はローカルの記録（`LicenseStore`）で決め、このクラスの結果では変えない（D14。`LicenseManager`）
 *
 * - 更新の確認（`update`）・詳細（`information`）は、有効化済みの instance とキーにだけ package（ダウンロードの URL）を付け、それ以外は
 *   版などの情報だけを返す。期限後は、状態確認と同じくその時点でサーバーがこの instance の有効化を消す（3.8.1 で実測。R3-8c）
 *
 * 応答はフェイルクローズで解釈する: HTTP 200・JSON のオブジェクト・`success === true`（実 bool）・操作ごとの印がそろったときだけ成功。
 */
final class LicenseClient {

	public const TIMEOUT = 15;

	/**
	 * 画面の読み込みの中で走る更新の確認・詳細の待ち（秒）。cron では `TIMEOUT`。販売サイトが応答しないとき、プラグインの一覧・更新の画面を
	 * 長く止めないため（WP 自身の wordpress.org への確認も、cron でなければ短く待つ）。
	 */
	public const INTERACTIVE_UPDATE_TIMEOUT = 5;

	/** 更新の確認・詳細でこのプラグインを名乗る値（API Manager は `plugin_name`・`slug` をそのまま応答に写す）。 */
	public const PLUGIN_SLUG = 'cart-bridge-jp-pro';

	/** プラグインの版の形（例 `1.0.0`・`1.2.3-beta.1`）。サーバーの値をそのまま WP の版の比較に渡すので、形の違う値は受けない。 */
	private const PLUGIN_VERSION_PATTERN = '/^\d{1,4}(\.\d{1,4}){1,3}(-[0-9A-Za-z.]{1,20})?\z/';

	/** WordPress・PHP の版の形（`tested`・`requires`・`requires_php`）。 */
	private const PLATFORM_VERSION_PATTERN = '/^\d{1,2}\.\d{1,2}(\.\d{1,2})?\z/';

	/**
	 * 詳細の画面で出す節（WP の節の名前 => API Manager が節のキーにする表示名）。API Manager は節を `Licensing_Product_Tab::label()` の
	 * **訳した**表示名で返す（3.8.1 のソース。販売サイトが ja なら「説明」「変更履歴」）ので、英語と API Manager 同梱の ja の訳の両方を、
	 * WP の詳細の画面と「バージョン x の詳細」（`section=changelog`）が引く小文字の名前に直す（R3-8c の review-loop R1-1）。表示名は小文字にして比べる。
	 */
	private const SECTIONS = [
		'description'   => [ 'description', '説明' ],
		'installation'  => [ 'installation', 'インストール' ],
		'faq'           => [ 'faq', 'よくある質問' ],
		'screenshots'   => [ 'screenshots', 'スクリーンショット' ],
		'changelog'     => [ 'changelog', '変更履歴' ],
		'other_notes'   => [ 'other notes', 'その他の注意点' ],
		'documentation' => [ 'documentation', 'ドキュメント' ],
	];

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
	 * 更新の確認。成功したときの値: `version`（販売サイトの最新の版）、`package`（ダウンロードの URL。有効化済みの期限内だけ付く。
	 * 付かない・形の違う URL は ''）、`url`（製品のページ）・`tested`・`upgrade_notice`（無い・形が違えば ''）。
	 */
	public function update( string $api_key, string $instance ): LicenseResult {
		$body = $this->request( 'update', $this->plugin_params( $api_key, $instance ), self::update_timeout() );

		if ( $body instanceof LicenseResult ) {
			return $body;
		}

		$data    = $body['data'] ?? null;
		$package = is_array( $data ) ? ( $data['package'] ?? null ) : null;
		$version = is_array( $package ) ? self::plugin_version( $package['new_version'] ?? null ) : null;

		if ( null === $version ) {
			return LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
		}

		return LicenseResult::success(
			[
				'version'        => $version,
				'package'        => $this->package_url( $package['package'] ?? null ),
				'url'            => self::web_url( $package['url'] ?? null ),
				'tested'         => self::platform_version( $package['tested'] ?? null ),
				'upgrade_notice' => is_string( $package['upgrade_notice'] ?? null ) ? self::clean_message( $package['upgrade_notice'] ) : '',
			]
		);
	}

	/**
	 * プラグインの詳細（WP の「詳細を表示」の画面）。成功したときの値: `version`、`name`・`author`・`homepage`・`requires`・`tested`・
	 * `requires_php`・`last_updated`（無い・形が違えば ''）、`sections`（既知の節だけ。キーは小文字の節の名前、値は `wp_kses_post()` した HTML）。
	 */
	public function information( string $api_key, string $instance ): LicenseResult {
		$body = $this->request( 'information', $this->plugin_params( $api_key, $instance ), self::update_timeout() );

		if ( $body instanceof LicenseResult ) {
			return $body;
		}

		$data    = $body['data'] ?? null;
		$info    = is_array( $data ) ? ( $data['info'] ?? null ) : null;
		$version = is_array( $info ) ? self::plugin_version( $info['version'] ?? null ) : null;

		if ( null === $version ) {
			return LicenseResult::failure( LicenseResult::INVALID_RESPONSE );
		}

		return LicenseResult::success(
			[
				'version'      => $version,
				'name'         => is_string( $info['name'] ?? null ) ? self::clean_message( $info['name'] ) : '',
				'author'       => is_string( $info['author'] ?? null ) ? trim( wp_kses( $info['author'], [ 'a' => [ 'href' => true ] ] ) ) : '',
				'homepage'     => self::web_url( $info['homepage'] ?? null ),
				'requires'     => self::platform_version( $info['requires'] ?? null ),
				'tested'       => self::platform_version( $info['tested'] ?? null ),
				'requires_php' => self::platform_version( $info['requires_php'] ?? null ),
				'last_updated' => is_string( $info['last_updated'] ?? null ) ? mb_substr( self::clean_message( $info['last_updated'] ), 0, 40 ) : '',
				'sections'     => self::sections( $info['sections'] ?? null ),
			]
		);
	}

	/**
	 * @param array<string,string> $params
	 * @return array<string,mixed>|LicenseResult 成功（`success === true`）の応答の本文、または失敗。
	 */
	private function request( string $action, array $params, int $timeout = self::TIMEOUT ): array|LicenseResult {
		$endpoint   = $this->config->endpoint();
		$product_id = $this->config->product_id();

		if ( null === $endpoint || null === $product_id ) {
			return LicenseResult::failure( LicenseResult::NOT_CONFIGURED );
		}

		$response = wp_safe_remote_post(
			$endpoint,
			[
				'timeout'     => $timeout,
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
	 * 更新の確認・詳細のパラメータ（公式のクライアントと同じ。`plugin_name` はプラグインのファイル、`version` は今の版）。
	 *
	 * @return array<string,string>
	 */
	private function plugin_params( string $api_key, string $instance ): array {
		return [
			'api_key'     => $api_key,
			'instance'    => $instance,
			'plugin_name' => plugin_basename( CBJP_PRO_FILE ),
			'slug'        => self::PLUGIN_SLUG,
			'version'     => CBJP_PRO_VERSION,
		];
	}

	private static function update_timeout(): int {
		return wp_doing_cron() ? self::TIMEOUT : self::INTERACTIVE_UPDATE_TIMEOUT;
	}

	private static function plugin_version( mixed $value ): ?string {
		return is_string( $value ) && 1 === preg_match( self::PLUGIN_VERSION_PATTERN, $value ) ? $value : null;
	}

	private static function platform_version( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( self::PLATFORM_VERSION_PATTERN, $value ) ? $value : '';
	}

	/**
	 * http(s) で host のある URL（`esc_url_raw()` は scheme の無い値に `http://` を補い、host の無い値も通すので、先に `wp_parse_url()` で
	 * 確かめる。CLAUDE.md）。資格情報を含む URL は受けない。使えなければ ''。
	 *
	 * @param array<int,string> $schemes
	 */
	private static function web_url( mixed $value, array $schemes = [ 'http', 'https' ] ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url   = trim( $value );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), $schemes, true ) ) {
			return '';
		}

		return esc_url_raw( $url, $schemes );
	}

	/**
	 * ダウンロードの URL。接続先が https なら https に限る（キーで得た package を平文で取りに行かない）。使えなければ ''（WP は
	 * 「自動更新は利用できません」と出す）。
	 */
	private function package_url( mixed $value ): string {
		$api_url = (string) $this->config->api_url();

		return self::web_url( $value, str_starts_with( strtolower( $api_url ), 'https://' ) ? [ 'https' ] : [ 'http', 'https' ] );
	}

	/**
	 * 詳細の節。キーは API Manager の表示名（`Description`・`Other notes`・`変更履歴`）を WP の節の名前（`description`・`other_notes`・`changelog`）にし、
	 * 既知の節だけを残す。値は `wp_kses_post()` する（WP の詳細の画面も節を `wp_kses()` するが、ここでも浄化してから渡す）。
	 *
	 * @return array<string,string>
	 */
	private static function sections( mixed $sections ): array {
		if ( ! is_array( $sections ) ) {
			return [];
		}

		$clean = [];

		foreach ( $sections as $name => $html ) {
			if ( ! is_string( $name ) || ! is_string( $html ) ) {
				continue;
			}

			$key = self::section_key( $name );

			if ( null !== $key && ! isset( $clean[ $key ] ) ) {
				$clean[ $key ] = wp_kses_post( $html );
			}
		}

		return $clean;
	}

	/**
	 * API Manager の節の表示名に当たる WP の節の名前。知らない表示名なら null。
	 */
	private static function section_key( string $label ): ?string {
		$label = strtolower( trim( str_replace( [ '_', '-' ], ' ', $label ) ) );

		foreach ( self::SECTIONS as $key => $labels ) {
			if ( in_array( $label, $labels, true ) ) {
				return $key;
			}
		}

		return null;
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
