<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\License;

use CartBridgeJP\Pro\License\LicenseConfig;
use WP_UnitTestCase;

/**
 * ライセンスの接続先の検証（R3-8a）。定数は wp-config.php からも上書きできて型が強制されないので、使える値だけを受ける。
 */
final class LicenseConfigTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		// メインファイルの既定値を zip の検査と同じ関数で読む（ルートの bin/。テストのサイトでは wp-content/cbjp-dev）。
		require_once WP_CONTENT_DIR . '/cbjp-dev/bin/check-pro-zip.php';
	}

	public function test_accepts_an_https_url_and_a_positive_product_id(): void {
		$config = new LicenseConfig( 'https://example.com/', 1000 );

		$this->assertTrue( $config->is_configured() );
		$this->assertSame( 'https://example.com/', $config->api_url() );
		$this->assertSame( 1000, $config->product_id() );
		$this->assertSame( 'https://example.com/?wc-api=wc-am-api', $config->endpoint() );
	}

	public function test_accepts_the_largest_integer_product_id_string(): void {
		$this->assertSame( PHP_INT_MAX, ( new LicenseConfig( 'https://example.com/', (string) PHP_INT_MAX ) )->product_id() );
	}

	public function test_accepts_http_and_a_numeric_product_id_string(): void {
		$config = new LicenseConfig( ' http://wordpress/ ', '42' );

		$this->assertSame( 'http://wordpress/', $config->api_url() );
		$this->assertSame( 42, $config->product_id() );
	}

	/**
	 * @dataProvider invalid_urls
	 */
	public function test_rejects_unusable_urls( mixed $url ): void {
		$config = new LicenseConfig( $url, 1000 );

		$this->assertNull( $config->api_url() );
		$this->assertNull( $config->endpoint() );
		$this->assertFalse( $config->is_configured() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_urls(): array {
		return [
			'empty'              => [ '' ],
			'no scheme'          => [ 'example.com/pro' ],
			'no host'            => [ 'https:example.com' ],
			'javascript'         => [ 'javascript:alert(1)' ],
			'ftp'                => [ 'ftp://example.com/' ],
			'credentials in url' => [ 'https://user:pass@example.com/' ],
			'not a string'       => [ [ 'https://example.com/' ] ],
			'null'               => [ null ],
		];
	}

	/**
	 * @dataProvider invalid_product_ids
	 */
	public function test_rejects_unusable_product_ids( mixed $product_id ): void {
		$config = new LicenseConfig( 'https://example.com/', $product_id );

		$this->assertNull( $config->product_id() );
		$this->assertFalse( $config->is_configured() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_product_ids(): array {
		return [
			'zero'           => [ 0 ],
			'negative'       => [ -1 ],
			'zero string'    => [ '0' ],
			'trailing junk'  => [ '42abc' ],
			'leading zero'   => [ '042' ],
			'float'          => [ 1.5 ],
			'bool'           => [ true ],
			'null'           => [ null ],
			'array'          => [ [ 42 ] ],
			'trailing break' => [ "42\n" ],
			// PHP_INT_MAX を超えると `(int)` で別の値になる（PR #123 G1-2）。
			'overflow'       => [ '9999999999999999999' ],
		];
	}

	/**
	 * 販売サイトが決まるまで接続先は空（メインファイルの既定）。版 1.0.0 以上を空のまま・https でないまま公開しない（R3-8a。`ReadmeTest` の URL の止めと同じ考え方）。
	 * 実行時の定数ではなくメインファイルの `define()` の既定値を読む（wp-config.php・`.wp-env.json` の `config` で定数を定義した環境でも、配る zip の値で判定する。review-loop R1-4）。
	 */
	public function test_a_release_has_a_configured_license_server(): void {
		$this->assertFalse(
			self::is_release_without_license_server( CBJP_PRO_VERSION, self::main_file_defaults() ),
			'Set CBJP_PRO_LICENSE_API_URL (https) and CBJP_PRO_LICENSE_PRODUCT_ID in cart-bridge-jp-pro.php before releasing Cart Bridge JP Pro 1.0.0.'
		);
	}

	public function test_reads_the_defaults_of_the_main_file(): void {
		$source = self::main_file_source();

		$this->assertNotNull( cbjp_zipcheck_define( $source, 'CBJP_PRO_LICENSE_API_URL' ), 'メインファイルに接続先の既定値がある' );
		$this->assertNotNull( cbjp_zipcheck_define( $source, 'CBJP_PRO_LICENSE_PRODUCT_ID' ), 'メインファイルに製品 ID の既定値がある' );
	}

	public function test_the_release_check_stops_an_unconfigured_1_0_0(): void {
		$unconfigured = new LicenseConfig( '', 0 );
		$configured   = new LicenseConfig( 'https://example.com/', 1000 );

		$this->assertTrue( self::is_release_without_license_server( '1.0.0', $unconfigured ) );
		$this->assertTrue( self::is_release_without_license_server( '1.2.3', $unconfigured ) );
		$this->assertTrue( self::is_release_without_license_server( '1.0.0', new LicenseConfig( 'http://example.com/', 1000 ) ), '本文でキーを送るので https に限る' );
		$this->assertFalse( self::is_release_without_license_server( '0.1.0', $unconfigured ) );
		$this->assertFalse( self::is_release_without_license_server( '1.0.0', $configured ) );
	}

	/**
	 * 更新の配信（R3-8c）は `Update URI` ヘッダーの host のフィルターで行い、要求は接続先の定数へ送る。ヘッダーは販売サイトの URL が決まったときに入れる
	 * （2026-10-12 ユーザー決定）。版 1.0.0 以上ではヘッダーが無い（wordpress.org の同じスラッグに上書きされうる。backlog `r3-7-monorepo/R1-L4`）・
	 * https でない・接続先と host が違う（更新のフィルターが呼ばれない）ままの公開を止める。ヘッダーを入れたら版にかかわらず接続先と合わせる。
	 * ヘッダーは WP と同じ `get_file_data()` で読み、接続先はメインファイルの既定値で比べる（`test_a_release_has_a_configured_license_server()` と同じ理由）。
	 */
	public function test_a_release_has_an_update_uri_on_the_license_server_host(): void {
		$this->assertFalse(
			self::has_update_uri_problem( CBJP_PRO_VERSION, self::main_file_update_uri(), self::main_file_defaults() ),
			'Set the Update URI header of cart-bridge-jp-pro.php to an https URL on the host of CBJP_PRO_LICENSE_API_URL.'
		);
	}

	public function test_the_update_uri_check_stops_a_missing_or_mismatched_header(): void {
		$unconfigured = new LicenseConfig( '', 0 );
		$configured   = new LicenseConfig( 'https://example.com/', 1000 );

		$this->assertFalse( self::has_update_uri_problem( '0.1.0', '', $unconfigured ), '販売サイトが決まるまでは空でよい' );
		$this->assertTrue( self::has_update_uri_problem( '1.0.0', '', $configured ) );
		$this->assertTrue( self::has_update_uri_problem( '1.0.0', 'http://example.com/cart-bridge-jp-pro/', $configured ) );
		$this->assertTrue( self::has_update_uri_problem( '1.0.0', 'https://updates.example.net/', $configured ) );
		$this->assertTrue( self::has_update_uri_problem( '0.2.0', 'https://updates.example.net/', $configured ), 'ヘッダーを入れたら版にかかわらず合わせる' );
		$this->assertTrue( self::has_update_uri_problem( '0.2.0', 'https://example.com/', $unconfigured ) );
		$this->assertFalse( self::has_update_uri_problem( '1.0.0', 'https://example.com/cart-bridge-jp-pro/', $configured ) );
		$this->assertFalse( self::has_update_uri_problem( '1.0.0', 'https://EXAMPLE.com/', $configured ), 'host の大文字小文字は区別しない' );
	}

	public function test_the_version_header_matches_the_constant(): void {
		$headers = get_file_data( CBJP_PRO_FILE, [ 'Version' => 'Version' ] );

		$this->assertSame( CBJP_PRO_VERSION, $headers['Version'], 'WP はヘッダーの版で更新を比べ、Pro は定数の版を販売サイトへ送る' );
	}

	private static function has_update_uri_problem( string $version, string $update_uri, LicenseConfig $config ): bool {
		if ( '' === trim( $update_uri ) ) {
			return version_compare( $version, '1.0.0', '>=' );
		}

		$header = wp_parse_url( $update_uri );
		$server = wp_parse_url( (string) $config->api_url() );

		if ( ! is_array( $header ) || 'https' !== strtolower( (string) ( $header['scheme'] ?? '' ) ) || ! is_array( $server ) ) {
			return true;
		}

		return '' === (string) ( $header['host'] ?? '' ) || strtolower( (string) $header['host'] ) !== strtolower( (string) ( $server['host'] ?? '' ) );
	}

	private static function main_file_update_uri(): string {
		$headers = get_file_data( CBJP_PRO_FILE, [ 'UpdateURI' => 'Update URI' ] );

		return $headers['UpdateURI'];
	}

	private static function is_release_without_license_server( string $version, LicenseConfig $config ): bool {
		if ( version_compare( $version, '1.0.0', '<' ) ) {
			return false;
		}

		return ! $config->is_configured() || ! str_starts_with( (string) $config->api_url(), 'https://' );
	}

	/**
	 * メインファイルの `define( 'CBJP_PRO_LICENSE_*', <既定値> )` から作った設定。読めなければ未設定。zip の検査（`bin/check-pro-zip.php`）と同じく
	 * PHP のトークンで読む（コメントアウトした `define()` を読まない。PR #125 G2-B2）。
	 */
	private static function main_file_defaults(): LicenseConfig {
		$source = self::main_file_source();

		return new LicenseConfig(
			(string) cbjp_zipcheck_define( $source, 'CBJP_PRO_LICENSE_API_URL' ),
			(string) cbjp_zipcheck_define( $source, 'CBJP_PRO_LICENSE_PRODUCT_ID' )
		);
	}

	private static function main_file_source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- リポジトリ内の PHP ファイルを読む（JSON ではないので wp_json_file_decode() は使えない）。
		$source = file_get_contents( CBJP_PRO_FILE );

		return is_string( $source ) ? $source : '';
	}
}
