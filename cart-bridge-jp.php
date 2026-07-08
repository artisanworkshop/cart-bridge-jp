<?php
/**
 * Plugin Name: Cart Bridge JP – Migrate for WooCommerce
 * Description: Migrate products, customers, and orders between Japanese e-commerce platforms (Color Me Shop, MakeShop, BASE) and WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Artisan Workshop
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cart-bridge-jp
 * Domain Path: /languages
 *
 * WC requires at least: 8.0
 *
 * @package CartBridgeJP
 */

namespace CartBridgeJP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBJP_VERSION', '0.1.0' );
define( 'CBJP_FILE', __FILE__ );
define( 'CBJP_PATH', plugin_dir_path( __FILE__ ) );
define( 'CBJP_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( CBJP_PATH . 'vendor/autoload.php' ) ) {
	require_once CBJP_PATH . 'vendor/autoload.php';
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CBJP_FILE, true );
		}
	}
);

register_activation_hook( CBJP_FILE, [ Core\Activator::class, 'activate' ] );

add_action( 'plugins_loaded', __NAMESPACE__ . '\\cbjp_bootstrap' );

/**
 * プラグインを起動する。WooCommerce未有効時は管理画面通知のみ出しfatalにしない。
 */
function cbjp_bootstrap(): void {
	if ( ! class_exists( \WooCommerce::class ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\cbjp_render_missing_woocommerce_notice' );
		return;
	}

	if ( ! class_exists( Core\Plugin::class ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\cbjp_render_missing_autoload_notice' );
		return;
	}

	Core\Plugin::instance()->boot();
}

/**
 * WooCommerce未有効時の管理画面通知。
 */
function cbjp_render_missing_woocommerce_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Cart Bridge JP requires WooCommerce to be installed and active.', 'cart-bridge-jp' )
	);
}

/**
 * Composerオートロード未生成時（開発環境）の管理画面通知。
 */
function cbjp_render_missing_autoload_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Cart Bridge JP could not load its dependencies. Run "composer install" in the plugin directory.', 'cart-bridge-jp' )
	);
}
