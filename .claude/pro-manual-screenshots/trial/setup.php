<?php
/**
 * Pro のマニュアル用スクリーンショット（trial: ライセンスキーを有効化していない Pro）の準備（共有スキル wporg-screenshots の setup フック）。
 * 処理は ../shared/lib.php の `cbjp_pm_setup_trial()`。
 *
 * @package CartBridgeJP
 */

if ( ! is_readable( WP_CONTENT_DIR . '/cbjp-dev/.claude/pro-manual-screenshots/shared/lib.php' ) ) {
	fwrite( STDERR, "setup: cannot read the shared lib from the wp-env root mount (wp-content/cbjp-dev).\n" );
	exit( 1 );
}

require_once WP_CONTENT_DIR . '/cbjp-dev/.claude/pro-manual-screenshots/shared/lib.php';

cbjp_pm_setup_trial( $args );
