<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use RuntimeException;

/**
 * `tests/fixtures/{platform}/{name}.json` を読み込むテスト用ヘルパー。
 * `file_get_contents()` はPHPCSの `WordPress.WP.AlternativeFunctions`警告対象のため、
 * `wp_json_file_decode()` を使う。
 */
final class FixtureLoader {

	/**
	 * @return array<string,mixed>
	 */
	public static function load( string $platform, string $name ): array {
		$path = dirname( __DIR__, 2 ) . "/fixtures/{$platform}/{$name}.json";

		$decoded = wp_json_file_decode( $path, [ 'associative' => true ] );

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( "Fixture \"{$platform}/{$name}\" could not be decoded." );
		}

		return $decoded;
	}
}
