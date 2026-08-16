<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WC_Shipping_Method;

/**
 * `cbjp_settings_{platform}` オプション（F1-6の `/settings/mappings/{platform}` REST が書く。
 * 現状は未実装のため常に空配列＝全て「未マッピング」経路に倒れる）から決済/配送/受注ステータスの
 * ユーザー設定マッピングを読む。
 *
 * `payment_map`/`shipping_map` の値は表示タイトルではなく**Wooの決済ゲートウェイID/配送方法ID**
 * （`docs/01-plan-colorme.md` の例: 「銀行振込 → bacs」の `bacs` 相当）。表示タイトルは
 * マッピングされたIDから `payment_gateway_title()`/`shipping_method_title()` で別途解決する。
 */
final class MethodMap {

	public function __construct( private readonly string $platform ) {}

	/**
	 * ASP側のmethod_idに対応するWoo決済ゲートウェイID（ユーザー設定マッピング）。
	 */
	public function mapped_payment_gateway_id( ?string $method_id ): ?string {
		return null !== $method_id ? $this->lookup( 'payment_map', $method_id ) : null;
	}

	/**
	 * ASP側のmethod_idに対応するWoo配送方法ID（ユーザー設定マッピング）。
	 */
	public function mapped_shipping_method_id( ?string $method_id ): ?string {
		return null !== $method_id ? $this->lookup( 'shipping_map', $method_id ) : null;
	}

	/**
	 * WooゲートウェイIDから表示タイトルを解決する。未登録のゲートウェイIDの場合、
	 * `WC_Payment_Gateways::get_payment_gateway_name_by_id()` はID自体をフォールバックとして
	 * 返す仕様のため、常に非空文字列を返す。
	 */
	public function payment_gateway_title( string $gateway_id ): string {
		return WC()->payment_gateways()->get_payment_gateway_name_by_id( $gateway_id );
	}

	/**
	 * Woo配送方法IDから表示タイトルを解決する。登録されていないIDの場合はnull。
	 */
	public function shipping_method_title( string $method_id ): ?string {
		$methods = WC()->shipping()->get_shipping_methods();
		$method  = $methods[ $method_id ] ?? null;

		return $method instanceof WC_Shipping_Method ? $method->get_method_title() : null;
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
