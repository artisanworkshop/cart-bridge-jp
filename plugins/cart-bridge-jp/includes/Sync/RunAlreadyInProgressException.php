<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use Exception;

/**
 * 同一プラットフォームで running のジョブがある場合の新規 run 開始拒否（レート制限保護）。
 */
final class RunAlreadyInProgressException extends Exception {

	public function __construct( string $platform ) {
		parent::__construct( sprintf( 'A run is already in progress for platform "%s".', $platform ) );
	}
}
