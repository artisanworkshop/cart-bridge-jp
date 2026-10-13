<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

/**
 * パスワード設定メールの状態を、比べて書き換える（CAS）ために、options の行を直接読み書きする（R3-10）。値は JSON の文字列で持つ（直列化しない）。
 *
 * `get_option()` はこのリクエストのキャッシュ（永続オブジェクトキャッシュのサイトではリクエストをまたぐ）を返すので、判定に使う値は DB から行ごと読む
 * （`get_var()` は空文字列を null にして「行が無い」と区別できない。CLAUDE.md）。書いた後は値と notoptions のキャッシュを捨てる。
 * `add_option()` は同時の作成で他の値を上書きするので、作成は `INSERT IGNORE` にする（CLAUDE.md・`LicenseStore::instance()` と同じ）。
 */
final class StateOption {

	private function __construct() {}

	/**
	 * 行の生の値と、それを読んだ配列（壊れた値は空の配列）。行が無ければ null。
	 *
	 * @return array{raw:string,value:array<string,mixed>}|null
	 */
	public static function read( string $name ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS の比較に使う現在値を DB から直接読む。
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$raw     = (string) $row['option_value'];
		$decoded = json_decode( $raw, true );

		return [
			'raw'   => $raw,
			'value' => is_array( $decoded ) ? $decoded : [],
		];
	}

	/**
	 * 行が無いときだけ作る。
	 *
	 * @param array<string,mixed> $value
	 * @return bool 作ったか（既にあれば false）。
	 */
	public static function insert( string $name, array $value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 作成の競合を一意キーで決める。直後にキャッシュを捨てる。
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$name,
				self::encode( $value )
			)
		);
		self::forget( $name );

		return 1 === $inserted;
	}

	/**
	 * 今の値が `$expected` のときだけ書き換える。
	 *
	 * @param array<string,mixed> $value
	 * @return bool 書き換えたか（他の要求が先に書き換えていれば false）。
	 */
	public static function replace( string $name, string $expected, array $value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 状態の遷移を比べて書き換える（CAS）。直後にキャッシュを捨てる。
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				self::encode( $value ),
				$name,
				$expected
			)
		);
		self::forget( $name );

		return 1 === $updated;
	}

	/**
	 * 行が無ければ作り、あれば `$expected` のときだけ書き換える（{@see self::insert()}・{@see self::replace()}）。
	 *
	 * @param array{raw:string,value:array<string,mixed>}|null $current {@see self::read()} の結果。
	 * @param array<string,mixed>                               $value
	 */
	public static function swap( string $name, ?array $current, array $value ): bool {
		return null === $current ? self::insert( $name, $value ) : self::replace( $name, $current['raw'], $value );
	}

	/**
	 * 比べずに消す（テスト・後片付け用）。
	 */
	public static function delete( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自分の状態の行を消す。直後にキャッシュを捨てる。
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		self::forget( $name );
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private static function encode( array $value ): string {
		$json = wp_json_encode( $value );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * 直接書いた行を次の `get_option()` が DB から読むよう、このリクエストのキャッシュ（値・notoptions・alloptions）を捨てる。
	 */
	private static function forget( string $name ): void {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}
}
