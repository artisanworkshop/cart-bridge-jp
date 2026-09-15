<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo;

use CartBridgeJP\Woo\WarningCode;
use WP_UnitTestCase;

final class WarningCodeTest extends WP_UnitTestCase {

	public function test_with_detail_omits_separator_for_empty_detail(): void {
		$this->assertSame( 'sku_duplicate', WarningCode::with_detail( 'sku_duplicate', '' ) );
	}

	public function test_split_round_trips_with_detail(): void {
		$warning = WarningCode::with_detail( WarningCode::SKU_DUPLICATE, 'ABC-1' );

		$this->assertSame( [ WarningCode::SKU_DUPLICATE, 'ABC-1' ], WarningCode::split( $warning ) );
	}

	/**
	 * detail自体（画像URL等）に`:`が含まれていても、最初の`:`でのみ分割されるため
	 * detail部分は壊れずに復元できる。
	 */
	public function test_split_handles_colon_inside_detail(): void {
		$detail  = 'https://shop.example.com/img.jpg';
		$warning = WarningCode::with_detail( WarningCode::IMAGE_DOWNLOAD_FAILED, $detail );

		$this->assertSame( [ WarningCode::IMAGE_DOWNLOAD_FAILED, $detail ], WarningCode::split( $warning ) );
	}

	public function test_split_returns_null_detail_when_no_separator(): void {
		$this->assertSame( [ WarningCode::ENTITY_NOT_SUPPORTED, null ], WarningCode::split( WarningCode::ENTITY_NOT_SUPPORTED ) );
	}

	/**
	 * R3レビュー指摘（Codex/Copilot）: これらの警告は`CanonicalProduct`が対応する状態を運ぶ
	 * フィールドを持たないため、無警告のままpushすると金銭的リスク（0円商品の公開・非課税
	 * 商品の通常課税化・バリエーション取り違え）に直結する。export blocking対象であることを
	 * 固定する（`PRICES_INCLUDE_TAX_DISABLED`は意図的に対象外——`docs/review-backlog.md`
	 * `e2-3-push-product/G1-out-of-scope-prices-include-tax`参照）。
	 */
	public function test_product_price_invalid_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::PRODUCT_PRICE_INVALID ] ) );
	}

	public function test_tax_status_not_taxable_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::TAX_STATUS_NOT_TAXABLE ] ) );
	}

	public function test_variation_axis_limit_exceeded_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::VARIATION_AXIS_LIMIT_EXCEEDED ] ) );
	}

	public function test_prices_include_tax_disabled_is_not_export_blocking(): void {
		$this->assertFalse( WarningCode::indicates_export_blocking( [ WarningCode::PRICES_INCLUDE_TAX_DISABLED ] ) );
	}
}
