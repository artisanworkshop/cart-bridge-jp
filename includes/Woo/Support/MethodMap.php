<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * `cbjp_settings_{platform}` オプション（F1-6の `/settings/mappings/{platform}` REST が書く。
 * 現状は未実装のため常に空配列＝全て「未マッピング」経路に倒れる）から決済/配送/受注ステータスの
 * ユーザー設定マッピングを読む。
 */
final class MethodMap {

	public function __construct( private readonly string $platform ) {}

	public function payment_method_title( ?string $method_id ): ?string {
		return null !== $method_id ? $this->lookup( 'payment_map', $method_id ) : null;
	}

	public function shipping_method_title( ?string $method_id ): ?string {
		return null !== $method_id ? $this->lookup( 'shipping_map', $method_id ) : null;
	}

	/**
	 * ユーザー設定に対応する値があれば上書きし、無ければCanonical側の値（既にWooステータス
	 * スラッグ）をそのまま返す。
	 */
	public function order_status( string $canonical_status ): string {
		return $this->lookup( 'status_map', $canonical_status ) ?? $canonical_status;
	}

	private function lookup( string $map_key, string $key ): ?string {
		$settings = get_option( "cbjp_settings_{$this->platform}", [] );

		if ( ! is_array( $settings ) || ! is_array( $settings[ $map_key ] ?? null ) ) {
			return null;
		}

		$value = $settings[ $map_key ][ $key ] ?? null;

		return Value::string( $value );
	}
}
