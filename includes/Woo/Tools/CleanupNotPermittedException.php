<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use RuntimeException;

/**
 * サンプルクリーンアップを実行者の権限では完遂できない場合（本プラグインが作成した顧客アカウントが
 * あるのに `delete_users` を持たない等）に投げる。REST 層は 403 に変換する。
 */
final class CleanupNotPermittedException extends RuntimeException {}
