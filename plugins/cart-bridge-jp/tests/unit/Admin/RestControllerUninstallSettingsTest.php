<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Core\Uninstaller;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * `GET/PUT /settings/uninstall`（R3-5a）。アンインストール時にプラグインのデータを消すかの設定。
 */
final class RestControllerUninstallSettingsTest extends WP_UnitTestCase {

	private const ROUTE = '/cbjp/v1/settings/uninstall';

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		parent::tear_down();
	}

	public function test_get_defaults_to_keeping_the_data(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'delete_data' => false,
				'can_change'  => true,
			],
			$response->get_data()
		);
	}

	/**
	 * 保存済みの値が `'yes'` でなければ（WP-CLI で入れた `1` などを含む）「消さない」と表示する。アンインストールの判定と同じ。
	 */
	public function test_get_reports_only_yes_as_deleting_the_data(): void {
		update_option( Uninstaller::DELETE_DATA_OPTION, '1', false );

		$this->assertFalse( $this->server->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) )->get_data()['delete_data'] );

		update_option( Uninstaller::DELETE_DATA_OPTION, 'yes', false );

		$this->assertTrue( $this->server->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) )->get_data()['delete_data'] );
	}

	public function test_put_persists_and_is_read_back(): void {
		$on = new WP_REST_Request( 'PUT', self::ROUTE );
		$on->set_body_params( [ 'delete_data' => true ] );
		$response = $this->server->dispatch( $on );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['delete_data'] );
		$this->assertSame( 'yes', get_option( Uninstaller::DELETE_DATA_OPTION ) );
		$this->assertTrue( $this->server->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) )->get_data()['delete_data'] );

		$off = new WP_REST_Request( 'PUT', self::ROUTE );
		$off->set_body_params( [ 'delete_data' => false ] );
		$response = $this->server->dispatch( $off );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['delete_data'] );
		$this->assertSame( 'no', get_option( Uninstaller::DELETE_DATA_OPTION ) );
	}

	/**
	 * 管理画面（`apiFetch`）は JSON の本文で送る。
	 */
	public function test_put_accepts_a_json_body(): void {
		$request = new WP_REST_Request( 'PUT', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'delete_data' => true ] ) );

		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$this->assertTrue( Uninstaller::delete_data_enabled() );
	}

	/**
	 * データを消す設定は真偽値の `true`/`false` だけを受け付ける。`"true"`・`1`・配列・欠損は 400 で、保存済みの値は変わらない
	 * （`(bool)` キャストや `rest_sanitize_boolean()` は `"false"` や `1` を黙って解釈してしまう）。
	 */
	public function test_put_rejects_anything_but_a_boolean_and_keeps_the_stored_value(): void {
		$bad_bodies = [
			'string true'  => [ 'delete_data' => 'true' ],
			'string yes'   => [ 'delete_data' => 'yes' ],
			'string false' => [ 'delete_data' => 'false' ],
			'int 1'        => [ 'delete_data' => 1 ],
			'int 0'        => [ 'delete_data' => 0 ],
			'null'         => [ 'delete_data' => null ],
			'array'        => [ 'delete_data' => [ true ] ],
			'missing'      => [ 'unrelated' => true ],
		];

		foreach ( [ true, false ] as $stored ) {
			Uninstaller::set_delete_data( $stored );

			foreach ( $bad_bodies as $label => $body ) {
				$request = new WP_REST_Request( 'PUT', self::ROUTE );
				$request->set_body_params( $body );
				$response = $this->server->dispatch( $request );

				$this->assertSame( 400, $response->get_status(), $label );
				$this->assertSame( 'cbjp_invalid_request', $response->as_error()->get_error_code(), $label );
				$this->assertSame( $stored, Uninstaller::delete_data_enabled(), "{$label}: 保存済みの値を変えない" );
			}
		}

		// クエリ文字列は常に文字列なので、`?delete_data=true` でオンにはできない。
		$query = new WP_REST_Request( 'PUT', self::ROUTE );
		$query->set_query_params( [ 'delete_data' => 'true' ] );

		$this->assertSame( 400, $this->server->dispatch( $query )->get_status() );
		$this->assertFalse( Uninstaller::delete_data_enabled() );
	}

	/**
	 * `manage_woocommerce` を持つがプラグインを削除できない shop_manager は、設定を見られるが変えられない（403。値は変わらない）。
	 * 画面がチェックボックスを止められるよう、GET の `can_change` は false。
	 */
	public function test_shop_manager_can_read_but_cannot_change_the_setting(): void {
		$shop_manager = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		wp_set_current_user( $shop_manager );

		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
		$this->assertFalse( current_user_can( 'delete_plugins' ) );

		$get = $this->server->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );

		$this->assertSame( 200, $get->get_status() );
		$this->assertSame(
			[
				'delete_data' => false,
				'can_change'  => false,
			],
			$get->get_data()
		);

		foreach ( [ true, false ] as $value ) {
			$put = new WP_REST_Request( 'PUT', self::ROUTE );
			$put->set_body_params( [ 'delete_data' => $value ] );
			$response = $this->server->dispatch( $put );

			$this->assertSame( 403, $response->get_status() );
		}

		$this->assertFalse( get_option( Uninstaller::DELETE_DATA_OPTION ) );
	}

	/**
	 * プラグインを削除できても `manage_woocommerce` が無ければ、このプラグインの設定には触れられない（他のルートと同じ）。
	 */
	public function test_users_without_manage_woocommerce_cannot_read_or_change_the_setting(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		get_userdata( $editor )->add_cap( 'delete_plugins' );
		wp_set_current_user( $editor );

		$this->assertTrue( current_user_can( 'delete_plugins' ) );
		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );

		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) )->get_status() );

		$put = new WP_REST_Request( 'PUT', self::ROUTE );
		$put->set_body_params( [ 'delete_data' => true ] );

		$this->assertSame( 403, $this->server->dispatch( $put )->get_status() );
		$this->assertFalse( get_option( Uninstaller::DELETE_DATA_OPTION ) );
	}
}
