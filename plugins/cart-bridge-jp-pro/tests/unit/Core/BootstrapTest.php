<?php
/**
 * Pro アドオンの起動（R3-7。中身は R3-6 で足す）。
 *
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Core;

use CartBridgeJP\Pro\Core\Plugin;
use WP_UnitTestCase;

/**
 * WooCommerce と無料版がそろっていれば起動し、欠けていれば管理画面に通知を出す。
 */
final class BootstrapTest extends WP_UnitTestCase {

	public function test_boots_after_the_free_plugin_without_notices(): void {
		$this->assertSame( 10, has_action( 'plugins_loaded', 'CartBridgeJP\\cbjp_bootstrap' ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', 'CartBridgeJP\\Pro\\cbjp_pro_bootstrap' ) );
		$this->assertTrue( Plugin::instance()->is_booted() );
		$this->assertFalse( has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_requirements_notice' ) );
		$this->assertFalse( has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_autoload_notice' ) );
	}

	public function test_requirements_notice_is_shown_to_administrators_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$admin_output = $this->render( 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_requirements_notice' );

		$this->assertStringContainsString( 'notice-error', $admin_output );
		$this->assertStringContainsString( 'Cart Bridge JP Pro requires WooCommerce and Cart Bridge JP to be installed and active.', $admin_output );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$this->assertSame( '', $this->render( 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_requirements_notice' ) );
	}

	public function test_autoload_notice_is_shown_to_administrators_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringContainsString( 'composer install', $this->render( 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_autoload_notice' ) );

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->render( 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_autoload_notice' ) );
	}

	public function test_declares_hpos_compatibility(): void {
		$features = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );

		$this->assertContains(
			plugin_basename( CBJP_PRO_FILE ),
			$features->get_compatible_plugins_for_feature( 'custom_order_tables' )['compatible']
		);
	}

	/**
	 * 通知を描いた HTML を返す。
	 *
	 * @param callable-string $callback 描画する関数。
	 */
	private function render( string $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}
}
