<?php
/**
 * wordpress.org 用スクリーンショットの準備（共有スキル wporg-screenshots の setup フック）。処理は同じディレクトリの lib.php の
 * `cbjp_shot_setup()`。capture.sh が tests サイトの mu-plugins の下へ写して `wp eval-file` で実行するので、lib.php は
 * wp-env がルートをマウントした wp-content/cbjp-dev から読む（フックの写しは lib.php を持たない）。
 *
 * 引数: <tests サイトの home_url> <dry-run するエンティティ（カンマ区切り。`shots.json` の `setup_args`）>
 *
 * @package CartBridgeJP
 */

if ( ! is_readable( WP_CONTENT_DIR . '/cbjp-dev/.claude/wporg-screenshots/lib.php' ) ) {
	fwrite( STDERR, "setup: cannot read lib.php from the wp-env root mount (wp-content/cbjp-dev).\n" );
	exit( 1 );
}

require_once WP_CONTENT_DIR . '/cbjp-dev/.claude/wporg-screenshots/lib.php';

cbjp_shot_setup( $args );
