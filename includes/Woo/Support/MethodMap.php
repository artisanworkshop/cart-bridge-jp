<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WC_Shipping_Method;
use WC_Shipping_Zones;

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
	 * マッピング先のゲートウェイIDが現在実際に登録されているかを検証する。プラグイン削除・
	 * 無効化等で存在しなくなったゲートウェイへのマッピングを「解決済み」のまま扱うと、
	 * `payment_gateway_title()`がID自体をフォールバック表示するだけで警告なく実在しない
	 * ゲートウェイが注文へ書き込まれてしまう（境界データはフェイルクローズで検証する。
	 * CLAUDE.md参照。Codexレビュー指摘）。
	 */
	public static function payment_gateway_exists( string $gateway_id ): bool {
		return isset( WC()->payment_gateways()->payment_gateways()[ $gateway_id ] );
	}

	/**
	 * Woo配送方法IDから表示タイトルを解決する。登録されていないID・不正な形式・
	 * ゾーンから削除済みのインスタンスの場合はnull。`flat_rate:5`のようなインスタンスID付きの
	 * 値も受け付ける（`split_shipping_method_id()`参照）。インスタンスID付きの場合、
	 * `WC()->shipping()->get_shipping_methods()`（ゾーンに紐付かない方式そのものの一覧）では
	 * 引けないため、実際のゾーンインスタンスを読み込みその設定済みタイトルを返す
	 * （店舗側でリネームされていることがあり、方式共通のジェネリックな名称よりも正確なため）。
	 */
	public function shipping_method_title( string $method_id ): ?string {
		$split = self::split_shipping_method_id( $method_id );

		if ( null === $split ) {
			return null;
		}

		[ $bare_method_id, $instance_id ] = $split;

		if ( 0 !== $instance_id ) {
			$method = self::instance_method( $split );

			// インスタンスの設定済みタイトル（店舗側でリネームされていることがあり、
			// 方式共通のジェネリックな名称よりも正確）を返す。
			return null !== $method ? $method->get_title() : null;
		}

		$methods = WC()->shipping()->get_shipping_methods();
		$method  = $methods[ $bare_method_id ] ?? null;

		return $method instanceof WC_Shipping_Method ? $method->get_method_title() : null;
	}

	/**
	 * マッピング先の配送方法（分割済みの`method_id`/`instance_id`の組）が現在実際に
	 * 存在するかを検証する。`shipping_method_exists()`・`shipping_method_title()`の
	 * 両方で共有する（インスタンスIDが無い単純なマッピングは、方式クラス自体が
	 * 読み込まれているかのみ確認する）。
	 *
	 * @param array{0:string,1:int} $split
	 */
	public static function shipping_method_instance_exists( array $split ): bool {
		if ( 0 === $split[1] ) {
			return isset( WC()->shipping()->get_shipping_methods()[ $split[0] ] );
		}

		return null !== self::instance_method( $split );
	}

	/**
	 * インスタンスID付き配送方法の実体を読み込む。ゾーンから削除された、または同じ
	 * instance_idが（ゾーンの再構成等で）別の方式に再利用されたインスタンスを
	 * 「マッピング済み」として扱うと、消えた配送設定へ黙って注文が紐付いてしまうため、
	 * `WC_Shipping_Zones::get_shipping_method()`が返す実際の方式IDとマッピング側の
	 * 方式部が一致するところまで確認する（`false`は「インスタンスが存在しない」の意味）。
	 *
	 * @param array{0:string,1:int} $split
	 */
	private static function instance_method( array $split ): ?WC_Shipping_Method {
		[ $bare_method_id, $instance_id ] = $split;
		$method                           = WC_Shipping_Zones::get_shipping_method( $instance_id );

		return ( $method instanceof WC_Shipping_Method && $method->id === $bare_method_id ) ? $method : null;
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
	 * マッピング値（`Woo\Writer\OrderWriter`が受け取る生の文字列）が、形式・実在の
	 * 両方の意味で使える配送方法かを判定する。`split_shipping_method_id()`（形式）と
	 * `shipping_method_instance_exists()`（実在）をまとめた便宜メソッド。
	 */
	public static function shipping_method_exists( string $mapped_id ): bool {
		$split = self::split_shipping_method_id( $mapped_id );

		return null !== $split && self::shipping_method_instance_exists( $split );
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
