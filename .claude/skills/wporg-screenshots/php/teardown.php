<?php
/**
 * wordpress.org 用スクリーンショットの後片付け。wp-env の tests サイトだけで、`capture.sh` が mu-plugin を消す**前**に
 * `wp eval-file` で実行する（撮影の終わり・`capture.sh cleanup`）。
 *
 * 引数: <tests サイトの home_url>
 *
 * mu-plugin（Color Me Shop API へのモック）を消した後に、撮影の run の残りのアクションや偽のトークンで管理画面・WP-Cron が
 * 動くと、実 API に通信が出うる（PR #112 G1-2）。そこで mu-plugin がまだある間に次を行う。
 * 1. home_url が引数と一致する（＝ tests サイト）ことを確かめる。
 * 2. `colorme` のトークンが無いか、撮影用の偽のトークン（`setup.php` が保存する `screenshot-dummy-token`）であることを確かめる。
 *    別のトークン・復号できない値なら、何も変えずに失敗する（tests サイトを誰かが店舗につないだ。その run も止めない。人が判断する）。
 * 3. 開いたままの `colorme` の run をキャンセルする（残ったアクションは閉じたジョブに対して何もせずに終わる）。
 * 4. 偽のトークンを消す。トークンが無ければ何もしない。
 *
 * プラグインか WooCommerce が無効なら、片付けられないので、`colorme` のトークンか開いた `colorme` のジョブが残っていれば失敗する
 * （無効化はデータを消さない。モックを消した後で有効に戻すと、残った run が実 API へ出うる。PR #112 G3）。何も残っていなければ何もしない
 * （PHPUnit の後はオプションが戻ってプラグインが無効になり、トークンもオプションごと消えている）。
 * 失敗したら終了コード 1。`capture.sh` は mu-plugin を残して止まる。
 *
 * @package CartBridgeJP
 */

use CartBridgeJP\Support\TokenStore;

$cbjp_teardown_fail = static function ( string $message ): void {
	fwrite( STDERR, "teardown: {$message}\n" );
	exit( 1 );
};

$cbjp_teardown_expected = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
if ( '' === $cbjp_teardown_expected || home_url() !== $cbjp_teardown_expected ) {
	$cbjp_teardown_fail( 'home_url ' . home_url() . " is not the tests site '{$cbjp_teardown_expected}'. Refusing to touch this site." );
}
if ( ! class_exists( TokenStore::class ) || ! function_exists( 'WC' ) ) {
	// 無効化はオプションもテーブルも消さない。トークンか開いた run が残ったままモックを消すと、後で有効に戻したときに
	// 残った run が偽のトークンで実 API へ出うる（PR #112 G3）。片付けられないので、残っていれば失敗して mu-plugin を残す。
	global $wpdb;
	$cbjp_teardown_raw   = get_option( 'cbjp_token_colorme', null );
	$cbjp_teardown_table = $wpdb->prefix . 'cbjp_jobs';
	$cbjp_teardown_open  = $cbjp_teardown_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $cbjp_teardown_table ) ) )
		? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cbjp_teardown_table} WHERE platform = 'colorme' AND status IN ('pending', 'running', 'paused')" )
		: 0;
	if ( ( null !== $cbjp_teardown_raw && '' !== $cbjp_teardown_raw ) || $cbjp_teardown_open > 0 ) {
		$cbjp_teardown_fail(
			'the plugin or WooCommerce is inactive on the tests site, but a colorme token or ' . $cbjp_teardown_open . ' open colorme job(s) remain.'
			. ' Activate them (npx wp-env run tests-cli wp plugin activate woocommerce cart-bridge-jp) while the mu-plugin is still installed, then run capture.sh cleanup again.'
		);
	}
	echo "teardown: the plugin is not active and no colorme token or open job remains; nothing to tear down\n";
	return;
}
if ( ! user_can( 1, 'manage_woocommerce' ) ) {
	$cbjp_teardown_fail( 'user 1 cannot manage WooCommerce on the tests site.' );
}
// run をキャンセルする前に、トークンの持ち主を確かめる（PR #112 G2-3）。無いか、このスキルの偽のトークンのときだけ進める。
// 復号できない値は `TokenStore::get()` が null を返すので、オプションの有無を生の値で見る。
$cbjp_teardown_store = new TokenStore( 'colorme' );
$cbjp_teardown_raw   = get_option( 'cbjp_token_colorme', null );
$cbjp_teardown_ours  = null !== $cbjp_teardown_raw && '' !== $cbjp_teardown_raw;
if ( $cbjp_teardown_ours && 'screenshot-dummy-token' !== ( $cbjp_teardown_store->get()['access_token'] ?? null ) ) {
	$cbjp_teardown_fail( 'a colorme token other than the screenshot dummy (or one that cannot be decrypted) is saved on the tests site. Not cancelling runs or deleting it.' );
}
wp_set_current_user( 1 );

$cbjp_teardown_rest = static function ( string $method, string $route, array $params = [] ): array {
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	}
	$response = rest_do_request( $request );

	return [
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	];
};

$cbjp_teardown_active = $cbjp_teardown_rest( 'GET', '/cbjp/v1/runs', [ 'platform' => 'colorme' ] );
if ( 200 !== $cbjp_teardown_active['status'] || ! is_array( $cbjp_teardown_active['data']['runs'] ?? null ) ) {
	$cbjp_teardown_fail( 'GET /runs returned HTTP ' . $cbjp_teardown_active['status'] . ': ' . wp_json_encode( $cbjp_teardown_active['data'] ) );
}
foreach ( $cbjp_teardown_active['data']['runs'] as $cbjp_teardown_run ) {
	$cbjp_teardown_run_id = is_array( $cbjp_teardown_run ) && is_string( $cbjp_teardown_run['run_id'] ?? null ) ? $cbjp_teardown_run['run_id'] : '';
	$cbjp_teardown_cancel = '' === $cbjp_teardown_run_id ? null : $cbjp_teardown_rest( 'POST', "/cbjp/v1/runs/{$cbjp_teardown_run_id}/cancel" );
	if ( null === $cbjp_teardown_cancel || 200 !== $cbjp_teardown_cancel['status'] ) {
		$cbjp_teardown_fail( 'could not cancel the open run ' . wp_json_encode( $cbjp_teardown_run ) . '.' );
	}
	echo "teardown: cancelled the open run {$cbjp_teardown_run_id}\n";
}

if ( ! $cbjp_teardown_ours ) {
	echo "teardown: no saved token\n";
	return;
}
$cbjp_teardown_store->delete();
if ( null !== ( new TokenStore( 'colorme' ) )->get() ) {
	$cbjp_teardown_fail( 'the screenshot dummy token is still saved after deleting it.' );
}
echo "teardown: deleted the screenshot dummy token\n";
