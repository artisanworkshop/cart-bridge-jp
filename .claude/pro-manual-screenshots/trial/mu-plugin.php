<?php
/**
 * Pro のマニュアル用スクリーンショットのモック（共有スキル wporg-screenshots の mu-plugin フック）。中身は ../shared/mock.php で、
 * wp-env のルートのマウント（wp-content/cbjp-dev）から読み込む。読めなければ何も足さない（setup.php がモックの定数を確かめて止まる）。
 *
 * TEMPORARY. capture.sh が tests サイトの mu-plugins の下へ写し、撮影の後に消す。
 *
 * @package CartBridgeJP
 */

defined( 'ABSPATH' ) || exit;

if ( is_readable( WP_CONTENT_DIR . '/cbjp-dev/.claude/pro-manual-screenshots/shared/mock.php' ) ) {
	require_once WP_CONTENT_DIR . '/cbjp-dev/.claude/pro-manual-screenshots/shared/mock.php';
}
