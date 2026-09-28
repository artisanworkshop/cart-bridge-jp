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
}
