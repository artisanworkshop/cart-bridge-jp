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
	 * WP が `update_plugins_{host}` の戻り値から作る更新の情報（`wp_update_plugins()` の該当部分）を通す。
	 */
	public function test_wp_reads_the_update_through_the_header_host_filter(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'update_active' );
		$this->checker->register();

		$update = apply_filters( 'update_plugins_example.com', false, [ 'Version' => CBJP_PRO_VERSION ], self::PLUGIN_FILE, [] );

		$this->assertIsArray( $update );
		$this->assertTrue( version_compare( $update['version'], '0.1.0', '>' ) );
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
		$this->assertStringContainsString( 'within its update period', $this->render_message( (object) [] ) );
		$this->assertSame( '', $this->render_message( (object) [ 'package' => 'https://example.com/pkg.zip' ] ) );
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
