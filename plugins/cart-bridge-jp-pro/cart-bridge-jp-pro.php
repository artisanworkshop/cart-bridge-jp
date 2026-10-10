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

// Pro が使う無料版の拡張点の版（無料版の `CBJP_EXTENSION_API_VERSION`。R3-6c1）。無料版のこれより古い版では起動しない。
define( 'CBJP_PRO_REQUIRED_EXTENSION_API', 1 );

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
	$requirements_met = class_exists( \WooCommerce::class ) && class_exists( \CartBridgeJP\Core\Plugin::class );

	// 前提が欠けていれば Pro のクラスを読み込まない（Pro のクラスが無料版の型を継承・実装すると、無料版が無いときの autoload が fatal になる）。
	cbjp_pro_maybe_boot( $requirements_met, $requirements_met && class_exists( Core\Plugin::class ), cbjp_pro_free_extension_api() );
}

/**
 * 無料版の拡張点の版（`CBJP_EXTENSION_API_VERSION`。定数の無い古い無料版は 0）。定数は無料版のメインファイルが定義するので、
 * 静的解析（Pro だけの PHPStan）が知らない名前を直接書かず `constant()` で読む。
 */
function cbjp_pro_free_extension_api(): int {
	if ( ! defined( 'CBJP_EXTENSION_API_VERSION' ) ) {
		return 0;
	}

	$version = constant( 'CBJP_EXTENSION_API_VERSION' );

	return is_int( $version ) ? $version : 0;
}

/**
 * 前提を確かめてから起動する。欠けていれば管理画面に通知を登録し、起動しない。
 * 判定を引数で受けるのは、前提が欠けた場合をテストで再現するため（テスト環境では WooCommerce と無料版が常に読み込まれている）。
 *
 * @param bool $requirements_met WooCommerce と無料版が読み込まれているか。
 * @param bool $autoloaded       Pro の autoload（vendor/autoload.php）が読み込めたか。
 * @param int  $free_api         無料版の拡張点の版（`cbjp_pro_free_extension_api()`）。
 * @return bool 起動したか。
 */
function cbjp_pro_maybe_boot( bool $requirements_met, bool $autoloaded, int $free_api = CBJP_PRO_REQUIRED_EXTENSION_API ): bool {
	if ( ! $requirements_met ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\cbjp_pro_render_missing_requirements_notice' );
		return false;
	}

	// 無料版が古い（Pro が使う拡張点が無い）と、Pro の種類の登録・無料版の型の継承が失敗しうる。起動せず更新を促す。
	if ( $free_api < CBJP_PRO_REQUIRED_EXTENSION_API ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\cbjp_pro_render_outdated_free_plugin_notice' );
		return false;
	}

	if ( ! $autoloaded ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\cbjp_pro_render_missing_autoload_notice' );
		return false;
	}

	Core\Plugin::instance()->boot();
	return true;
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
 * 無料版が Pro の要る拡張点の版より古いときの管理画面通知。
 */
function cbjp_pro_render_outdated_free_plugin_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Cart Bridge JP Pro requires a newer version of Cart Bridge JP. Update Cart Bridge JP to use the Pro add-on.', 'cart-bridge-jp-pro' )
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
