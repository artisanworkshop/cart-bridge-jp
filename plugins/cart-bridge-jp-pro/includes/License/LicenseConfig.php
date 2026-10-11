<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

/**
 * ライセンスの接続先（販売サイトの WooCommerce API Manager の URL と、Pro の製品 ID。R3-8a）。
 *
 * 値はメインファイルの定数 `CBJP_PRO_LICENSE_API_URL`・`CBJP_PRO_LICENSE_PRODUCT_ID` から読む（wp-config.php で先に定義すると上書きできる）。
 * 定数は型が強制されないので、使う前に確かめる: URL は scheme が http/https で host のあるものだけ（`esc_url_raw()` は scheme の無い値に
 * `http://` を補い、host の無い `https:example.com` も通すので、先に `wp_parse_url()` で確かめる。CLAUDE.md）、製品 ID は正の整数だけ。
 * どちらかが使えなければ「未設定」で、クライアントは要求を送らない。
 */
final class LicenseConfig {

	/**
	 * @param mixed $api_url    販売サイトの URL（定数の値）。
	 * @param mixed $product_id API Manager の製品 ID（定数の値）。
	 */
	public function __construct(
		private readonly mixed $api_url,
		private readonly mixed $product_id
	) {}

	public static function from_constants(): self {
		return new self(
			defined( 'CBJP_PRO_LICENSE_API_URL' ) ? constant( 'CBJP_PRO_LICENSE_API_URL' ) : null,
			defined( 'CBJP_PRO_LICENSE_PRODUCT_ID' ) ? constant( 'CBJP_PRO_LICENSE_PRODUCT_ID' ) : null
		);
	}

	/**
	 * 検証済みの販売サイトの URL。使えない値なら null。
	 */
	public function api_url(): ?string {
		if ( ! is_string( $this->api_url ) ) {
			return null;
		}

		$url   = trim( $this->api_url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			return null;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$sanitized = esc_url_raw( $url, [ 'http', 'https' ] );

		return '' === $sanitized ? null : $sanitized;
	}

	/**
	 * 検証済みの製品 ID（正の整数）。使えない値なら null。
	 */
	public function product_id(): ?int {
		if ( is_int( $this->product_id ) ) {
			return $this->product_id > 0 ? $this->product_id : null;
		}

		if ( is_string( $this->product_id ) && 1 === preg_match( '/^[1-9][0-9]{0,18}\z/', $this->product_id ) ) {
			return (int) $this->product_id;
		}

		return null;
	}

	public function is_configured(): bool {
		return null !== $this->api_url() && null !== $this->product_id();
	}

	/**
	 * API Manager の入口（`<url>?wc-api=wc-am-api`）。未設定なら null。
	 */
	public function endpoint(): ?string {
		$url = $this->api_url();

		return null === $url ? null : add_query_arg( 'wc-api', 'wc-am-api', $url );
	}
}
