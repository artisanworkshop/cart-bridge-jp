<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Fixtures;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Pro\Adapters\CommerceAdapter;
use CartBridgeJP\Pro\Adapters\CommerceAdapters;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;

/**
 * テストで接続先に `CommerceAdapter` を `cbjp/pro/commerce_adapters/register` で登録する（R3-6c1）。`CommerceAdapters` は無料版の
 * アダプタのインスタンスごとに結果を覚え、WP のテスト基盤はフックを戻しても静的変数を戻さないので、登録・解除のたびに捨てる。
 * `tear_down()` は `parent::tear_down()` の前後に `forget_commerce_adapters()` を呼ぶ（`RegistersEntityTypes` と同じ）。
 */
trait RegistersCommerceAdapters {

	private function register_commerce_adapter( CommerceAdapter $commerce ): void {
		$platform = $commerce->id();

		add_filter(
			CommerceAdapters::FILTER,
			static function ( $factories ) use ( $platform, $commerce ) {
				$factories              = is_array( $factories ) ? $factories : [];
				$factories[ $platform ] = static fn ( PlatformAdapter $adapter ): CommerceAdapter => $commerce; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- 組み立ての形（`CommerceAdapters` が接続先を渡す）に合わせる。

				return $factories;
			}
		);
		CommerceAdapters::reset_cache();
	}

	private function forget_commerce_adapters(): void {
		CommerceAdapters::reset_cache();
	}
}
