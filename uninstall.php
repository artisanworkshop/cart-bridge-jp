<?php
/**
 * アンインストール処理。
 *
 * @package CartBridgeJP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

\CartBridgeJP\Core\Uninstaller::uninstall();
