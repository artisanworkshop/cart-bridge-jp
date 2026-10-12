<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\License;

use CartBridgeJP\Pro\License\LicenseClient;
use CartBridgeJP\Pro\License\LicenseConfig;
use CartBridgeJP\Pro\License\LicenseResult;
use CartBridgeJP\Pro\Tests\Fixtures\FakeLicenseServer;
use CartBridgeJP\Pro\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

/**
 * 更新の確認（`update`）と詳細（`information`）の要求の形と、応答のフェイルクローズな解釈（R3-8c）。
 * 応答は手元の API Manager 3.8.1 で実測したもの（`tests/fixtures/api-manager/update_*`・`information_*`）。
 */
final class LicenseClientUpdateTest extends WP_UnitTestCase {

	private const KEY      = '0123456789abcdef0123456789abcdef01234567';
	private const INSTANCE = 'InstanceIdForTests0123456789abcd';

	private FakeLicenseServer $server;

	private LicenseClient $client;

	public function set_up(): void {
		parent::set_up();
		$this->server = FakeLicenseServer::install();
		$this->client = new LicenseClient( new LicenseConfig( 'https://example.com/', 1000 ) );
	}

	public function test_update_posts_the_plugin_parameters_in_the_body(): void {
		$this->server->fixture( 'update_active' );

		$this->assertTrue( $this->client->update( self::KEY, self::INSTANCE )->is_success() );

		$this->assertCount( 1, $this->server->requests );
		$request = $this->server->requests[0];
		$this->assertSame( 'https://example.com/?wc-api=wc-am-api', $request['url'], 'キーを URL（アクセスログ）に載せない' );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 0, $request['args']['redirection'], '転送先へキーを運ばない' );
		$this->assertTrue( $request['args']['reject_unsafe_urls'] );
		$this->assertSame( LicenseClient::INTERACTIVE_UPDATE_TIMEOUT, $request['args']['timeout'], '画面の読み込みの中では短く待つ' );
		$this->assertSame(
			[
				'api_key'      => self::KEY,
				'instance'     => self::INSTANCE,
				'plugin_name'  => 'cart-bridge-jp-pro/cart-bridge-jp-pro.php',
				'slug'         => 'cart-bridge-jp-pro',
				'version'      => CBJP_PRO_VERSION,
				'wc_am_action' => 'update',
				'product_id'   => '1000',
				'object'       => (string) preg_replace( '#^https?://#', '', home_url() ),
			],
			$request['args']['body']
		);
	}

	public function test_waits_longer_in_cron(): void {
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->server->fixture( 'update_active' )->fixture( 'information_active' );

		$this->client->update( self::KEY, self::INSTANCE );
		$this->client->information( self::KEY, self::INSTANCE );

		$this->assertSame( LicenseClient::TIMEOUT, $this->server->requests[0]['args']['timeout'] );
		$this->assertSame( LicenseClient::TIMEOUT, $this->server->requests[1]['args']['timeout'] );
	}

	public function test_reads_an_update_with_a_package(): void {
		$this->server->fixture( 'update_active' );

		$result = $this->client->update( self::KEY, self::INSTANCE );

		$this->assertTrue( $result->is_success() );
		$this->assertSame(
			[
				'version'        => '0.1.1',
				'package'        => 'https://example.com/?user_id=1&am_download_file=1000&am_order=1001&hname=xxxxxxxxxxxx&hkey=xxxxxxxxxxxxxxxx&hexpires=1792360276&remote_url=no',
				'url'            => 'https://example.com/cart-bridge-jp-pro/',
				'tested'         => '7.1',
				'upgrade_notice' => 'Test upgrade notice bold',
			],
			$result->data
		);
	}

	/**
	 * 未有効化の instance と期限後は、版などの情報だけで package が空（3.8.1 で実測。期限後はこの時点でサーバーが有効化を消す）。
	 */
	public function test_reads_an_update_without_a_package(): void {
		$this->server->fixture( 'update_without_package' );

		$result = $this->client->update( self::KEY, self::INSTANCE );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '0.1.1', $result->data['version'] );
		$this->assertSame( '', $result->data['package'] );
	}

	public function test_reads_a_server_error(): void {
		$this->server->fixture( 'update_error_unknown_product' );

		$result = $this->client->update( self::KEY, self::INSTANCE );

		$this->assertSame( LicenseResult::SERVER_ERROR, $result->code );
		$this->assertSame( 'The product ID 2000 could not be found in this store.', $result->message );
	}

	public function test_does_not_send_without_a_configured_server(): void {
		$client = new LicenseClient( new LicenseConfig( '', 1000 ) );

		$this->assertSame( LicenseResult::NOT_CONFIGURED, $client->update( self::KEY, self::INSTANCE )->code );
		$this->assertSame( LicenseResult::NOT_CONFIGURED, $client->information( self::KEY, self::INSTANCE )->code );
		$this->assertCount( 0, $this->server->requests );
	}

	/**
	 * @dataProvider malformed_updates
	 */
	public function test_does_not_read_a_malformed_update( mixed $package ): void {
		$body                    = FixtureLoader::load( 'api-manager', 'update_active' );
		$body['data']['package'] = $package;
		$this->server->json( $body );

		$this->assertSame( LicenseResult::INVALID_RESPONSE, $this->client->update( self::KEY, self::INSTANCE )->code );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function malformed_updates(): array {
		$valid = FixtureLoader::load( 'api-manager', 'update_active' )['data']['package'];

		return [
			'no package'          => [ null ],
			'package is a string' => [ 'https://example.com/pkg.zip' ],
			'no version'          => [ array_diff_key( $valid, [ 'new_version' => true ] ) ],
			'numeric version'     => [ array_merge( $valid, [ 'new_version' => 1.1 ] ) ],
			'word version'        => [ array_merge( $valid, [ 'new_version' => 'latest' ] ) ],
			'markup in version'   => [ array_merge( $valid, [ 'new_version' => '1.0.0<script>' ] ) ],
			'trailing newline'    => [ array_merge( $valid, [ 'new_version' => "1.0.0\n" ] ) ],
		];
	}

	/**
	 * @dataProvider unusable_packages
	 */
	public function test_drops_an_unusable_package_url( mixed $package ): void {
		$body                               = FixtureLoader::load( 'api-manager', 'update_active' );
		$body['data']['package']['package'] = $package;
		$this->server->json( $body );

		$result = $this->client->update( self::KEY, self::INSTANCE );

		$this->assertTrue( $result->is_success(), '版の情報は使い、自動更新だけをやめる' );
		$this->assertSame( '', $result->data['package'] );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function unusable_packages(): array {
		return [
			'http from an https server' => [ 'http://example.com/pkg.zip' ],
			'javascript'                => [ 'javascript:alert(1)' ],
			'no scheme'                 => [ 'example.com/pkg.zip' ],
			'no host'                   => [ 'https:example.com/pkg.zip' ],
			'ftp'                       => [ 'ftp://example.com/pkg.zip' ],
			'credentials'               => [ 'https://user:pass@example.com/pkg.zip' ],
			'not a string'              => [ [ 'https://example.com/pkg.zip' ] ],
			'number'                    => [ 1 ],
		];
	}

	public function test_accepts_an_http_package_from_an_http_server(): void {
		$client = new LicenseClient( new LicenseConfig( 'http://wordpress/', 1000 ) );
		$body   = FixtureLoader::load( 'api-manager', 'update_active' );

		$body['data']['package']['package'] = 'http://wordpress/?am_download_file=1000';
		$this->server->json( $body );

		$this->assertSame( 'http://wordpress/?am_download_file=1000', $client->update( self::KEY, self::INSTANCE )->data['package'] );
	}

	public function test_drops_unusable_optional_update_fields(): void {
		$body = FixtureLoader::load( 'api-manager', 'update_active' );

		$body['data']['package']['url']            = 'javascript:alert(1)';
		$body['data']['package']['tested']         = '7.1<b>';
		$body['data']['package']['upgrade_notice'] = [ 'not a string' ];
		$this->server->json( $body );

		$data = $this->client->update( self::KEY, self::INSTANCE )->data;

		$this->assertSame( '', $data['url'] );
		$this->assertSame( '', $data['tested'] );
		$this->assertSame( '', $data['upgrade_notice'] );
	}

	public function test_information_sends_its_action_and_reads_the_details(): void {
		$this->server->fixture( 'information_active' );

		$result = $this->client->information( self::KEY, self::INSTANCE );

		$this->assertSame( 'information', $this->server->last_body()['wc_am_action'], 'シリアライズで返す plugininformation は使わない' );
		$this->assertSame( self::INSTANCE, $this->server->last_body()['instance'] );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( '0.1.1', $result->data['version'] );
		$this->assertSame( 'Cart Bridge JP Pro', $result->data['name'] );
		$this->assertSame( '<a href="https://example.com/">Artisan Workshop</a>', $result->data['author'] );
		$this->assertSame( 'https://example.com/cart-bridge-jp-pro/', $result->data['homepage'] );
		$this->assertSame( '6.9', $result->data['requires'] );
		$this->assertSame( '7.1', $result->data['tested'] );
		$this->assertSame( '8.2', $result->data['requires_php'] );
		$this->assertSame( '2026-10-12', $result->data['last_updated'] );
		// API Manager は節を表示名（`Changelog`）で返す。WP の詳細の画面と「バージョン x の詳細」（`section=changelog`）は小文字の名前で引く。
		$this->assertSame(
			[
				'changelog'   => '<h4>0.1.1</h4><ul><li>Fix</li></ul>',
				'description' => '<p>Pro description</p>alert(1)',
			],
			$result->data['sections']
		);
	}

	public function test_information_keeps_only_known_sections_and_cleans_them(): void {
		$body = FixtureLoader::load( 'api-manager', 'information_active' );

		$body['data']['info']['sections'] = [
			'Description' => '<p>ok</p><script>alert(1)</script><img src="x" onerror="alert(1)">',
			'Other notes' => '<p>notes</p>',
			'FAQ'         => '<p>faq</p>',
			'Reviews'     => '<p>reviews</p>',
			'Evil'        => '<p>evil</p>',
			'changelog'   => [ 'not a string' ],
		];
		$this->server->json( $body );

		$sections = $this->client->information( self::KEY, self::INSTANCE )->data['sections'];

		$this->assertSame( [ 'description', 'other_notes', 'faq' ], array_keys( $sections ) );
		$this->assertStringNotContainsString( '<script', $sections['description'] );
		$this->assertStringNotContainsString( 'onerror', $sections['description'] );
		$this->assertStringContainsString( '<p>ok</p>', $sections['description'] );
	}

	/**
	 * API Manager は節のキーに訳した表示名を使う（`Licensing_Product_Tab::label()`）。販売サイトが ja なら、同梱の訳の「説明」「変更履歴」などになる
	 * （R3-8c の review-loop R1-1。訳は woocommerce-api-manager-ja.mo 3.8.1）。
	 */
	public function test_information_reads_the_japanese_section_labels(): void {
		$body = FixtureLoader::load( 'api-manager', 'information_active' );

		$body['data']['info']['sections'] = [
			'説明'        => '<p>description</p>',
			'インストール'    => '<p>installation</p>',
			'よくある質問'    => '<p>faq</p>',
			'スクリーンショット' => '<p>screenshots</p>',
			'変更履歴'      => '<p>changelog</p>',
			'その他の注意点'   => '<p>other notes</p>',
			'ドキュメント'    => '<p>documentation</p>',
		];
		$this->server->json( $body );

		$this->assertSame(
			[
				'description'   => '<p>description</p>',
				'installation'  => '<p>installation</p>',
				'faq'           => '<p>faq</p>',
				'screenshots'   => '<p>screenshots</p>',
				'changelog'     => '<p>changelog</p>',
				'other_notes'   => '<p>other notes</p>',
				'documentation' => '<p>documentation</p>',
			],
			$this->client->information( self::KEY, self::INSTANCE )->data['sections']
		);
	}

	public function test_information_cleans_the_name_and_the_author(): void {
		$body = FixtureLoader::load( 'api-manager', 'information_active' );

		$body['data']['info']['name']   = 'Cart Bridge JP Pro<script>alert(1)</script>';
		$body['data']['info']['author'] = '<a href="https://example.com/" onclick="alert(1)">Artisan</a><script>alert(1)</script><img src="x">';
		$this->server->json( $body );

		$data = $this->client->information( self::KEY, self::INSTANCE )->data;

		$this->assertSame( 'Cart Bridge JP Pro', $data['name'], 'wp_strip_all_tags() は script の中身ごと除く' );
		$this->assertSame( '<a href="https://example.com/">Artisan</a>alert(1)', $data['author'] );
	}

	public function test_information_drops_unusable_fields(): void {
		$body = FixtureLoader::load( 'api-manager', 'information_active' );

		$body['data']['info']['homepage']     = 'javascript:alert(1)';
		$body['data']['info']['requires']     = 'six';
		$body['data']['info']['requires_php'] = '8.2; DROP';
		$body['data']['info']['sections']     = 'not an array';
		unset( $body['data']['info']['name'], $body['data']['info']['last_updated'] );
		$this->server->json( $body );

		$data = $this->client->information( self::KEY, self::INSTANCE )->data;

		$this->assertSame( '', $data['homepage'] );
		$this->assertSame( '', $data['requires'] );
		$this->assertSame( '', $data['requires_php'] );
		$this->assertSame( '', $data['name'] );
		$this->assertSame( '', $data['last_updated'] );
		$this->assertSame( [], $data['sections'] );
	}

	/**
	 * @dataProvider malformed_information
	 */
	public function test_does_not_read_malformed_information( mixed $info ): void {
		$body                 = FixtureLoader::load( 'api-manager', 'information_active' );
		$body['data']['info'] = $info;
		$this->server->json( $body );

		$this->assertSame( LicenseResult::INVALID_RESPONSE, $this->client->information( self::KEY, self::INSTANCE )->code );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function malformed_information(): array {
		$valid = FixtureLoader::load( 'api-manager', 'information_active' )['data']['info'];

		return [
			'no info'      => [ null ],
			'no version'   => [ array_diff_key( $valid, [ 'version' => true ] ) ],
			'word version' => [ array_merge( $valid, [ 'version' => 'new' ] ) ],
		];
	}
}
