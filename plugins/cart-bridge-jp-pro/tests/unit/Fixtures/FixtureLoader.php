<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Fixtures;

use RuntimeException;

/**
 * `tests/fixtures/{platform}/{name}.json` を読み込むテスト用ヘルパー（R3-6c1）。顧客・受注・クーポンのフィクスチャは Pro の
 * `tests/fixtures/` にあり、商品・ショップの設定などは無料版のテストのフィクスチャを使う（Pro を先に探す）。
 * `file_get_contents()` は PHPCS の警告対象のため `wp_json_file_decode()` を使う（無料版の `FixtureLoader` と同じ）。
 */
final class FixtureLoader {

	/**
	 * @return array<string,mixed>
	 */
	public static function load( string $platform, string $name ): array {
		$candidates = [
			dirname( __DIR__, 2 ) . "/fixtures/{$platform}/{$name}.json",
			WP_PLUGIN_DIR . "/cart-bridge-jp/tests/fixtures/{$platform}/{$name}.json",
		];

		foreach ( $candidates as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}

			$decoded = wp_json_file_decode( $path, [ 'associative' => true ] );

			if ( ! is_array( $decoded ) ) {
				throw new RuntimeException( "Fixture \"{$platform}/{$name}\" could not be decoded." );
			}

			return $decoded;
		}

		throw new RuntimeException( "Fixture \"{$platform}/{$name}\" was not found." );
	}
}
