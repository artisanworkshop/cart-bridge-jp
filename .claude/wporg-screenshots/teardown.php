<?php
/**
 * wordpress.org 用スクリーンショットの後片付け（共有スキル wporg-screenshots の teardown フック）。処理は同じディレクトリの lib.php の
 * `cbjp_shot_teardown()`。capture.sh が mu-plugin（Color Me Shop API のモック）を消す**前**に `wp eval-file` で実行する
 * （撮影の終わり・`capture.sh cleanup`）。失敗したら終了コード 1 で、capture.sh は mu-plugin とフックを残して止まる。
 *
 * 引数: <tests サイトの home_url>
 *
 * @package CartBridgeJP
 */

if ( ! is_readable( WP_CONTENT_DIR . '/cbjp-dev/.claude/wporg-screenshots/lib.php' ) ) {
	fwrite( STDERR, "teardown: cannot read lib.php from the wp-env root mount (wp-content/cbjp-dev).\n" );
	exit( 1 );
}

require_once WP_CONTENT_DIR . '/cbjp-dev/.claude/wporg-screenshots/lib.php';

cbjp_shot_teardown( $args );
