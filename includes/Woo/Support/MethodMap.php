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
	 * Woo配送方法IDから表示タイトルを解決する。登録されていないID・不正な形式の場合はnull。
	 * `flat_rate:5`のようなインスタンスID付きの値も受け付ける
	 * （`split_shipping_method_id()`参照。`WC()->shipping()->get_shipping_methods()`は
	 * ゾーンに紐付かない方式そのものの一覧のためインスタンスID部分では引けない）。
	 */
	public function shipping_method_title( string $method_id ): ?string {
		$split = self::split_shipping_method_id( $method_id );

		if ( null === $split ) {
			return null;
		}

		$methods = WC()->shipping()->get_shipping_methods();
		$method  = $methods[ $split[0] ] ?? null;

		return $method instanceof WC_Shipping_Method ? $method->get_method_title() : null;
	}

	/**
	 * Wooの配送方法IDを`method_id`（方式そのもの。例: `flat_rate`）と`instance_id`
	 * （ゾーン内のインスタンス番号。無ければ0）に分割する。`WC_Order_Item_Shipping`は
	 * この2つを別プロパティとして持つため、`flat_rate:5`のような複合IDを`method_id`へ
	 * 丸ごと設定すると`get_method_id()`が実在しない方式IDを返し、配送方法IDで判定する
	 * 他のコード（レポート・拡張機能等）と噛み合わなくなる。
	 *
	 * `/settings/mappings/{platform}`は値を不透明な文字列としてしか検証しないため、
	 * `flat_rate:abc`（数値でないインスタンス部）・`flat_rate:1.5`（整数でない）・`:5`
	 * （方式部が空）のような壊れた値が保存されうる。これらを`0`やインスタンス切り捨てで
	 * 黙って「それらしい」組へ解決すると、設定ミスが警告なく別のインスタンスとして
	 * 書き込まれてしまう（境界データはフェイルクローズで検証する。CLAUDE.md参照）ため、
	 * 形式が不正な場合はnullを返し、呼び出し元に「未マッピングと同様に扱う」判断を委ねる。
	 *
	 * @return ?array{0:string,1:int}
	 */
	public static function split_shipping_method_id( string $mapped_id ): ?array {
		$parts          = explode( ':', $mapped_id, 2 );
		$bare_method_id = $parts[0];

		if ( '' === $bare_method_id ) {
			return null;
		}

		if ( ! isset( $parts[1] ) ) {
			return [ $bare_method_id, 0 ];
		}

		// `is_numeric()`は`'1.5'`や`'1e2'`も真になるため、非負整数のみを許容する形式
		// （数字のみ）を明示的に要求する。
		if ( 1 !== preg_match( '/^\d+$/', $parts[1] ) ) {
			return null;
		}

		return [ $bare_method_id, (int) $parts[1] ];
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
