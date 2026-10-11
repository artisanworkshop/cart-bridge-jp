<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Admin;

use CartBridgeJP\Pro\Admin\LicensePage;
use CartBridgeJP\Pro\License\LicenseClient;
use CartBridgeJP\Pro\License\LicenseConfig;
use CartBridgeJP\Pro\License\LicenseManager;
use CartBridgeJP\Pro\License\LicenseResult;
use CartBridgeJP\Pro\License\LicenseStore;
use CartBridgeJP\Pro\Tests\Fixtures\FakeLicenseServer;
use CartBridgeJP\Pro\Tests\License\LicenseStoreTest;
use WP_UnitTestCase;
use WPDieException;

/**
 * Pro の設定画面（R3-8a）。操作は権限と nonce を確かめてからだけ行い、拒否した要求は HTTP も保存もしない。
 */
final class LicensePageTest extends WP_UnitTestCase {

	private const KEY = '0123456789abcdef0123456789abcdef01234567';

	private FakeLicenseServer $server;

	private LicenseStore $store;

	private LicensePage $page;

	public function set_up(): void {
		parent::set_up();
		$this->server = FakeLicenseServer::install();
		$this->store  = new LicenseStore();
		$this->page   = self::page( new LicenseConfig( 'https://example.com/', 1000 ), $this->store );
	}

	public function tear_down(): void {
		unset( $_POST[ LicensePage::KEY_FIELD ], $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
		parent::tear_down();
	}

	public function test_registers_the_page_and_the_actions(): void {
		$this->page->register();

		$this->assertSame( 10, has_action( 'admin_menu', [ $this->page, 'add_menu' ] ) );
		foreach ( LicensePage::actions() as $action ) {
			$this->assertSame( 10, has_action( 'admin_post_' . $action, [ $this->page, 'handle_request' ] ), $action );
			$this->assertFalse( has_action( 'admin_post_nopriv_' . $action ), 'ログインしていない要求は受けない' );
		}
		$this->assertSame( 10, has_filter( 'plugin_action_links_' . plugin_basename( CBJP_PRO_FILE ), [ $this->page, 'add_action_link' ] ) );
	}

	public function test_the_booted_plugin_registers_the_actions(): void {
		foreach ( LicensePage::actions() as $action ) {
			$this->assertTrue( has_action( 'admin_post_' . $action ), $action );
		}
	}

	public function test_adds_the_page_under_woocommerce_for_shop_managers(): void {
		global $submenu;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->page->add_menu();

		$entries = array_filter( $submenu['woocommerce'] ?? [], static fn ( array $entry ): bool => LicensePage::PAGE_SLUG === $entry[2] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'manage_woocommerce', array_values( $entries )[0][1] );
	}

	public function test_adds_a_license_link_to_the_plugin_row(): void {
		$links = $this->page->add_action_link( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertStringContainsString( 'page=' . LicensePage::PAGE_SLUG, $links[0] );
		$this->assertStringContainsString( '>License<', $links[0] );
		$this->assertArrayHasKey( 'deactivate', $links );
	}

	public function test_activates_with_a_valid_nonce(): void {
		$this->as_shop_manager();
		$this->server->fixture( 'activate_success' );
		$this->post( LicensePage::ACTION_ACTIVATE, ' ' . self::KEY . ' ' );

		$this->assertTrue( $this->page->process( LicensePage::ACTION_ACTIVATE )->is_success() );

		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
		$this->assertStringContainsString( 'The license key was activated on this site.', $this->render() );
	}

	/**
	 * 形の違う入力は、整えて別のキーに変えずに拒否する（`sanitize_text_field()` は `%XX` を取り除く。review-loop R1-6）。
	 */
	public function test_rejects_a_key_instead_of_cleaning_it(): void {
		$this->as_shop_manager();
		$this->post( LicensePage::ACTION_ACTIVATE, '0123456789abcdef%ab0123456789abcdef01234567' );

		$this->assertSame( LicenseResult::INVALID_KEY, $this->page->process( LicensePage::ACTION_ACTIVATE )->code );

		$this->assertCount( 0, $this->server->requests );
		$this->assertFalse( $this->store->is_activated() );
	}

	/**
	 * @dataProvider all_actions
	 */
	public function test_rejects_a_user_without_the_capability( string $action ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->post( $action, self::KEY );

		$this->assert_dies( $action, 'you are not allowed to manage the Cart Bridge JP Pro license' );
	}

	/**
	 * @dataProvider all_actions
	 */
	public function test_rejects_a_request_without_a_valid_nonce( string $action ): void {
		$this->as_shop_manager();
		$_POST[ LicensePage::KEY_FIELD ] = self::KEY;
		$_REQUEST['_wpnonce']            = wp_create_nonce( 'another-action' );

		$this->assert_dies( $action, 'The link you followed has expired.' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function all_actions(): array {
		$actions = [];

		foreach ( LicensePage::actions() as $action ) {
			$actions[ $action ] = [ $action ];
		}

		return $actions;
	}

	public function test_rejects_an_unknown_action(): void {
		$this->as_shop_manager();
		$this->post( 'cbjp_pro_license_unknown', self::KEY );

		$this->assert_dies( 'cbjp_pro_license_unknown', 'Unknown license action.' );
	}

	public function test_checks_the_status_and_deactivates(): void {
		$this->as_shop_manager();
		$this->server->fixture( 'status_active' )->fixture( 'deactivate_success' );
		$this->store->save_activation( self::KEY, 1000 );

		$this->post( LicensePage::ACTION_CHECK );
		$this->assertTrue( $this->page->process( LicensePage::ACTION_CHECK )->is_success() );
		$this->assertTrue( $this->store->last_check()['activated'] );
		$this->assertSame( 'status', $this->server->last_body()['wc_am_action'] );

		$this->post( LicensePage::ACTION_DEACTIVATE );
		$this->assertTrue( $this->page->process( LicensePage::ACTION_DEACTIVATE )->is_success() );
		$this->assertSame( 'deactivate', $this->server->last_body()['wc_am_action'] );
		$this->assertFalse( $this->store->is_activated() );
		$this->assertStringContainsString( 'The license key was deactivated on this site.', $this->render() );
	}

	public function test_shows_the_server_error_with_a_hint_for_a_key_change(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'activate_error_already_activated' );
		$this->post( LicensePage::ACTION_ACTIVATE, 'fedcba9876543210fedcba9876543210fedcba98' );

		$this->page->process( LicensePage::ACTION_ACTIVATE );

		$output = $this->render();
		$this->assertStringContainsString( 'The license key could not be activated. The license server returned an error: Cannot activate license key.', $output );
		$this->assertStringContainsString( 'deactivate it first, or check the status first if its update period has ended.', $output );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	/**
	 * 期限後はサーバーが無効化を断る（実測）。ローカルの有効化は残ることを案内する。
	 */
	public function test_explains_a_rejected_deactivation(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'deactivate_error' );
		$this->post( LicensePage::ACTION_DEACTIVATE );

		$this->page->process( LicensePage::ACTION_DEACTIVATE );

		$this->assertStringContainsString( 'no longer accepts deactivation. Cart Bridge JP Pro keeps working on this site.', $this->render() );
		$this->assertTrue( $this->store->is_activated() );
	}

	/**
	 * 猶予期間の後はリソースごと消え、状態確認もエラーになる（ソース。review-loop R1-2）。有効化済みのサイトではローカルの有効化が残ることを案内する。
	 */
	public function test_explains_a_failed_status_check_on_an_activated_site(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'activate_error_no_resources' );
		$this->post( LicensePage::ACTION_CHECK );

		$this->page->process( LicensePage::ACTION_CHECK );

		$output = $this->render();
		$this->assertStringContainsString( 'The license status could not be checked. The license server returned an error: No licensing resources exist.', $output );
		$this->assertStringContainsString( 'may no longer know this license. Cart Bridge JP Pro keeps working on this site.', $output );
		$this->assertTrue( $this->store->is_activated() );
	}

	/**
	 * 有効化していないサイトの失敗には、有効化済みのサイト向けの案内を付けない（review-loop R1-7）。
	 */
	public function test_adds_no_activated_site_hints_without_an_activation(): void {
		$this->as_shop_manager();
		$this->server->fixture( 'activate_error_unknown_key' )->fixture( 'status_inactive' );
		$this->post( LicensePage::ACTION_ACTIVATE, self::KEY );

		$this->page->process( LicensePage::ACTION_ACTIVATE );

		$output = $this->render();
		$this->assertStringContainsString( 'The license key could not be activated. The license server returned an error: A customer account does not exist for this license key.', $output );
		$this->assertStringNotContainsString( 'deactivate it first', $output );
		$this->assertStringNotContainsString( 'keeps working', $output );
	}

	/**
	 * `admin-post.php` の入口は、フックの名前から操作を取り出して処理し、設定画面へ戻してリクエストを終える（review-loop R1-7）。
	 * `exit` は差し替える（届くと PHPUnit が終了コード 0 のまま止まり、残りのテストが黙って走らなくなる。R2-1）。
	 */
	public function test_handles_a_request_from_admin_post(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'status_active' );
		$this->post( LicensePage::ACTION_CHECK );

		$terminated = false;
		$page       = new LicensePage(
			new LicenseManager( new LicenseConfig( 'https://example.com/', 1000 ), new LicenseClient( new LicenseConfig( 'https://example.com/', 1000 ) ), $this->store ),
			static function () use ( &$terminated ): void {
				$terminated = true;
			}
		);
		remove_all_actions( 'admin_post_' . LicensePage::ACTION_CHECK );
		$page->register();

		$redirected_to = null;
		add_filter(
			'wp_redirect',
			static function ( string $location ) use ( &$redirected_to ): string {
				$redirected_to = $location;

				return ''; // `wp_redirect()` を header() の前で止める（CLI ではヘッダーを送れない）。
			}
		);

		do_action( 'admin_post_' . LicensePage::ACTION_CHECK );

		$this->assertSame( LicensePage::page_url(), $redirected_to );
		$this->assertTrue( $terminated, '転送の後にリクエストを終える' );
		$this->assertSame( 'status', $this->server->last_body()['wc_am_action'] );
		$this->assertNotNull( $this->store->last_check() );
	}

	/**
	 * このサイトに保存できなかったときの文は、サーバーのエラーと言わず、操作ごとにやり直し方を案内する（PR #123 の最終確認）。
	 */
	public function test_explains_local_save_failures(): void {
		$this->as_shop_manager();
		LicenseStoreTest::fail_instance_insert();
		$this->post( LicensePage::ACTION_ACTIVATE, self::KEY );
		$this->page->process( LicensePage::ACTION_ACTIVATE );
		$output = $this->render();
		$this->assertStringContainsString( 'The license key could not be activated. The license data could not be saved on this site. Try again.', $output );
		$this->assertStringNotContainsString( 'The license server returned an error', $output );
	}

	public function test_explains_a_deactivation_the_site_could_not_record(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'deactivate_success' );
		LicenseStoreTest::fail_option_writes( LicenseStore::STATE_OPTION );
		$this->post( LicensePage::ACTION_DEACTIVATE );

		$this->page->process( LicensePage::ACTION_DEACTIVATE );

		$output = $this->render();
		$this->assertStringContainsString( 'Activate the same license key again, then deactivate it.', $output );
		$this->assertStringNotContainsString( 'no longer accepts deactivation', $output );
	}

	public function test_does_not_suggest_retrying_a_key_change_the_site_could_not_record(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'activate_success' );
		LicenseStoreTest::fail_option_writes( LicenseStore::KEY_OPTION );
		$this->post( LicensePage::ACTION_ACTIVATE, 'fedcba9876543210fedcba9876543210fedcba98' );

		$this->page->process( LicensePage::ACTION_ACTIVATE );

		$output = $this->render();
		$this->assertStringContainsString( 'The license server activated the key, but this site could not record it.', $output );
		$this->assertStringNotContainsString( 'could not record it. Try again.', $output );
	}

	public function test_escapes_the_notice(): void {
		$this->as_shop_manager();
		set_transient(
			'cbjp_pro_license_notice_' . get_current_user_id(),
			[
				'type'    => 'error',
				'message' => '<script>alert(1)</script>',
			]
		);

		$output = $this->render();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
		$this->assertStringNotContainsString( 'alert(1)', $this->render(), '通知は 1 回だけ出す' );
	}

	public function test_ignores_a_broken_notice(): void {
		$this->as_shop_manager();
		set_transient(
			'cbjp_pro_license_notice_' . get_current_user_id(),
			[
				'type'    => 'error" onmouseover="x',
				'message' => 'Broken',
			]
		);

		$this->assertStringNotContainsString( 'Broken', $this->render() );
	}

	public function test_shows_only_the_end_of_the_key(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );

		$output = $this->render();

		$this->assertStringNotContainsString( self::KEY, $output );
		$this->assertStringContainsString( 'Ending in 4567', $output );
		$this->assertStringContainsString( 'name="action" value="' . LicensePage::ACTION_DEACTIVATE . '"', $output );
		$this->assertStringContainsString( 'name="action" value="' . LicensePage::ACTION_CHECK . '"', $output );
		$this->assertStringContainsString( 'Use a different license key', $output );
		$this->assertStringNotContainsString( 'value="' . LicensePage::ACTION_SAVE_KEY . '"', $output );
	}

	public function test_offers_activation_when_not_activated(): void {
		$this->as_shop_manager();

		$output = $this->render();

		$this->assertStringContainsString( 'Not activated', $output );
		$this->assertStringContainsString( 'name="action" value="' . LicensePage::ACTION_ACTIVATE . '"', $output );
		$this->assertStringNotContainsString( 'value="' . LicensePage::ACTION_DEACTIVATE . '"', $output );
		$this->assertStringContainsString( 'name="_wpnonce"', $output );
	}

	/**
	 * キーが無い間にできること（D30・R3-8b）。有効化の状態（ローカルの記録）で出し分ける。
	 */
	public function test_explains_what_works_without_and_with_an_activated_key(): void {
		$this->as_shop_manager();

		$output = $this->render();
		$this->assertStringContainsString( 'customers, orders, and coupons can only be previewed with a dry run', $output );
		$this->assertStringNotContainsString( 'With the activated key', $output );

		$this->store->save_activation( self::KEY, 1000 );

		$output = $this->render();
		// 接続先が扱える操作だけ（ColorMe のクーポンは取込みだけ、受注のエクスポートはプレミアムプランだけ）なので、全部ができるとは書かない（PR #124 G1-4）。
		$this->assertStringContainsString( 'With the activated key, customers, orders, and coupons can be imported and exported on this site, as far as the connected shop supports each operation.', $output );
		$this->assertStringNotContainsString( 'can only be previewed with a dry run', $output );
	}

	public function test_offers_only_saving_the_key_when_it_is_unreadable(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		update_option( LicenseStore::KEY_OPTION, 'broken' );

		$output = $this->render();

		$this->assertStringContainsString( 'cannot be read', $output );
		$this->assertStringContainsString( 'value="' . LicensePage::ACTION_SAVE_KEY . '"', $output );
		$this->assertStringNotContainsString( 'value="' . LicensePage::ACTION_DEACTIVATE . '"', $output );
		$this->assertStringNotContainsString( 'value="' . LicensePage::ACTION_ACTIVATE . '"', $output );
	}

	public function test_offers_saving_the_key_when_an_activation_has_no_key(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		delete_option( LicenseStore::KEY_OPTION );

		$output = $this->render();

		$this->assertStringContainsString( 'value="' . LicensePage::ACTION_SAVE_KEY . '"', $output );
		$this->assertStringNotContainsString( 'value="' . LicensePage::ACTION_DEACTIVATE . '"', $output );
	}

	public function test_explains_an_expired_check_on_an_activated_site(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );
		$this->store->record_check( false, 'Expired' );

		$output = $this->render();

		$this->assertStringContainsString( 'The license server does not list this site as activated.', $output );
		$this->assertStringContainsString( 'Update period: Expired', $output );
		$this->assertStringContainsString( 'keeps working on this site', $output );
	}

	/**
	 * 販売サイトへ送るもの（backlog `r3-8a-license-client/R1-X2`・R3-8c）。接続先が設定されているビルドだけ、送り先の host を添えて出す。
	 */
	public function test_tells_what_is_sent_to_the_license_server(): void {
		$this->as_shop_manager();

		$output = $this->render();

		$this->assertStringContainsString( 'sends the license key, the address of this site, and the version of Cart Bridge JP Pro to the license server (example.com)', $output );
		$this->assertStringContainsString( 'Updates are checked only while a license key is activated on this site.', $output );

		$page = self::page( new LicenseConfig( '', 0 ), $this->store );
		ob_start();
		$page->render_page();
		$this->assertStringNotContainsString( 'sends the license key', (string) ob_get_clean() );
	}

	/**
	 * DB を複製したサイトで無効化すると、元のサイトもライセンスから外れる（無効化は instance で消す。backlog `r3-8a-license-client/R1-X1` を記録だけにした代わり）。
	 */
	public function test_warns_that_deactivating_a_copied_site_affects_the_original(): void {
		$this->as_shop_manager();
		$this->store->save_activation( self::KEY, 1000 );

		$this->assertStringContainsString( 'deactivating here also removes the original site from the license, and the original site stops receiving updates', $this->render() );
	}

	public function test_shows_no_forms_without_a_configured_server(): void {
		$this->as_shop_manager();
		$page = self::page( new LicenseConfig( '', 0 ), $this->store );

		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'The license server is not configured', $output );
		$this->assertStringNotContainsString( '<form', $output );
	}

	public function test_renders_nothing_for_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * `wp_die()` で止まること（WP のテスト基盤は状態コードを残さないので、理由の文で見分ける）と、副作用が無いこと。
	 */
	private function assert_dies( string $action, string $reason ): void {
		$died = null;

		try {
			$this->page->process( $action );
		} catch ( WPDieException $e ) {
			$died = $e;
		}

		$this->assertInstanceOf( WPDieException::class, $died, 'process() should stop the request.' );
		$this->assertStringContainsString( $reason, $died->getMessage() );

		// 副作用が無いこと: 要求を送らず、キー・状態・通知を保存しない。
		$this->assertCount( 0, $this->server->requests );
		$this->assertFalse( get_option( LicenseStore::KEY_OPTION ) );
		$this->assertFalse( $this->store->is_activated() );
		$this->assertFalse( get_transient( 'cbjp_pro_license_notice_' . get_current_user_id() ) );
	}

	private function as_shop_manager(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
	}

	private function post( string $action, ?string $key = null ): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );

		if ( null !== $key ) {
			$_POST[ LicensePage::KEY_FIELD ] = $key;
		}
	}

	private function render(): string {
		ob_start();
		$this->page->render_page();

		return (string) ob_get_clean();
	}

	private static function page( LicenseConfig $config, LicenseStore $store ): LicensePage {
		return new LicensePage( new LicenseManager( $config, new LicenseClient( $config ), $store ) );
	}
}
