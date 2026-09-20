<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use RuntimeException;

/**
 * 県コード修復ツールが ASP への照会に失敗して中断したことを表す（`PrefStateRepair` の内部用）。
 * `PrefStateRepair::run()` が捕捉し、処理済みの件数と再開位置（cursor）付きの結果として返す。
 */
final class RepairInterruptedException extends RuntimeException {

	public const RATE_LIMITED  = 'rate_limited';
	public const NOT_CONNECTED = 'not_connected';
	public const UNSUPPORTED   = 'unsupported';
	public const API_ERROR     = 'api_error';

	public function __construct( public readonly string $reason, string $message = '' ) {
		parent::__construct( '' !== $message ? $message : $reason );
	}
}
