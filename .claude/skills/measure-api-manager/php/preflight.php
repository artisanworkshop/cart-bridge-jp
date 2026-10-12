<?php
/**
 * setup の前の確認（何も変えない）: dev サイトで、状態ファイルが無く、消し残しが 0（API Manager・Pro のキーの記録・前回の製品や顧客・
 * mu-plugin が無い）であることを肯定形で確かめる。どれかを満たさなければ exit 2（am.sh setup は何もしない）。
 * 引数: site=<dev サイトの URL>
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$opts = cbjp_am_args( $args, [ 'site' ] );
cbjp_am_require_site( $opts );

if ( null !== cbjp_am_state() ) {
	cbjp_am_abort( 'a state file from an earlier run exists: ' . cbjp_am_state_path() . ' (run: am.sh teardown)' );
}

$counts = cbjp_am_leftovers( null );
$found  = array_filter( $counts );

if ( [] !== $found ) {
	cbjp_am_abort( 'the dev site is not clean: ' . wp_json_encode( $found ) . ' (API Manager, a saved Pro license, or data from an earlier run is present. Remove it by hand after checking what it is; this skill removes things only with its own state file bound to this database)' );
}

// WooCommerce・無料版・Pro が有効で、Pro が実際に起動したこと（Pro のメインファイルの定数は、無料版が無効・古くても定義される。G1-11）。
// 無料版のクラスを先に確かめる（無料版が無いと、Pro のクラスのオートロードが fatal になりうる）。Pro のクラスは起動で読み込み済みなので、オートロードしない。
$pro_booted = class_exists( 'WooCommerce' ) && class_exists( \CartBridgeJP\Core\Plugin::class )
	&& class_exists( \CartBridgeJP\Pro\Core\Plugin::class, false ) && \CartBridgeJP\Pro\Core\Plugin::instance()->is_booted();

if ( ! $pro_booted ) {
	cbjp_am_abort( 'WooCommerce, Cart Bridge JP, and Cart Bridge JP Pro must be active, and Pro must have started (check the admin notices for an outdated free plugin)' );
}

echo "preflight: clean\n";
