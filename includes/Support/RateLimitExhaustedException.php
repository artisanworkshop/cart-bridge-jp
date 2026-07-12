<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

use Exception;

/**
 * RateLimiter::wait() が最大待機時間内にトークンを確保できなかった場合に投げられる。
 * JobManager はこれを捕捉してジョブを `paused` にする。
 */
final class RateLimitExhaustedException extends Exception {

	public function __construct( private readonly string $platform ) {
		parent::__construct( sprintf( 'Rate limit exhausted for platform "%s".', $platform ) );
	}

	public function platform(): string {
		return $this->platform;
	}
}
