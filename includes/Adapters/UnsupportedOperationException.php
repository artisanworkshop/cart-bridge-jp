<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

use Exception;

/**
 * アダプタがcapabilityで不可と宣言している操作が呼ばれた場合に投げる（防御の二重化）。
 */
final class UnsupportedOperationException extends Exception {

	public function __construct( string $platform, string $operation ) {
		parent::__construct( sprintf( 'Platform "%s" does not support operation "%s".', $platform, $operation ) );
	}
}
