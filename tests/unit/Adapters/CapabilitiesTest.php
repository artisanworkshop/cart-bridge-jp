<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters;

use CartBridgeJP\Adapters\Capabilities;
use WP_UnitTestCase;

final class CapabilitiesTest extends WP_UnitTestCase {

	/**
	 * D20の値オブジェクト規則: 新しい引数は末尾に既定値付きで追加する（外部アダプタが11個の位置引数で
	 * `new Capabilities(...)`を呼んでも壊れない）。D22の`supports_per_variant_stock_management`は
	 * 宣言しないアダプタを安全側（混在した商品を止める側）に倒すため、既定は`false`。
	 */
	public function test_per_variant_stock_management_defaults_to_false_for_legacy_positional_construction(): void {
		$capabilities = new Capabilities( true, true, true, true, true, true, true, true, true, true, 600 );

		$this->assertFalse( $capabilities->supports_per_variant_stock_management );
	}

	public function test_per_variant_stock_management_can_be_declared_and_is_exposed_by_to_array(): void {
		$declared = new Capabilities( true, true, true, true, true, true, true, true, true, true, 600, true );
		$default  = new Capabilities( true, true, true, true, true, true, true, true, true, true, 600 );

		$this->assertTrue( $declared->supports_per_variant_stock_management );
		$this->assertTrue( $declared->to_array()['supports_per_variant_stock_management'] );
		$this->assertFalse( $default->to_array()['supports_per_variant_stock_management'] );
		$this->assertSame( 600, $declared->to_array()['rate_limit_per_minute'], '既存の位置引数の意味が変わっていない' );
	}

	/**
	 * D24: `beta_features`の既定は空（ベータ機能なし）。宣言しない外部アダプタ（11個の位置引数）も壊れない。
	 */
	public function test_beta_features_default_to_empty_for_legacy_positional_construction(): void {
		$capabilities = new Capabilities( true, true, true, true, true, true, true, true, true, true, 600 );

		$this->assertSame( [], $capabilities->beta_features );
		$this->assertSame( [], $capabilities->to_array()['beta_features'] );
	}

	public function test_beta_features_are_exposed_by_to_array(): void {
		$capabilities = new Capabilities( true, true, true, true, true, true, true, true, true, true, 600, false, [ Capabilities::BETA_ORDER_EXPORT, Capabilities::BETA_IMAGE_PUSH ] );

		$this->assertSame( [ 'order_export', 'image_push' ], $capabilities->to_array()['beta_features'] );
	}

	/**
	 * 外部アダプタ（`cbjp/adapters/register`）が返す値は型が実行時に強制されない（原則8）。文字列以外・空文字・重複・
	 * 飛んだキーが混ざっても、UI が受け取る値は重複のない非空文字列の連番配列（JSON配列）になる。
	 */
	public function test_to_array_normalizes_beta_features_from_a_misbehaving_adapter(): void {
		$messy = [
			5   => 'order_export',
			7   => null,
			9   => [ 'image_push' ],
			11  => 42,
			13  => '',
			15  => 'order_export',
			'k' => 'image_push',
			17  => new \stdClass(),
			19  => false,
		];

		$capabilities = new Capabilities( true, true, true, true, true, true, true, true, true, true, 600, false, $messy );
		$normalized   = $capabilities->to_array()['beta_features'];

		$this->assertSame( [ 'order_export', 'image_push' ], $normalized );
		$this->assertTrue( array_is_list( $normalized ), 'キーが飛ぶとJSONがオブジェクトになりUIの.includes()が落ちる' );
		$this->assertSame( '["order_export","image_push"]', wp_json_encode( $normalized ) );
	}
}
