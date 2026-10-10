<?php
/**
 * Pro アドオンの起動（R3-7。無料版の拡張点の版の確認は R3-6c1）。
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

	public function test_does_not_boot_without_woocommerce_or_the_free_plugin(): void {
		$this->assertFalse( \CartBridgeJP\Pro\cbjp_pro_maybe_boot( false, true ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_requirements_notice' ) );
		$this->assertFalse( has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_autoload_notice' ) );
	}

	public function test_does_not_boot_without_its_autoloader(): void {
		$this->assertFalse( \CartBridgeJP\Pro\cbjp_pro_maybe_boot( true, false ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_autoload_notice' ) );
		$this->assertFalse( has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_requirements_notice' ) );
	}

	/**
	 * R3-6c1: 無料版の拡張点の版（`CBJP_EXTENSION_API_VERSION`）が Pro の要る版より古いと起動せず、更新を促す。
	 */
	public function test_does_not_boot_with_an_outdated_free_plugin(): void {
		$this->assertFalse( \CartBridgeJP\Pro\cbjp_pro_maybe_boot( true, true, CBJP_PRO_REQUIRED_EXTENSION_API - 1 ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_outdated_free_plugin_notice' ) );
		$this->assertFalse( has_action( 'admin_notices', 'CartBridgeJP\\Pro\\cbjp_pro_render_missing_autoload_notice' ) );
	}

	public function test_reads_the_free_extension_api_version(): void {
		$this->assertSame( CBJP_EXTENSION_API_VERSION, \CartBridgeJP\Pro\cbjp_pro_free_extension_api() );
		$this->assertGreaterThanOrEqual( CBJP_PRO_REQUIRED_EXTENSION_API, \CartBridgeJP\Pro\cbjp_pro_free_extension_api(), 'このリポジトリの無料版は Pro が要る版を満たす' );
	}

	public function test_outdated_free_plugin_notice_is_shown_to_administrators_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringContainsString( 'requires a newer version of Cart Bridge JP', $this->render( 'CartBridgeJP\\Pro\\cbjp_pro_render_outdated_free_plugin_notice' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$this->assertSame( '', $this->render( 'CartBridgeJP\\Pro\\cbjp_pro_render_outdated_free_plugin_notice' ) );
	}

	public function test_boots_when_the_requirements_are_met(): void {
		$this->assertTrue( \CartBridgeJP\Pro\cbjp_pro_maybe_boot( true, true ) );
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
