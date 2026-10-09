<?php
/**
 * Plugin Name: Cart Bridge JP Pro
 * Description: Pro add-on for Cart Bridge JP – Migrate for WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce, cart-bridge-jp
 * Author: Artisan Workshop
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cart-bridge-jp-pro
 *
 * WC requires at least: 10.0
 *
 * @package CartBridgeJP\Pro
 */

namespace CartBridgeJP\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBJP_PRO_VERSION', '0.1.0' );
define( 'CBJP_PRO_FILE', __FILE__ );
define( 'CBJP_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CBJP_PRO_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( CBJP_PRO_PATH . 'vendor/autoload.php' ) ) {
	require_once CBJP_PRO_PATH . 'vendor/autoload.php';
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CBJP_PRO_FILE, true );
		}
	}
);

// 無料版の起動（`cbjp_bootstrap()`。plugins_loaded の既定の優先度 10）より後に起動する。
add_action( 'plugins_loaded', __NAMESPACE__ . '\\cbjp_pro_bootstrap', 20 );

/**
 * Pro アドオンを起動する。WooCommerce と無料版（Cart Bridge JP）が無ければ管理画面に通知だけ出し、fatal にしない。
 * 依存は Pro から無料版への一方向（D29）。無料版が起動していないときは Pro も何もしない。
 */
function cbjp_pro_bootstrap(): void {
	if ( ! class_exists( \WooCommerce::class ) || ! class_exists( \CartBridgeJP\Core\Plugin::class ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\cbjp_pro_render_missing_requirements_notice' );
		return;
	}

	if ( ! class_exists( Core\Plugin::class ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\cbjp_pro_render_missing_autoload_notice' );
		return;
	}

	Core\Plugin::instance()->boot();
}

/**
 * WooCommerce・無料版が無いときの管理画面通知。
 */
function cbjp_pro_render_missing_requirements_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Cart Bridge JP Pro requires WooCommerce and Cart Bridge JP to be installed and active.', 'cart-bridge-jp-pro' )
	);
}

/**
 * Composerオートロード未生成時（開発環境）の管理画面通知。
 */
function cbjp_pro_render_missing_autoload_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Cart Bridge JP Pro could not load its dependencies. Run "composer install" in the plugin directory.', 'cart-bridge-jp-pro' )
	);
}
