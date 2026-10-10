<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Woo;

use CartBridgeJP\Pro\Woo\CommerceWarningCode;
use CartBridgeJP\Woo\WarningCode;
use WP_UnitTestCase;

/**
 * 顧客・受注・クーポンの警告コードに Pro が付けた印で、無料版の判定関数（`WarningCode::indicates_*()`）が答える（R3-6c1 で
 * 無料版の `WarningCodeTest` から分けた）。
 */
final class CommerceWarningCodeTest extends WP_UnitTestCase {

	/**
	 * E2-3 PR-Cレビュー指摘: `Woo\Reader\OrderReader::line_item_amounts()`が壊れた明細金額を
	 * `0`へフェイルクローズ済みでも、`ColorMeAdapter::push_order()`の`sale.details[].price`は
	 * 明示指定するとColorMeに恒久的な金額として記録されるため、`PRODUCT_PRICE_INVALID`と同じ
	 * 金銭的リスクでexport blocking対象であることを固定する。
	 */
	public function test_order_line_amount_invalid_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ CommerceWarningCode::ORDER_LINE_AMOUNT_INVALID ] ) );
	}

	/**
	 * 捏造した数量（`max(1, ...)`）をColorMeへ恒久的な受注数量として送らないことを固定する。
	 */
	public function test_order_line_quantity_invalid_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ CommerceWarningCode::ORDER_LINE_QUANTITY_INVALID ] ) );
	}

	/**
	 * R3-0m: 決済/配送の未マッピングは、カテゴリの未マッピングと同じく「マッピング設定（Mappings タブ）を
	 * 追加すれば消える」警告として dry-run CSV の `note` に `mapping_required` を付ける。detail の有無
	 * （インポート方向は ASP 側 ID、エクスポート方向は Woo 側 ID）に関わらず判定できる。
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function mapping_required_codes(): array {
		return [
			'payment_method_unmapped'  => [ CommerceWarningCode::PAYMENT_METHOD_UNMAPPED ],
			'shipping_method_unmapped' => [ CommerceWarningCode::SHIPPING_METHOD_UNMAPPED ],
		];
	}

	/**
	 * @dataProvider mapping_required_codes
	 */
	public function test_unmapped_codes_indicate_mapping_required( string $code ): void {
		$this->assertTrue( WarningCode::indicates_mapping_required( $code ) );
		$this->assertTrue( WarningCode::indicates_mapping_required( WarningCode::with_detail( $code, '1094475' ) ) );
		$this->assertTrue( WarningCode::indicates_mapping_required( WarningCode::with_detail( $code, 'flat_rate:6' ) ) );
		// 「参照先を先にインポートする」案内とは排他（`indicates_pending_import()` は mapping_required を除外する）。
		$this->assertFalse( WarningCode::indicates_pending_import( WarningCode::with_detail( $code, '1094475' ) ) );
	}

	/**
	 * R3-0m: 決済/配送の未マッピングは checksum をキャッシュさせない（`indicates_unresolved_reference()` の対象）。
	 * キャッシュすると、後からマッピングを設定しても checksum 一致で飛ばされ、取込み済みの受注が空の決済/配送方法の
	 * まま直らない。エクスポートの停止判定とエクスポート方向の注記は変えない。
	 */
	public function test_payment_and_shipping_unmapped_are_unresolved_but_not_export_blocking(): void {
		foreach ( [ CommerceWarningCode::PAYMENT_METHOD_UNMAPPED, CommerceWarningCode::SHIPPING_METHOD_UNMAPPED ] as $code ) {
			$warning = WarningCode::with_detail( $code, 'pay-1' );

			$this->assertTrue( WarningCode::indicates_unresolved_reference( [ $warning ] ), $code );
			$this->assertTrue( WarningCode::indicates_unresolved_reference( [ 'sku_duplicate:X', $warning ] ), $code );
			$this->assertFalse( WarningCode::indicates_pending_export( $warning ), $code );
			$this->assertFalse( WarningCode::indicates_export_blocking( [ $warning ] ), $code );
		}
	}

	public function test_other_warnings_do_not_indicate_mapping_required(): void {
		$this->assertFalse( WarningCode::indicates_mapping_required( WarningCode::with_detail( CommerceWarningCode::ORDER_STATUS_UNKNOWN, 'x' ) ) );
		$this->assertFalse( WarningCode::indicates_mapping_required( WarningCode::with_detail( CommerceWarningCode::ORDER_CUSTOMER_UNRESOLVED, '1' ) ) );
		$this->assertFalse( WarningCode::indicates_mapping_required( 'payment_method_unmapped_extra:1' ) );
	}

	/**
	 * R3-0n: 受注の商品・顧客の未解決は、未インポートなのかASP側で削除済みなのかを区別できない（実店舗の受注では
	 * この 2 コードの参照先はすべて ColorMe 側で削除済みだった）。`reference_pending_import` から外して中立の注記にするが、
	 * 未インポートなら後から解決しうるので checksum はキャッシュしない（`indicates_unresolved_reference()`）ままにする。
	 */
	public function test_order_product_and_customer_refs_are_unresolved_but_not_pending_import(): void {
		foreach ( [ CommerceWarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, CommerceWarningCode::ORDER_CUSTOMER_UNRESOLVED ] as $code ) {
			$warning = WarningCode::with_detail( $code, 'gone-1' );

			$this->assertTrue( WarningCode::indicates_reference_not_found( $warning ), $code );
			$this->assertFalse( WarningCode::indicates_pending_import( $warning ), $code );
			$this->assertTrue( WarningCode::indicates_unresolved_reference( [ $warning ] ), $code );
		}
	}

	/**
	 * R3-0n: 取り込み済みの variable 商品でバリエーションを特定できない明細。商品は取り込み済みなので
	 * 「先にインポートすれば消える」でも「未インポートか削除済み」でもない。variation が後から取り込まれれば
	 * 解決しうるので checksum はキャッシュしない。インポート方向の警告なのでエクスポートは止めない
	 * （エクスポート方向の`ORDER_LINE_VARIATION_UNRESOLVED`とは別コード）。
	 */
	public function test_variation_unmatched_is_retry_worthy_without_a_pending_note(): void {
		$warning = WarningCode::with_detail( CommerceWarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp-axes' );

		$this->assertTrue( WarningCode::indicates_unresolved_reference( [ $warning ] ) );
		$this->assertFalse( WarningCode::indicates_pending_import( $warning ) );
		$this->assertFalse( WarningCode::indicates_reference_not_found( $warning ) );
		$this->assertFalse( WarningCode::indicates_export_blocking( [ $warning ] ) );
		$this->assertNotSame( CommerceWarningCode::ORDER_LINE_VARIATION_UNRESOLVED, CommerceWarningCode::ORDER_LINE_VARIATION_UNMATCHED );
	}
}
