<?php
/**
 * アンインストール処理。
 *
 * @package CartBridgeJP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// vendor/ はgit管理外のため、composer install 前のチェックアウトでも削除が失敗しないようガードする。
$cbjp_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $cbjp_autoload ) ) {
	require_once $cbjp_autoload;
}

if ( ! class_exists( \CartBridgeJP\Core\Uninstaller::class ) ) {
	require_once __DIR__ . '/includes/Core/Activator.php';
	require_once __DIR__ . '/includes/Core/Uninstaller.php';
}

\CartBridgeJP\Core\Uninstaller::uninstall();
