<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\License;

use CartBridgeJP\Pro\License\LicenseClient;
use CartBridgeJP\Pro\License\LicenseConfig;
use CartBridgeJP\Pro\License\LicenseManager;
use CartBridgeJP\Pro\License\LicenseResult;
use CartBridgeJP\Pro\License\LicenseStore;
use CartBridgeJP\Pro\Tests\Fixtures\FakeLicenseServer;
use WP_UnitTestCase;

/**
 * ライセンスの操作（R3-8a）。有効化の状態はローカルの記録だけで決め、サーバーが成功を返した操作だけが変える（D14）。
 */
final class LicenseManagerTest extends WP_UnitTestCase {

	private const KEY       = '0123456789abcdef0123456789abcdef01234567';
	private const OTHER_KEY = 'fedcba9876543210fedcba9876543210fedcba98';

	private FakeLicenseServer $server;

	private LicenseStore $store;

	private LicenseManager $manager;

	public function set_up(): void {
		parent::set_up();
		$this->server  = FakeLicenseServer::install();
		$this->store   = new LicenseStore();
		$this->manager = self::manager( new LicenseConfig( 'https://example.com/', 1000 ), $this->store );
	}

	public function test_activation_saves_the_key_and_state(): void {
		$this->server->fixture( 'activate_success' );

		$this->assertTrue( $this->manager->activate( '  ' . self::KEY . "\n" )->is_success() );

		$this->assertSame( self::KEY, $this->server->last_body()['api_key'], '前後の空白を除いて送る' );
		$this->assertSame( $this->store->instance(), $this->server->last_body()['instance'] );
		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
		$this->assertTrue( $this->manager->is_activated() );
	}

	public function test_a_failed_activation_saves_nothing(): void {
		// 突き合わせの状態確認も失敗する（理由の文が有効化と違う）。返すのは有効化の失敗（review-loop R2-2）。
		$this->server->fixture( 'activate_error_unknown_key' )->fixture( 'activate_error_no_resources' );

		$result = $this->manager->activate( self::KEY );

		$this->assertSame( LicenseResult::SERVER_ERROR, $result->code );
		$this->assertSame( 'A customer account does not exist for this license key.', $result->message, '有効化の失敗の理由を返す（突き合わせの状態確認の理由ではない）' );
		$this->assertCount( 2, $this->server->requests );
		$this->assertFalse( $this->store->is_activated() );
		$this->assertFalse( get_option( LicenseStore::KEY_OPTION ) );
	}

	/**
	 * サーバーが有効化を記録したのに応答を受け取れなかったとき、状態確認で突き合わせる（review-loop R1-1）。突き合わせないと、
	 * 以後の有効化が「同じ instance で有効化済み」で断られ続け、未有効化の画面からは抜けられない。
	 *
	 * @dataProvider unconfirmed_activations
	 */
	public function test_reconciles_an_activation_the_server_recorded( string $activation ): void {
		if ( 'timeout' === $activation ) {
			$this->server->error( 'cURL error 28: Operation timed out' );
		} else {
			$this->server->fixture( $activation );
		}
		$this->server->fixture( 'status_active' );

		$this->assertTrue( $this->manager->activate( self::KEY )->is_success() );

		$this->assertSame( 'status', $this->server->last_body()['wc_am_action'] );
		$this->assertSame( $this->store->instance(), $this->server->last_body()['instance'] );
		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function unconfirmed_activations(): array {
		return [
			'timeout'           => [ 'timeout' ],
			'already activated' => [ 'activate_error_already_activated' ],
		];
	}

	public function test_does_not_reconcile_when_the_server_does_not_list_the_site(): void {
		$this->server->error( 'cURL error 28: Operation timed out' )->fixture( 'status_inactive' );

		$this->assertSame( LicenseResult::HTTP_ERROR, $this->manager->activate( self::KEY )->code );

		$this->assertCount( 2, $this->server->requests );
		$this->assertFalse( $this->store->is_activated() );
		$this->assertFalse( get_option( LicenseStore::KEY_OPTION ) );
	}

	/**
	 * キーの交換（有効化済み）では突き合わせない。サーバーは instance で有効化を見るので、前のキーの有効化を新しいキーで「有効」と読んでしまう。
	 */
	public function test_does_not_reconcile_a_key_change(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'activate_error_already_activated' )->fixture( 'status_active' );
		$this->manager->activate( self::KEY );

		$this->assertSame( LicenseResult::SERVER_ERROR, $this->manager->activate( self::OTHER_KEY )->code );

		$this->assertCount( 2, $this->server->requests );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	/**
	 * @dataProvider invalid_keys
	 */
	public function test_rejects_an_invalid_key_without_a_request( mixed $key ): void {
		$this->assertSame( LicenseResult::INVALID_KEY, $this->manager->activate( $key )->code );
		$this->assertCount( 0, $this->server->requests );
		$this->assertFalse( get_option( LicenseStore::KEY_OPTION ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_keys(): array {
		return [
			'empty'        => [ '' ],
			'spaces'       => [ '   ' ],
			'inner space'  => [ 'abc def' ],
			'symbols'      => [ 'abc<def>' ],
			'too long'     => [ str_repeat( 'a', 191 ) ],
			'not a string' => [ [ self::KEY ] ],
			'null'         => [ null ],
		];
	}

	/**
	 * instance を保存できないときは、保存していない instance をサーバーへ送らない（PR #123 G2-B1）。
	 */
	public function test_does_not_send_an_unsaved_instance(): void {
		LicenseStoreTest::fail_instance_insert();

		$this->assertSame( LicenseResult::STORAGE_ERROR, $this->manager->activate( self::KEY )->code );

		$this->assertCount( 0, $this->server->requests );
		$this->assertFalse( $this->store->is_activated() );
	}

	public function test_does_not_check_or_deactivate_with_an_unsaved_instance(): void {
		$this->store->save_activation( self::KEY, 1000 );
		delete_option( LicenseStore::INSTANCE_OPTION );
		LicenseStoreTest::fail_instance_insert();

		$this->assertSame( LicenseResult::STORAGE_ERROR, $this->manager->check_status()->code );
		$this->assertSame( LicenseResult::STORAGE_ERROR, $this->manager->deactivate()->code );

		$this->assertCount( 0, $this->server->requests );
		$this->assertTrue( $this->store->is_activated() );
	}

	/**
	 * サーバーが有効化しても手元に保存できなければ成功と言わない。次の有効化は突き合わせで保存し直せる（PR #123 G3-B1）。
	 */
	public function test_reports_a_failed_save_after_a_server_activation(): void {
		$this->server->fixture( 'activate_success' );
		$failing = LicenseStoreTest::fail_option_writes( LicenseStore::KEY_OPTION );

		$this->assertSame( LicenseResult::STORAGE_ERROR, $this->manager->activate( self::KEY )->code );
		$this->assertFalse( $this->store->is_activated() );

		remove_filter( 'query', $failing );
		$this->server->fixture( 'activate_error_already_activated' )->fixture( 'status_active' );

		$this->assertTrue( $this->manager->activate( self::KEY )->is_success() );
		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	public function test_reports_a_failed_save_after_a_server_deactivation(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->server->fixture( 'deactivate_success' );
		LicenseStoreTest::fail_option_writes( LicenseStore::STATE_OPTION );

		$this->assertSame( LicenseResult::STORAGE_ERROR, $this->manager->deactivate()->code );

		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	public function test_saves_a_key_again_when_an_activation_has_no_key(): void {
		$this->store->save_activation( self::KEY, 1000 );
		delete_option( LicenseStore::KEY_OPTION );

		$this->assertTrue( $this->manager->replace_unreadable_key( self::KEY )->is_success() );

		$this->assertSame( self::KEY, $this->store->key() );
		$this->assertCount( 0, $this->server->requests );
	}

	public function test_reports_a_failed_key_save(): void {
		$this->store->save_activation( self::KEY, 1000 );
		update_option( LicenseStore::KEY_OPTION, 'broken' );
		LicenseStoreTest::fail_option_writes( LicenseStore::KEY_OPTION );

		$this->assertSame( LicenseResult::STORAGE_ERROR, $this->manager->replace_unreadable_key( self::KEY )->code );
	}

	public function test_does_not_activate_without_a_configured_server(): void {
		$manager = self::manager( new LicenseConfig( '', 0 ), $this->store );

		$this->assertFalse( $manager->is_configured() );
		$this->assertSame( LicenseResult::NOT_CONFIGURED, $manager->activate( self::KEY )->code );
		$this->assertCount( 0, $this->server->requests );
		$this->assertFalse( $this->store->is_activated() );
	}

	/**
	 * 有効化済みでも別のキーを同じ instance で試せる（期限後に新しいキーを買った人のキーの交換。2026-10-11 ユーザー決定）。
	 */
	public function test_a_different_key_replaces_the_key_on_success(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'activate_success' );
		$this->manager->activate( self::KEY );
		$instance = $this->store->instance();

		$this->assertTrue( $this->manager->activate( self::OTHER_KEY )->is_success() );

		$this->assertSame( $instance, $this->server->last_body()['instance'] );
		$this->assertSame( self::OTHER_KEY, $this->store->key() );
		$this->assertTrue( $this->store->is_activated() );
	}

	/**
	 * サーバーにこのサイトの有効化が残っていれば（期限内）、サーバーが断る。前のキーと有効化はそのまま。
	 */
	public function test_a_rejected_key_change_keeps_the_current_key(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'activate_error_already_activated' );
		$this->manager->activate( self::KEY );

		$this->assertSame( LicenseResult::SERVER_ERROR, $this->manager->activate( self::OTHER_KEY )->code );

		$this->assertSame( self::KEY, $this->store->key() );
		$this->assertTrue( $this->store->is_activated() );
	}

	public function test_deactivation_clears_the_activation(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'deactivate_success' );
		$this->manager->activate( self::KEY );
		$instance = $this->store->instance();

		$this->assertTrue( $this->manager->deactivate()->is_success() );

		$this->assertSame( self::KEY, $this->server->last_body()['api_key'] );
		$this->assertSame( $instance, $this->server->last_body()['instance'] );
		$this->assertFalse( $this->store->is_activated() );
		$this->assertNull( $this->store->key() );
	}

	/**
	 * 期限後はサーバーが無効化を断る（実測）。ローカルの有効化は残す（消すと期限後は有効化し直せない。D14）。
	 */
	public function test_a_rejected_deactivation_keeps_the_activation(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'deactivate_error' );
		$this->manager->activate( self::KEY );

		$this->assertSame( LicenseResult::SERVER_ERROR, $this->manager->deactivate()->code );

		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	public function test_a_failed_connection_keeps_the_activation(): void {
		$this->server->fixture( 'activate_success' )->error( 'timed out' );
		$this->manager->activate( self::KEY );

		$this->assertSame( LicenseResult::HTTP_ERROR, $this->manager->deactivate()->code );

		$this->assertTrue( $this->store->is_activated() );
	}

	public function test_does_not_deactivate_without_an_activation(): void {
		$this->assertSame( LicenseResult::NOT_ALLOWED, $this->manager->deactivate()->code );
		$this->assertCount( 0, $this->server->requests );
	}

	public function test_does_not_deactivate_with_an_unreadable_key(): void {
		$this->server->fixture( 'activate_success' );
		$this->manager->activate( self::KEY );
		update_option( LicenseStore::KEY_OPTION, 'broken' );

		$this->assertSame( LicenseResult::NOT_ALLOWED, $this->manager->deactivate()->code );
		$this->assertCount( 1, $this->server->requests );
		$this->assertTrue( $this->store->is_activated() );
	}

	public function test_a_status_check_records_the_result(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'status_active' );
		$this->manager->activate( self::KEY );

		$this->assertTrue( $this->manager->check_status()->is_success() );

		$check = $this->store->last_check();
		$this->assertIsArray( $check );
		$this->assertTrue( $check['activated'] );
		$this->assertSame( 'January 9, 2027', $check['expires'] );
	}

	/**
	 * 期限後の状態確認は `inactive` を返す（サーバーがこのサイトの有効化を消す。実測）。ローカルの有効化は変えない（D14）。
	 */
	public function test_a_status_check_does_not_change_the_activation(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'status_expired' );
		$this->manager->activate( self::KEY );

		$this->assertTrue( $this->manager->check_status()->is_success() );

		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
		$this->assertFalse( $this->store->last_check()['activated'] );
		$this->assertSame( 'Expired', $this->store->last_check()['expires'] );
	}

	public function test_a_failed_status_check_records_nothing(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'activate_error_no_resources' );
		$this->manager->activate( self::KEY );

		$this->assertSame( LicenseResult::SERVER_ERROR, $this->manager->check_status()->code );

		$this->assertNull( $this->store->last_check() );
		$this->assertTrue( $this->store->is_activated() );
	}

	public function test_does_not_check_without_a_key(): void {
		$this->assertSame( LicenseResult::NOT_ALLOWED, $this->manager->check_status()->code );
		$this->assertCount( 0, $this->server->requests );
	}

	public function test_saves_a_key_again_when_the_saved_key_is_unreadable(): void {
		$this->server->fixture( 'activate_success' );
		$this->manager->activate( self::KEY );
		update_option( LicenseStore::KEY_OPTION, 'broken' );

		$this->assertTrue( $this->manager->replace_unreadable_key( ' ' . self::KEY . ' ' )->is_success() );

		$this->assertCount( 1, $this->server->requests, 'サーバーには問い合わせない' );
		$this->assertSame( self::KEY, $this->store->key() );
		$this->assertTrue( $this->store->is_activated() );
	}

	public function test_saving_a_key_again_needs_an_activation_with_an_unreadable_key(): void {
		$this->assertSame( LicenseResult::NOT_ALLOWED, $this->manager->replace_unreadable_key( self::KEY )->code, '有効化していない' );
		$this->assertFalse( get_option( LicenseStore::KEY_OPTION ) );

		$this->server->fixture( 'activate_success' );
		$this->manager->activate( self::KEY );

		$this->assertSame( LicenseResult::NOT_ALLOWED, $this->manager->replace_unreadable_key( self::OTHER_KEY )->code, 'キーを読める' );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	public function test_saving_a_key_again_rejects_an_invalid_key(): void {
		$this->server->fixture( 'activate_success' );
		$this->manager->activate( self::KEY );
		update_option( LicenseStore::KEY_OPTION, 'broken' );

		$this->assertSame( LicenseResult::INVALID_KEY, $this->manager->replace_unreadable_key( 'abc def' )->code );
		$this->assertSame( 'broken', get_option( LicenseStore::KEY_OPTION ) );
	}

	private static function manager( LicenseConfig $config, LicenseStore $store ): LicenseManager {
		return new LicenseManager( $config, new LicenseClient( $config ), $store );
	}
}
