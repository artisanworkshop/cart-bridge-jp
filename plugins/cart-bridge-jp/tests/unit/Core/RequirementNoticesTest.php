<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use CartBridgeJP\Core\Plugin;
use WP_UnitTestCase;

/**
 * 前提条件が欠けたときの通知（WooCommerce・オートロード・sodium）は、権限のある利用者に、「プラグイン」画面とこのプラグインの画面でだけ出す
 * （ガイドライン 11: 通知は範囲を絞る。wordpress.org の審査の指摘。issue #144）。
 */
final class RequirementNoticesTest extends WP_UnitTestCase {

	/**
	 * テストの前の画面（`set_current_screen()` は `is_admin()` も変えるので戻す）。
	 *
	 * @var mixed
	 */
	private $previous_screen;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		$this->previous_screen = $GLOBALS['current_screen'] ?? null;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		$GLOBALS['current_screen'] = $this->previous_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストで差し替えた画面を戻す。
		parent::tear_down();
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_notices(): array {
		return [
			'missing WooCommerce' => [ 'woocommerce' ],
			'missing autoload'    => [ 'autoload' ],
			'missing sodium'      => [ 'sodium' ],
		];
	}

	/**
	 * @dataProvider provide_notices
	 */
	public function test_notice_is_shown_on_the_plugins_screen_and_the_plugin_page( string $notice ): void {
		foreach ( [ 'plugins', 'woocommerce_page_cart-bridge-jp' ] as $screen ) {
			set_current_screen( $screen );
			$this->assertStringContainsString( 'notice notice-error', $this->render( $notice ), $screen );
		}
	}

	/**
	 * @dataProvider provide_notices
	 */
	public function test_notice_is_not_shown_on_other_screens( string $notice ): void {
		foreach ( [ 'dashboard', 'edit-post', 'woocommerce_page_wc-settings' ] as $screen ) {
			set_current_screen( $screen );
			$this->assertSame( '', $this->render( $notice ), $screen );
		}

		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 画面の無い状態（`get_current_screen()` が null）を作る。
		$this->assertSame( '', $this->render( $notice ), 'no screen' );
	}

	/**
	 * @dataProvider provide_notices
	 */
	public function test_notice_is_not_shown_to_users_who_cannot_activate_plugins( string $notice ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		set_current_screen( 'plugins' );

		$this->assertSame( '', $this->render( $notice ) );
	}

	public function test_sodium_notice_is_not_shown_when_the_extension_is_loaded(): void {
		set_current_screen( 'plugins' );

		$this->assertSame( '', $this->capture( static fn () => Plugin::instance()->render_sodium_notice( true ) ) );
	}

	private function render( string $notice ): string {
		switch ( $notice ) {
			case 'woocommerce':
				return $this->capture( static fn () => \CartBridgeJP\cbjp_render_missing_woocommerce_notice() );
			case 'autoload':
				return $this->capture( static fn () => \CartBridgeJP\cbjp_render_missing_autoload_notice() );
			case 'sodium':
				return $this->capture( static fn () => Plugin::instance()->render_sodium_notice( false ) );
			default:
				self::fail( "Unknown notice: {$notice}" );
		}
	}

	private function capture( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}
}
