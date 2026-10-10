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
	 * D20の値オブジェクト規則: 新しい引数は末尾に既定値付きで追加する（外部アダプタが既定値の手前までの位置引数で
	 * `new Capabilities(...)`を呼んでも壊れない）。D22の`supports_per_variant_stock_management`は
	 * 宣言しないアダプタを安全側（混在した商品を止める側）に倒すため、既定は`false`。
	 */
	public function test_per_variant_stock_management_defaults_to_false_when_not_declared(): void {
		$capabilities = self::capabilities();

		$this->assertFalse( $capabilities->supports_per_variant_stock_management );
	}

	public function test_per_variant_stock_management_can_be_declared_and_is_exposed_by_to_array(): void {
		$declared = self::capabilities( supports_per_variant_stock_management: true );
		$default  = self::capabilities();

		$this->assertTrue( $declared->supports_per_variant_stock_management );
		$this->assertTrue( $declared->to_array()['supports_per_variant_stock_management'] );
		$this->assertFalse( $default->to_array()['supports_per_variant_stock_management'] );
		$this->assertSame( 600, $declared->to_array()['rate_limit_per_minute'] );
	}

	/**
	 * D24: `beta_features`の既定は空（ベータ機能なし）。宣言しない外部アダプタも壊れない。
	 */
	public function test_beta_features_default_to_empty_when_not_declared(): void {
		$capabilities = self::capabilities();

		$this->assertSame( [], $capabilities->beta_features );
		$this->assertSame( [], $capabilities->to_array()['beta_features'] );
	}

	public function test_beta_features_are_exposed_by_to_array(): void {
		$capabilities = self::capabilities( beta_features: [ Capabilities::BETA_IMAGE_PUSH ] );

		$this->assertSame( [ 'image_push' ], $capabilities->to_array()['beta_features'] );
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

		$capabilities = self::capabilities( beta_features: $messy );
		$normalized   = $capabilities->to_array()['beta_features'];

		$this->assertSame( [ 'order_export', 'image_push' ], $normalized );
		$this->assertTrue( array_is_list( $normalized ), 'キーが飛ぶとJSONがオブジェクトになりUIの.includes()が落ちる' );
		$this->assertSame( '["order_export","image_push"]', wp_json_encode( $normalized ) );
	}

	/**
	 * issue #75 の完了条件: 外部アダプタが**配列でない**値を `beta_features` に渡しても落ちない（`array` 型にすると
	 * `new Capabilities()` が TypeError になり、`/connections` ごと落ちる）。`to_array()` は空配列を返す。
	 *
	 * @dataProvider provider_non_array_beta_features
	 *
	 * @param mixed $value 配列でない `beta_features`。
	 */
	public function test_non_array_beta_features_are_accepted_and_normalized_to_an_empty_list( mixed $value ): void {
		$capabilities = self::capabilities( beta_features: $value );

		$this->assertSame( [], $capabilities->to_array()['beta_features'] );
		$this->assertSame( '[]', wp_json_encode( $capabilities->to_array()['beta_features'] ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function provider_non_array_beta_features(): array {
		return [
			'string'   => [ 'order_export' ],
			'null'     => [ null ],
			'int'      => [ 1 ],
			'bool'     => [ true ],
			'stdClass' => [ new \stdClass() ],
		];
	}

	/**
	 * R3-6c1: 顧客・受注・クーポンの能力は Pro の `CommerceCapabilities` へ移した。無料版の能力は名前付き引数で作る。
	 */
	public function test_capabilities_hold_no_customer_order_or_coupon_fields(): void {
		$this->assertSame(
			[ 'can_create_category', 'can_push_images', 'has_tags', 'has_reviews', 'has_variants', 'rate_limit_per_minute', 'supports_per_variant_stock_management', 'beta_features' ],
			array_keys( self::capabilities()->to_array() )
		);
	}

	private static function capabilities( bool $supports_per_variant_stock_management = false, mixed $beta_features = [] ): Capabilities {
		return new Capabilities(
			can_create_category: true,
			can_push_images: true,
			has_tags: true,
			has_reviews: true,
			has_variants: true,
			rate_limit_per_minute: 600,
			supports_per_variant_stock_management: $supports_per_variant_stock_management,
			beta_features: $beta_features
		);
	}
}
