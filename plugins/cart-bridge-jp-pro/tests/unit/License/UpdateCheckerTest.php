<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\License;

use CartBridgeJP\Pro\License\LicenseClient;
use CartBridgeJP\Pro\License\LicenseConfig;
use CartBridgeJP\Pro\License\LicenseStore;
use CartBridgeJP\Pro\License\UpdateChecker;
use CartBridgeJP\Pro\Tests\Fixtures\ActivatesLicense;
use CartBridgeJP\Pro\Tests\Fixtures\FakeLicenseServer;
use CartBridgeJP\Pro\Tests\Fixtures\FixtureLoader;
use WP_Error;
use WP_UnitTestCase;

/**
 * 更新の配信（R3-8c）。キーを有効化しているサイトだけが販売サイトに問い合わせ、WP の更新の仕組み（`update_plugins_{host}`・`plugins_api`）に渡す。
 */
final class UpdateCheckerTest extends WP_UnitTestCase {

	use ActivatesLicense;

	private const KEY         = '0123456789abcdef0123456789abcdef01234567';
	private const PLUGIN_FILE = 'cart-bridge-jp-pro/cart-bridge-jp-pro.php';
	private const UPDATE_URI  = 'https://example.com/cart-bridge-jp-pro/';

	private FakeLicenseServer $server;

	private LicenseStore $store;

	private UpdateChecker $checker;

	public function set_up(): void {
		parent::set_up();
		$this->server  = FakeLicenseServer::install();
		$this->store   = new LicenseStore();
		$this->checker = self::checker( $this->store, self::UPDATE_URI );
	}

	public function test_the_update_host_is_the_host_of_the_header(): void {
		$this->assertSame( 'example.com', $this->checker->update_host() );
		$this->assertSame( 'updates.example.com', self::checker( $this->store, 'https://updates.example.com/pro' )->update_host() );
		$this->assertNull( self::checker( $this->store, '' )->update_host(), 'ヘッダーが無ければ WP はフィルターを呼ばない' );
		$this->assertNull( self::checker( $this->store, '   ' )->update_host() );
	}

	public function test_registers_the_update_filter_on_the_header_host(): void {
		$this->checker->register();

		$this->assertSame( 10, has_filter( 'update_plugins_example.com', [ $this->checker, 'filter_update' ] ) );
		$this->assertSame( 10, has_filter( 'plugins_api', [ $this->checker, 'filter_plugin_information' ] ) );
		$this->assertSame( 10, has_action( 'in_plugin_update_message-' . self::PLUGIN_FILE, [ $this->checker, 'render_update_message' ] ) );
	}

	/**
	 * ヘッダーが無い間（販売サイトの URL が決まるまで）も、詳細の問い合わせは答える（wordpress.org に同じスラッグを問い合わせない）。
	 */
	public function test_answers_the_details_without_a_header(): void {
		$checker = self::checker( $this->store, '' );
		$checker->register();

		$this->assertSame( 10, has_filter( 'plugins_api', [ $checker, 'filter_plugin_information' ] ) );
	}

	public function test_the_booted_plugin_registers_the_update_checker(): void {
		global $wp_filter;

		$registered = false;

		foreach ( $wp_filter['plugins_api']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof UpdateChecker ) {
					$registered = true;
				}
			}
		}

		$this->assertTrue( $registered );
	}

	public function test_does_not_ask_the_server_without_an_activated_key(): void {
		$this->assertFalse( $this->checker->filter_update( false, [], self::PLUGIN_FILE ) );

		$details = $this->checker->filter_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'cart-bridge-jp-pro' ] );

		$this->assertInstanceOf( WP_Error::class, $details );
		$this->assertSame( 'cbjp_pro_plugin_information_unavailable', $details->get_error_code() );
		$this->assertCount( 0, $this->server->requests, 'キーを有効化していないサイトから外へ通信しない' );
	}

	/**
	 * 有効化済みの記録はあるがキーを読めない（ソルトが変わった・保存の途中の失敗）。送るキーが無いので問い合わせない。
	 */
	public function test_does_not_ask_the_server_without_a_readable_key(): void {
		$this->activate_license();

		$this->assertFalse( $this->checker->filter_update( false, [], self::PLUGIN_FILE ) );
		$this->assertInstanceOf( WP_Error::class, $this->checker->filter_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'cart-bridge-jp-pro' ] ) );
		$this->assertCount( 0, $this->server->requests );
	}

	public function test_does_not_ask_the_server_after_a_deactivation(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->deactivate_license();

		$this->assertFalse( $this->checker->filter_update( false, [], self::PLUGIN_FILE ) );
		$this->assertCount( 0, $this->server->requests );
	}

	public function test_leaves_other_plugins_alone(): void {
		$this->store->save_activation( self::KEY, 1000 );

		$this->assertSame( 'other', $this->checker->filter_update( 'other', [], 'other/other.php' ) );
		$this->assertSame( 'other', $this->checker->filter_plugin_information( 'other', 'plugin_information', (object) [ 'slug' => 'woocommerce' ] ) );
		$this->assertSame( 'other', $this->checker->filter_plugin_information( 'other', 'query_plugins', (object) [ 'slug' => 'cart-bridge-jp-pro' ] ) );
		$this->assertCount( 0, $this->server->requests );
	}

	public function test_returns_the_update_of_an_activated_site(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'update_active' );

		$update = $this->checker->filter_update( false, [], self::PLUGIN_FILE );

		$this->assertSame(
			[
				'slug'           => 'cart-bridge-jp-pro',
				'version'        => '0.1.1',
				'url'            => 'https://example.com/cart-bridge-jp-pro/',
				'package'        => 'https://example.com/?user_id=1&am_download_file=1000&am_order=1001&hname=xxxxxxxxxxxx&hkey=xxxxxxxxxxxxxxxx&hexpires=1792360276&remote_url=no',
				'tested'         => '7.1',
				'upgrade_notice' => 'Test upgrade notice bold',
			],
			$update
		);
		$this->assertSame( 'update', $this->server->last_body()['wc_am_action'] );
		$this->assertSame( self::KEY, $this->server->last_body()['api_key'] );
		$this->assertSame( $this->store->instance(), $this->server->last_body()['instance'] );
	}

	/**
	 * 期限後はサーバーが package を付けない（WP は「自動更新は利用できません」と出す）。ローカルの有効化は変えない（D14）。
	 */
	public function test_returns_an_update_without_a_package_and_keeps_the_activation(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'update_without_package' );

		$update = $this->checker->filter_update( false, [], self::PLUGIN_FILE );

		$this->assertIsArray( $update );
		$this->assertSame( '0.1.1', $update['version'] );
		$this->assertSame( '', $update['package'] );
		$this->assertTrue( $update['disable_autoupdate'], '取得できない更新を自動更新で試させない（失敗のメールが届く。R3-8c の review-loop R1-4）' );
		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	public function test_keeps_the_given_value_when_the_check_fails(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->error( 'cURL error 28' )->raw( 'not json' )->fixture( 'update_error_unknown_product' );

		$this->assertFalse( $this->checker->filter_update( false, [], self::PLUGIN_FILE ) );
		$this->assertFalse( $this->checker->filter_update( false, [], self::PLUGIN_FILE ) );
		$this->assertFalse( $this->checker->filter_update( false, [], self::PLUGIN_FILE ) );
		$this->assertTrue( $this->store->is_activated() );
	}

	/**
	 * WP の `wp_update_plugins()` を通す（R3-8c の review-loop R1-2）。ヘッダーの `Update URI` は販売サイトが決まるまで空なので、`get_plugins()` の
	 * キャッシュに差し込む。WP は先に wordpress.org へ問い合わせ、200 でなければ `Update URI` のプラグインも確かめずに戻るので、空の応答を先に積む。
	 */
	public function test_wp_update_plugins_offers_the_update(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->checker->register();
		// フィクスチャの new_version（0.1.1。0.1.0 のときに測った）は今の版より古いことがあり、WP は今の版より新しいものしか `response` に入れない。
		$body = FixtureLoader::load( 'api-manager', 'update_active' );

		$body['data']['package']['new_version'] = self::newer_version();
		$this->server->json( self::wordpress_org_response() )->json( $body );

		$item = $this->run_wp_update_plugins()->response[ self::PLUGIN_FILE ] ?? null;

		$this->assertIsObject( $item );
		$this->assertSame( self::newer_version(), $item->new_version, 'WP は version を new_version に写す' );
		$this->assertSame( self::UPDATE_URI, $item->id, 'id はヘッダーの値' );
		$this->assertSame( self::PLUGIN_FILE, $item->plugin );
		$this->assertSame( 'cart-bridge-jp-pro', $item->slug, '詳細のリンクはこのスラッグで plugins_api を引く' );
		$this->assertStringStartsWith( 'https://example.com/?user_id=1&am_download_file=1000', $item->package );
		$this->assertSame( 'update', $this->server->last_body()['wc_am_action'] );
	}

	public function test_wp_update_plugins_lists_the_current_version_as_up_to_date(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->checker->register();
		$body = FixtureLoader::load( 'api-manager', 'update_active' );

		$body['data']['package']['new_version'] = CBJP_PRO_VERSION;
		$this->server->json( self::wordpress_org_response() )->json( $body );

		$updates = $this->run_wp_update_plugins();

		$this->assertArrayNotHasKey( self::PLUGIN_FILE, $updates->response );
		$this->assertIsObject( $updates->no_update[ self::PLUGIN_FILE ] ?? null, 'no_update に入ると自動更新の切り替えが出る' );
	}

	public function test_wp_update_plugins_does_not_ask_the_server_without_an_activated_key(): void {
		$this->checker->register();
		$this->server->json( self::wordpress_org_response() );

		$updates = $this->run_wp_update_plugins();

		$this->assertArrayNotHasKey( self::PLUGIN_FILE, $updates->response );
		$this->assertArrayNotHasKey( self::PLUGIN_FILE, $updates->no_update );
		$this->assertCount( 1, $this->server->requests, 'wordpress.org への 1 件だけで、販売サイトへは送らない' );
		$this->assertStringNotContainsString( 'wc-am-api', $this->server->requests[0]['url'] );
	}

	public function test_returns_the_details_of_an_activated_site(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'information_active' );

		$details = $this->checker->filter_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'cart-bridge-jp-pro' ] );

		$this->assertIsObject( $details );
		$this->assertSame( 'Cart Bridge JP Pro', $details->name );
		$this->assertSame( 'cart-bridge-jp-pro', $details->slug );
		$this->assertSame( '0.1.1', $details->version );
		$this->assertSame( '8.2', $details->requires_php );
		$this->assertSame( [ 'changelog', 'description' ], array_keys( $details->sections ) );
		$this->assertSame( 'information', $this->server->last_body()['wc_am_action'] );
	}

	public function test_accepts_array_arguments_and_names_the_plugin_when_the_server_does_not(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$body = FixtureLoader::load( 'api-manager', 'information_active' );
		unset( $body['data']['info']['name'] );
		$this->server->json( $body );

		$details = $this->checker->filter_plugin_information( false, 'plugin_information', [ 'slug' => 'cart-bridge-jp-pro' ] );

		$this->assertIsObject( $details );
		$this->assertSame( 'Cart Bridge JP Pro', $details->name );
	}

	public function test_reports_details_that_could_not_be_loaded(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->error( 'cURL error 28' );

		$details = $this->checker->filter_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'cart-bridge-jp-pro' ] );

		$this->assertInstanceOf( WP_Error::class, $details );
		$this->assertSame( 'cbjp_pro_plugin_information_failed', $details->get_error_code() );
	}

	public function test_explains_an_update_without_a_package(): void {
		$this->assertStringContainsString( 'within its update period', $this->render_message( (object) [ 'package' => '' ] ) );
		// 期限後とは限らない（販売サイトのアカウントから有効化を外した場合も package は付かない）ので、設定画面へ案内する（R3-8c の review-loop R1-9）。
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'admin.php?page=cart-bridge-jp-pro' ) ) . '">Check the license status</a>', $this->render_message( (object) [ 'package' => '' ] ) );
		$this->assertStringContainsString( 'within its update period', $this->render_message( (object) [] ) );
		$this->assertSame( '', $this->render_message( (object) [ 'package' => 'https://example.com/pkg.zip' ] ) );
	}

	/**
	 * 今の版の最後の数字を 1 つ上げた版（WP の `wp_update_plugins()` が `response` に入れる、今より新しい版）。
	 */
	private static function newer_version(): string {
		$parts = explode( '.', CBJP_PRO_VERSION );

		$parts[ count( $parts ) - 1 ] = (string) ( (int) end( $parts ) + 1 );

		return implode( '.', $parts );
	}

	/**
	 * `get_plugins()` のキャッシュでこのプラグインの `Update URI` をテストの値（`self::UPDATE_URI`。チェッカーも同じ値で作る）にしてから
	 * `wp_update_plugins()` を走らせ、保存された更新の情報を返す。
	 */
	private function run_wp_update_plugins(): object {
		$plugins = get_plugins();

		$this->assertArrayHasKey( self::PLUGIN_FILE, $plugins, 'テストのサイトに Pro が入っている' );
		$plugins[ self::PLUGIN_FILE ]['UpdateURI'] = self::UPDATE_URI;
		wp_cache_set( 'plugins', [ '' => $plugins ], 'plugins' );
		delete_site_transient( 'update_plugins' );

		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		$this->assertIsObject( $updates );

		return $updates;
	}

	/**
	 * @return array<string,array<mixed>>
	 */
	private static function wordpress_org_response(): array {
		return [
			'plugins'      => [],
			'translations' => [],
			'no_update'    => [],
		];
	}

	private function render_message( object $response ): string {
		ob_start();
		$this->checker->render_update_message( [], $response );

		return (string) ob_get_clean();
	}

	private static function checker( LicenseStore $store, string $update_uri ): UpdateChecker {
		return new UpdateChecker( $store, new LicenseClient( new LicenseConfig( 'https://example.com/', 1000 ) ), $update_uri, self::PLUGIN_FILE );
	}
}
