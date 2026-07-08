<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical\Concerns;

/**
 * `CanonicalModel::canonical_json()` / `checksum()` の既定実装。
 * `to_array()` を実装したクラスで use するだけで checksum 算出が揃う。
 */
trait ChecksumTrait {

	/**
	 * @return array<string,mixed>
	 */
	abstract public function to_array(): array;

	public function canonical_json(): string {
		return (string) wp_json_encode( self::deep_ksort( $this->to_array() ) );
	}

	public function checksum(): string {
		return hash( 'sha256', $this->canonical_json() );
	}

	/**
	 * @param array<int|string,mixed> $data
	 * @return array<int|string,mixed>
	 */
	private static function deep_ksort( array $data ): array {
		ksort( $data );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::deep_ksort( $value );
			}
		}

		return $data;
	}
}
