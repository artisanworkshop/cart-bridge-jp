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

	/**
	 * D21-A（issue #72）: 作成確定後に処理が止まった商品は、次回exportで後続処理をやり直すため
	 * checksumをキャッシュしない（simple商品には他の未完了印が無く、この登録が唯一の根拠になる）。
	 * ただし、送信自体を止める（blocking）警告ではない。
	 */
	public function test_push_interrupted_after_create_is_unresolved_but_not_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_unresolved_reference( [ WarningCode::PUSH_INTERRUPTED_AFTER_CREATE ] ) );
		$this->assertFalse( WarningCode::indicates_export_blocking( [ WarningCode::PUSH_INTERRUPTED_AFTER_CREATE ] ) );
	}

	public function test_prices_include_tax_disabled_is_not_export_blocking(): void {
		$this->assertFalse( WarningCode::indicates_export_blocking( [ WarningCode::PRICES_INCLUDE_TAX_DISABLED ] ) );
	}

	/**
	 * E2-3 PR-Cレビュー指摘: `Woo\Reader\OrderReader::line_item_amounts()`が壊れた明細金額を
	 * `0`へフェイルクローズ済みでも、`ColorMeAdapter::push_order()`の`sale.details[].price`は
	 * 明示指定するとColorMeに恒久的な金額として記録されるため、`PRODUCT_PRICE_INVALID`と同じ
	 * 金銭的リスクでexport blocking対象であることを固定する。
	 */
	public function test_order_line_amount_invalid_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::ORDER_LINE_AMOUNT_INVALID ] ) );
	}

	/**
	 * 捏造した数量（`max(1, ...)`）をColorMeへ恒久的な受注数量として送らないことを固定する。
	 */
	public function test_order_line_quantity_invalid_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::ORDER_LINE_QUANTITY_INVALID ] ) );
	}
}
