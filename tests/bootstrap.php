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

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
