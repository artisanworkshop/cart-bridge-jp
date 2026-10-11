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

	public function test_accepts_an_https_url_and_a_positive_product_id(): void {
		$config = new LicenseConfig( 'https://example.com/', 1000 );

		$this->assertTrue( $config->is_configured() );
		$this->assertSame( 'https://example.com/', $config->api_url() );
		$this->assertSame( 1000, $config->product_id() );
		$this->assertSame( 'https://example.com/?wc-api=wc-am-api', $config->endpoint() );
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
		];
	}

	/**
	 * 販売サイトが決まるまで接続先は空（メインファイルの既定）。版 1.0.0 以上を空のまま公開しない（R3-8a。`ReadmeTest` の URL の止めと同じ考え方）。
	 */
	public function test_a_release_has_a_configured_license_server(): void {
		$this->assertFalse(
			self::is_release_without_license_server( CBJP_PRO_VERSION, LicenseConfig::from_constants() ),
			'Set CBJP_PRO_LICENSE_API_URL and CBJP_PRO_LICENSE_PRODUCT_ID before releasing Cart Bridge JP Pro 1.0.0.'
		);
	}

	public function test_the_release_check_stops_an_unconfigured_1_0_0(): void {
		$unconfigured = new LicenseConfig( '', 0 );
		$configured   = new LicenseConfig( 'https://example.com/', 1000 );

		$this->assertTrue( self::is_release_without_license_server( '1.0.0', $unconfigured ) );
		$this->assertTrue( self::is_release_without_license_server( '1.2.3', $unconfigured ) );
		$this->assertFalse( self::is_release_without_license_server( '0.1.0', $unconfigured ) );
		$this->assertFalse( self::is_release_without_license_server( '1.0.0', $configured ) );
	}

	private static function is_release_without_license_server( string $version, LicenseConfig $config ): bool {
		return version_compare( $version, '1.0.0', '>=' ) && ! $config->is_configured();
	}
}
