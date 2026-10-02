<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

use RuntimeException;

/**
 * `PlatformLock::run()` がロックを取得できなかった（同じプラットフォームで別の操作が「判定 → 状態変更」の
 * 区間を実行中）。REST は 409 `cbjp_run_in_progress` で応答する（issue #57）。
 */
final class PlatformBusyException extends RuntimeException {

	public function __construct( private readonly string $platform ) {
		parent::__construct( sprintf( 'Another operation holds the lock for platform "%s".', $platform ) );
	}

	public function platform(): string {
		return $this->platform;
	}
}
