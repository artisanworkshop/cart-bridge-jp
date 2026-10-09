<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * Canonicalモデルの `extras`（`array<string,mixed>`）から値を安全に取り出すための最小キャストヘルパー。
 * `Adapters\ColorMe\Transform\Cast` はColorMe名前空間専属のため、プラットフォーム非依存であるべき
 * Woo層はそちらをimportせず、このクラスを使う（アーキテクチャ原則1）。
 */
final class Value {

	private function __construct() {}

	public static function string( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$string = (string) $value;

		return '' === $string ? null : $string;
	}

	public static function int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			return (int) $value;
		}

		return null;
	}

	public static function bool( mixed $value ): ?bool {
		return is_bool( $value ) ? $value : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function array_or_null( mixed $value ): ?array {
		return is_array( $value ) ? $value : null;
	}
}
