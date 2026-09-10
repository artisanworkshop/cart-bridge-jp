<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

/**
 * 金額文字列と1/100単位（minor units）の整数の相互変換。
 *
 * 移行後検証レポート（D17。`Sync\VerificationReport`）の合計計算用。`CanonicalProduct::$price`等が
 * 浮動小数点誤差を避けるため金額を文字列で保持する設計（CLAUDE.md）に揃え、合算は整数で行い
 * floatを使わない。ASP側は円（小数なし）だがWoo側の`get_total()`は小数を含みうるため、
 * 両者を同じ単位（1/100）に落として比較する。
 */
final class Money {

	private function __construct() {}

	/**
	 * `"1000"` / `"1234.5"` / `"-12.345"` のような10進文字列（またはint/float）を1/100単位のintへ変換する。
	 * 小数第3位で四捨五入し、第4位以降は見ない。非数値・空文字列・非スカラーはnull。
	 */
	public static function to_minor_units( mixed $amount ): ?int {
		if ( is_int( $amount ) ) {
			return $amount * 100;
		}

		if ( is_float( $amount ) ) {
			// float入力（WooCommerce側のAPIが返しうる）は固定桁の10進文字列へ落としてから同じ経路で解析する。
			$amount = number_format( $amount, 4, '.', '' );
		}

		if ( ! is_string( $amount ) ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\s*(-?)(\d+)(?:\.(\d*))?\s*$/', $amount, $matches ) ) {
			return null;
		}

		$fraction = str_pad( substr( $matches[3] ?? '', 0, 3 ), 3, '0' );
		$minor    = ( (int) $matches[2] ) * 100 + (int) substr( $fraction, 0, 2 );

		if ( (int) $fraction[2] >= 5 ) {
			++$minor;
		}

		return '-' === $matches[1] ? -$minor : $minor;
	}

	/**
	 * 1/100単位のintを `"1234.56"` 形式の10進文字列へ戻す（UI側で通貨書式にする）。
	 */
	public static function format_minor_units( int $minor ): string {
		$absolute = abs( $minor );

		return sprintf( '%s%d.%02d', $minor < 0 ? '-' : '', intdiv( $absolute, 100 ), $absolute % 100 );
	}
}
