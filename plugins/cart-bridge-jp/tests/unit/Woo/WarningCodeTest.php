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

	/**
	 * D23: 「Any」バリエーションはプラットフォーム非依存でexport blocking（`CanonicalProduct::$variants`で
	 * 表現できないため、`ALL_VARIATIONS_EXCLUDED`と同じ位置づけ）。バリエーションIDのdetail付きでも判定できる。
	 */
	public function test_variation_any_attribute_unsupported_is_export_blocking(): void {
		$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::VARIATION_ANY_ATTRIBUTE_UNSUPPORTED ] ) );
		$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::with_detail( WarningCode::VARIATION_ANY_ATTRIBUTE_UNSUPPORTED, '123' ) ] ) );
	}

	/**
	 * D22: 在庫管理の混在は、止めるかどうかがプラットフォームの能力（`Capabilities::
	 * $supports_per_variant_stock_management`）次第のため、`indicates_export_blocking()`には登録しない
	 * （登録すると、バリエーション単位で在庫管理できるASPでも止まってしまう）。専用の判定で識別する。
	 */
	public function test_variation_stock_management_mixed_is_capability_gated_not_export_blocking(): void {
		$this->assertFalse( WarningCode::indicates_export_blocking( [ WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED ] ) );
		$this->assertTrue( WarningCode::indicates_variation_stock_mixed( [ WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED ] ) );
		$this->assertTrue( WarningCode::indicates_variation_stock_mixed( [ 'other', WarningCode::with_detail( WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED, 'x' ) ] ) );
		$this->assertFalse( WarningCode::indicates_variation_stock_mixed( [ WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT ] ) );
		$this->assertFalse( WarningCode::indicates_variation_stock_mixed( [] ) );
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
			'category_map_unresolved'  => [ WarningCode::CATEGORY_MAP_UNRESOLVED ],
			'payment_method_unmapped'  => [ WarningCode::PAYMENT_METHOD_UNMAPPED ],
			'shipping_method_unmapped' => [ WarningCode::SHIPPING_METHOD_UNMAPPED ],
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
		foreach ( [ WarningCode::PAYMENT_METHOD_UNMAPPED, WarningCode::SHIPPING_METHOD_UNMAPPED ] as $code ) {
			$warning = WarningCode::with_detail( $code, 'pay-1' );

			$this->assertTrue( WarningCode::indicates_unresolved_reference( [ $warning ] ), $code );
			$this->assertTrue( WarningCode::indicates_unresolved_reference( [ 'sku_duplicate:X', $warning ] ), $code );
			$this->assertFalse( WarningCode::indicates_pending_export( $warning ), $code );
			$this->assertFalse( WarningCode::indicates_export_blocking( [ $warning ] ), $code );
		}
	}

	public function test_other_warnings_do_not_indicate_mapping_required(): void {
		$this->assertFalse( WarningCode::indicates_mapping_required( WarningCode::with_detail( WarningCode::ORDER_STATUS_UNKNOWN, 'x' ) ) );
		$this->assertFalse( WarningCode::indicates_mapping_required( WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, '1' ) ) );
		$this->assertFalse( WarningCode::indicates_mapping_required( 'payment_method_unmapped_extra:1' ) );
		$this->assertFalse( WarningCode::indicates_mapping_required( '' ) );
	}

	/**
	 * R3-0n: 受注の商品・顧客の未解決は、未インポートなのかASP側で削除済みなのかを区別できない（実店舗の受注では
	 * この 2 コードの参照先はすべて ColorMe 側で削除済みだった）。`reference_pending_import` から外して中立の注記にするが、
	 * 未インポートなら後から解決しうるので checksum はキャッシュしない（`indicates_unresolved_reference()`）ままにする。
	 */
	public function test_order_product_and_customer_refs_are_unresolved_but_not_pending_import(): void {
		foreach ( [ WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, WarningCode::ORDER_CUSTOMER_UNRESOLVED ] as $code ) {
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
		$warning = WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp-axes' );

		$this->assertTrue( WarningCode::indicates_unresolved_reference( [ $warning ] ) );
		$this->assertFalse( WarningCode::indicates_pending_import( $warning ) );
		$this->assertFalse( WarningCode::indicates_reference_not_found( $warning ) );
		$this->assertFalse( WarningCode::indicates_export_blocking( [ $warning ] ) );
		$this->assertNotSame( WarningCode::ORDER_LINE_VARIATION_UNRESOLVED, WarningCode::ORDER_LINE_VARIATION_UNMATCHED );
	}

	/**
	 * R3-0n で受注の2コードを外した後も、他の参照未解決（カテゴリ・在庫の親商品）は従来どおり
	 * `reference_pending_import` の対象（初回 dry-run の「未インポート起因」の注記）。
	 */
	public function test_other_unresolved_references_are_still_pending_import(): void {
		$this->assertTrue( WarningCode::indicates_pending_import( WarningCode::with_detail( WarningCode::CATEGORY_REF_UNRESOLVED, '10' ) ) );
		$this->assertTrue( WarningCode::indicates_pending_import( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_UNRESOLVED, '1' ) ) );
		$this->assertFalse( WarningCode::indicates_reference_not_found( WarningCode::with_detail( WarningCode::CATEGORY_REF_UNRESOLVED, '10' ) ) );
		$this->assertFalse( WarningCode::indicates_reference_not_found( 'order_line_product_unresolved_extra:1' ) );
	}

	/**
	 * D25（issue #98）: 往復で送らない／上書きしない 2 つのコードは終端の情報。止める警告・checksum を保存しない警告・CSV の
	 * 注記のどれにも入れない（入れると、送らない実体が「移行できない」扱いになったり、毎回再処理されたりする）。
	 */
	public function test_link_direction_codes_are_informational_only(): void {
		foreach ( [ WarningCode::LINKED_BY_IMPORT_NOT_EXPORTED, WarningCode::LINKED_BY_EXPORT_NOT_IMPORTED ] as $code ) {
			$this->assertFalse( WarningCode::indicates_export_blocking( [ $code ] ), $code );
			$this->assertFalse( WarningCode::indicates_unresolved_reference( [ $code ] ), $code );
			$this->assertFalse( WarningCode::indicates_variation_stock_mixed( [ $code ] ), $code );
			$this->assertFalse( WarningCode::indicates_pending_import( $code ), $code );
			$this->assertFalse( WarningCode::indicates_pending_export( $code ), $code );
			$this->assertFalse( WarningCode::indicates_mapping_required( $code ), $code );
		}
	}

	/**
	 * D25: `Sync\Importer`が`unchanged`に数えるのは、取込みがエクスポートで結ばれた実体を上書きしなかった結果だけ。
	 */
	public function test_kept_by_link_direction_matches_only_the_import_side_code(): void {
		$this->assertTrue( WarningCode::indicates_kept_by_link_direction( [ WarningCode::PRODUCT_SAVE_FAILED, WarningCode::LINKED_BY_EXPORT_NOT_IMPORTED ] ) );
		$this->assertFalse( WarningCode::indicates_kept_by_link_direction( [ WarningCode::LINKED_BY_IMPORT_NOT_EXPORTED ] ) );
		$this->assertFalse( WarningCode::indicates_kept_by_link_direction( [ WarningCode::PRODUCT_SAVE_FAILED ] ) );
		$this->assertFalse( WarningCode::indicates_kept_by_link_direction( [] ) );
	}

	/**
	 * R3-1d（issue #78）: 標準・軽減以外の税区分の商品・バリエーションは送らない（プラットフォーム非依存）。取込みの税の設定の
	 * 警告・アダプタの換算不能は送信を止める判定に入れない（後者はアダプタ自身がスキップする）。
	 */
	public function test_tax_class_codes_are_registered_where_they_belong(): void {
		foreach ( [ WarningCode::TAX_CLASS_UNSUPPORTED, WarningCode::VARIATION_TAX_CLASS_UNSUPPORTED ] as $code ) {
			$this->assertTrue( WarningCode::indicates_export_blocking( [ WarningCode::with_detail( $code, 'x' ) ] ), $code );
			$this->assertFalse( WarningCode::indicates_tax_setup_required( $code ), $code );
		}

		foreach ( [ WarningCode::REDUCED_TAX_CLASS_NOT_FOUND, WarningCode::TAX_RATES_NOT_CONFIGURED, WarningCode::PRODUCT_PRICE_NOT_CONVERTIBLE, WarningCode::TAX_CLASS_MISSING ] as $code ) {
			$this->assertFalse( WarningCode::indicates_export_blocking( [ $code ] ), $code );
			// 受注も共有する判定には入れない（受注は取込みのたびに明細を作り直さない）。
			$this->assertFalse( WarningCode::indicates_unresolved_reference( [ $code ] ), $code );
			$this->assertFalse( WarningCode::indicates_pending_import( $code ), $code );
		}
	}

	/**
	 * D26: 税の設定を作れば消える取込みの警告は CSV の`note`で`tax_setup_required`。checksum を止めるのは軽減税率の税区分が
	 * 無くて標準に倒した商品だけ（`ProductWriter`が見る）。
	 */
	public function test_tax_setup_codes(): void {
		$this->assertTrue( WarningCode::indicates_tax_setup_required( WarningCode::REDUCED_TAX_CLASS_NOT_FOUND ) );
		$this->assertTrue( WarningCode::indicates_tax_setup_required( WarningCode::with_detail( WarningCode::TAX_RATES_NOT_CONFIGURED, 'reduced-rate' ) ) );
		$this->assertFalse( WarningCode::indicates_tax_setup_required( WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, 'x' ) ) );

		$this->assertTrue( WarningCode::indicates_reduced_tax_class_fallback( [ WarningCode::PRODUCT_SAVE_FAILED, WarningCode::REDUCED_TAX_CLASS_NOT_FOUND ] ) );
		$this->assertFalse( WarningCode::indicates_reduced_tax_class_fallback( [ WarningCode::with_detail( WarningCode::TAX_RATES_NOT_CONFIGURED, 'reduced-rate' ) ] ) );
		$this->assertFalse( WarningCode::indicates_reduced_tax_class_fallback( [] ) );
	}
}
