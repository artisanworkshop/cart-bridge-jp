<?php
/**
 * Pro のマニュアル用スクリーンショットの後片付け（共有スキル wporg-screenshots の teardown フック）。処理は ../shared/lib.php の
 * `cbjp_pm_teardown()`（trial・licensed 共通）。capture.sh がモックを外す前に `wp eval-file` で実行する。
 *
 * @package CartBridgeJP
 */

if ( ! is_readable( WP_CONTENT_DIR . '/cbjp-dev/.claude/pro-manual-screenshots/shared/lib.php' ) ) {
	fwrite( STDERR, "teardown: cannot read the shared lib from the wp-env root mount (wp-content/cbjp-dev).\n" );
	exit( 1 );
}

require_once WP_CONTENT_DIR . '/cbjp-dev/.claude/pro-manual-screenshots/shared/lib.php';

cbjp_pm_teardown( $args );
