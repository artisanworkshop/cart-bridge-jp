<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

/**
 * ColorMe APIレスポンス（`json_decode` 由来の `mixed` 値）を安全にキャストするヘルパー。
 * `(string) $mixed` 等の直接キャストは値が配列だった場合に実行時エラーになるため、
 * ガードをこのクラス1箇所に集約する。全Transformerで共有する。
 */
final class Cast {

	private function __construct() {}

	/**
	 * 空文字は null に正規化する。ColorMeは同じ意味のフィールドで `""` と `null` を
	 * 混在させることがあり、正規化しないとchecksumが不安定になる。
	 */
	public static function to_string_or_null( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$string = (string) $value;

		return '' === $string ? null : $string;
	}

	public static function to_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			return (int) $value;
		}

		return null;
	}

	/**
	 * `null` は `null` のまま返す（`(bool) null === false` にしてしまうと
	 * 「未回答」と「いいえ」の区別がつかなくなる）。
	 */
	public static function to_bool_or_null( mixed $value ): ?bool {
		return is_bool( $value ) ? $value : null;
	}

	/**
	 * JPYの整数金額を文字列化する。桁区切りを入れると数値として使えなくなるため
	 * `number_format()` は使わない。
	 */
	public static function money( mixed $value ): string {
		return (string) ( self::to_int_or_null( $value ) ?? 0 );
	}

	/**
	 * `money()`と異なり、欠損・非数値を`0`へ丸めず`null`のまま透過する。呼び出し先が
	 * 「金額が0円」と「金額を復元できない」を区別してフェイルクローズ処理を分岐する場合に使う
	 * （例: `OrderItemBuilder::split_line_amount()`の`ORDER_TAX_SPLIT_UNAVAILABLE`経路）。
	 */
	public static function money_or_null( mixed $value ): ?string {
		$int = self::to_int_or_null( $value );

		return null === $int ? null : (string) $int;
	}

	/**
	 * ColorMeのUNIXタイムスタンプ（秒）をUTCのISO-8601文字列に変換する。
	 */
	public static function unix_to_iso( mixed $value ): ?string {
		$timestamp = self::to_int_or_null( $value );

		if ( null === $timestamp ) {
			return null;
		}

		return gmdate( 'c', $timestamp );
	}

	/**
	 * 先頭から見て最初の非空文字列を返す。PHPCSが短縮三項演算子 `?:` を禁止しているための代替。
	 */
	public static function first_non_empty( mixed ...$candidates ): ?string {
		foreach ( $candidates as $candidate ) {
			$string = self::to_string_or_null( $candidate );

			if ( null !== $string ) {
				return $string;
			}
		}

		return null;
	}

	/**
	 * 商品説明等のHTMLを `wp_kses_post` で浄化する（`docs/01-plan-colorme.md` §4）。
	 */
	public static function sanitize_html( mixed $value ): ?string {
		$string = self::to_string_or_null( $value );

		return null === $string ? null : wp_kses_post( $string );
	}

	/**
	 * 値の配列を文字列配列へ変換する。`null`/空文字のみを除外し、`'0'` のような
	 * falsyな文字列は保持する（`array_filter()` をコールバック無しで使うと
	 * `'0'` も除去されてしまうため、この用途では使わないこと）。
	 *
	 * @param array<int|string,mixed> $values
	 * @return array<int,string>
	 */
	public static function strings( array $values ): array {
		$result = [];

		foreach ( $values as $value ) {
			$string = self::to_string_or_null( $value );

			if ( null !== $string ) {
				$result[] = $string;
			}
		}

		return $result;
	}

	/**
	 * SEOメタタグ情報（タイトル・キーワード・ページ概要）。カテゴリー・グループ双方の
	 * `meta_tag` オブジェクトで共通のスキーマのため、ここに集約する。HTMLタグを含められない
	 * 仕様（swagger）のためsanitize_htmlではなくプレーン文字列として保持する。
	 *
	 * @return ?array<string,mixed>
	 */
	public static function meta_tag( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		return [
			'title'       => self::to_string_or_null( $value['title'] ?? null ),
			'keywords'    => self::to_string_or_null( $value['keywords'] ?? null ),
			'description' => self::to_string_or_null( $value['description'] ?? null ),
		];
	}

	/**
	 * カテゴリ参照キー。小カテゴリが無い（0）場合は大カテゴリのキーと同一になる。
	 */
	public static function category_ref( mixed $id_big, mixed $id_small ): ?string {
		$big = self::to_int_or_null( $id_big );

		if ( null === $big ) {
			return null;
		}

		$small = self::to_int_or_null( $id_small );

		return ( null !== $small && $small > 0 ) ? "{$big}-{$small}" : (string) $big;
	}
}
