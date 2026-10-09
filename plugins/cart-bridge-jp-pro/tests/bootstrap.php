<?php
/**
 * PHPUnit bootstrap（Pro アドオン）。wp-env の tests インスタンス（WP_TESTS_DIR）で実行する。
 *
 * WooCommerce → 無料版（Cart Bridge JP）→ Pro の順に読み込む（依存は Pro から無料版への一方向。D29）。
 *
 * @package CartBridgeJP\Pro
 */

$cbjp_pro_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $cbjp_pro_tests_dir ) {
	$cbjp_pro_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( "{$cbjp_pro_tests_dir}/includes/functions.php" ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLIブートストラップの標準出力であり、Webレスポンスではないため。
	echo "Could not find {$cbjp_pro_tests_dir}/includes/functions.php, have you run wp-env start?" . PHP_EOL;
	exit( 1 );
}

require_once "{$cbjp_pro_tests_dir}/includes/functions.php";

/**
 * WooCommerce・無料版・Pro を読み込む。
 */
function cbjp_pro_test_manually_load_plugins(): void {
	// テストはプラグインのマウント（wp-content/plugins/cart-bridge-jp-pro）を cwd にして実行する（無料版の tests/bootstrap.php と同じ理由）。
	$cbjp_pro_expected = realpath( WP_CONTENT_DIR . '/plugins/cart-bridge-jp-pro' );

	if ( false === $cbjp_pro_expected || realpath( dirname( __DIR__ ) ) !== $cbjp_pro_expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLIブートストラップの標準出力であり、Webレスポンスではないため。
		echo 'Run the tests from wp-content/plugins/cart-bridge-jp-pro (see composer test:wpenv), not from ' . dirname( __DIR__ ) . PHP_EOL;
		exit( 1 );
	}

	$woocommerce_candidates = glob( WP_CONTENT_DIR . '/plugins/woocommerce*/woocommerce.php' );

	if ( ! empty( $woocommerce_candidates ) ) {
		require $woocommerce_candidates[0];
	}

	require WP_CONTENT_DIR . '/plugins/cart-bridge-jp/cart-bridge-jp.php';
	require dirname( __DIR__ ) . '/cart-bridge-jp-pro.php';
}
tests_add_filter( 'muplugins_loaded', 'cbjp_pro_test_manually_load_plugins' );

require "{$cbjp_pro_tests_dir}/includes/bootstrap.php";

// WooCommerce のテーブル・ロールを作り、HPOS を権威ストレージにする（無料版の tests/bootstrap.php と同じ。Pro は受注を扱う）。
if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ロール追加をテストプロセスに反映するための意図的な再初期化。
	$GLOBALS['wp_roles'] = null;
	wp_roles();

	update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
}
