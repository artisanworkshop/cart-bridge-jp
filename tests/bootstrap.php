<?php
/**
 * PHPUnit bootstrap。wp-env の tests インスタンス（WP_TESTS_DIR）で実行する。
 *
 * @package CartBridgeJP
 */

$cbjp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $cbjp_tests_dir ) {
	$cbjp_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( "{$cbjp_tests_dir}/includes/functions.php" ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLIブートストラップの標準出力であり、Webレスポンスではないため。
	echo "Could not find {$cbjp_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh or wp-env start?" . PHP_EOL;
	exit( 1 );
}

require_once "{$cbjp_tests_dir}/includes/functions.php";

/**
 * WooCommerceとテスト対象プラグインを読み込む。
 */
function cbjp_test_manually_load_plugins(): void {
	// wp-env はzip取得したプラグインを "woocommerce.latest-stable" 等のURL由来の
	// ディレクトリ名で配置するため、固定パスではなくglobで解決する。
	$woocommerce_candidates = glob( WP_CONTENT_DIR . '/plugins/woocommerce*/woocommerce.php' );

	if ( ! empty( $woocommerce_candidates ) ) {
		require $woocommerce_candidates[0];
	}

	require dirname( __DIR__ ) . '/cart-bridge-jp.php';
}
tests_add_filter( 'muplugins_loaded', 'cbjp_test_manually_load_plugins' );

require "{$cbjp_tests_dir}/includes/bootstrap.php";

/**
 * wp-env はプラグインzipを配置するだけで有効化フックを走らせないため、WooCommerceの
 * テーブル（wc_orders等のHPOSテーブル含む）・ロール・product_typeタームが存在しない。
 * `wc_get_product()`/`WC_Order`のデータストアが動くために、テストスイート本体のbootstrap
 * 完了後（プラグイン読み込み後）にここで明示的にインストールする。
 * `beStrictAboutOutputDuringTests`のため、install()の出力はテストメソッド外で消費させる。
 */
if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();

	// install()が追加したロールを現プロセスのグローバルへ反映する（WP core既知の挙動）。
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ロール追加をテストプロセスに反映するための意図的な再初期化。
	$GLOBALS['wp_roles'] = null;
	wp_roles();

	// プラグインはHPOS（High-Performance Order Storage）必須（CLAUDE.md）のため、テストも
	// HPOSを権威ストレージとして有効化した状態で走らせる。`WC_Install::install()`は
	// wc_ordersテーブル自体は作成するが、権威データストアの切り替えは行わないため明示的に有効化する。
	update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
