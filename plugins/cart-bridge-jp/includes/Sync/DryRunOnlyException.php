<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use Exception;

/**
 * 拡張が dry-run だけにした種類（`Entities\EntityType::dry_run_only_reason()`。R3-8b）の、実際の取込み・エクスポートの run の作成・Retry の拒否。
 * 判定した時点の「キー => 理由」を運ぶ（REST は判定し直さずにこれを応答に使う）。
 */
final class DryRunOnlyException extends Exception {

	/**
	 * @param array<string,string> $reasons 種類のキー => 理由（空のこともある）。
	 */
	public function __construct( string $platform, private readonly array $reasons ) {
		parent::__construct( sprintf( 'Only a dry run is available for "%s" on platform "%s".', implode( '", "', array_keys( $reasons ) ), $platform ) );
	}

	/**
	 * @return array<string,string>
	 */
	public function reasons(): array {
		return $this->reasons;
	}
}
