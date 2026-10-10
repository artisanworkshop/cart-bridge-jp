<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * 拡張（Pro アドオン）が接続先の OAuth の認可に足すスコープの宣言（R3-6c2）。無料版は自分が使うスコープ（ColorMe は商品の 2 つ）だけを要求し、
 * 拡張は起動時にここへ宣言する（`docs/03-design-decisions.md` §10.0 決め残し 9）。
 *
 * フィルターにしないのは、フィルターの鎖は先に登録された別の拡張の例外・値の置き換えで途中から失われ、認可の要求と拡張自身の判定
 * （Pro はトークンに宣言したスコープが無ければ顧客・受注・クーポンを扱わない）が黙ってずれるため（PR #118 G1-3・G3-B1）。宣言はデータで、
 * ここでは何も実行しない。値は信用せず、要求を組み立てる側（`ColorMe\ColorMeOAuth::scopes()`）が既知のスコープだけを使う（原則 8）。
 *
 * 宣言はプロセスの中だけ（静的）。拡張は `plugins_loaded` で毎回宣言する。
 */
final class OAuthScopes {

	/**
	 * 接続先の ID => 宣言された値（検証前）。
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private static array $declared = [];

	private function __construct() {}

	/**
	 * `$platform` の認可に `$scopes` を足す。
	 *
	 * @param string $platform 接続先の ID（`PlatformAdapter::id()`）。
	 * @param mixed  $scopes   足すスコープ（文字列の配列）。型は信用せず、配列でなければ 1 つの値として扱う（文字列でなければ要求する側が捨てる）。
	 */
	public static function add( string $platform, mixed $scopes ): void {
		foreach ( is_array( $scopes ) ? array_values( $scopes ) : [ $scopes ] as $scope ) {
			self::$declared[ $platform ][] = $scope;
		}
	}

	/**
	 * `$platform` に宣言された値（検証前。重複を含みうる）。
	 *
	 * @return array<int,mixed>
	 */
	public static function declared( string $platform ): array {
		return self::$declared[ $platform ] ?? [];
	}

	/**
	 * 宣言を捨てる（テスト用。拡張の宣言も消えるので、本番のコードからは呼ばない）。
	 */
	public static function reset(): void {
		self::$declared = [];
	}
}
