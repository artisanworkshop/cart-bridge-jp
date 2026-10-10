<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Entities\WarningFlag;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\WarningCode;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- 向き・行の種類を使わない説明もある（`describe()` のシグネチャは共通）。

/**
 * 受注の警告コードの判定の印と店舗向けの説明（`OrderType`。R3-6b1 で `Woo\WarningCode`・`Woo\WarningCatalog` から移した）。
 * **R3-6c で Pro アドオンへ移す**（`Entities/Commerce/` ごと）。文言を変えたら dry-run の CSV の説明も変わる（`WarningCatalogTest`）。
 */
final class OrderWarnings {

	private function __construct() {}

	/**
	 * コード => 判定の印（`EntityType::warning_flags()`）。印の無いコードも、説明があるので載せる。
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function flags(): array {
		return [
			WarningCode::CUSTOMER_ACCOUNT_PROTECTED        => [],
			// 商品・顧客の参照が見つからない。後から取り込めば解決しうる（checksum を保存しない）が、先にインポートしても消えないことが多いので
			// CSV の note は中立の `reference_unresolved`（`WarningCode::indicates_reference_not_found()`。R3-0n）。
			WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED     => [ WarningFlag::UNRESOLVED_REFERENCE, WarningFlag::REFERENCE_UNRESOLVED ],
			// 商品は取り込み済みでバリエーションが合わない。後から解決しうるが、先にインポートしても消えない（note は付けない。R3-0n）。
			WarningCode::ORDER_LINE_VARIATION_UNMATCHED    => [ WarningFlag::UNRESOLVED_REFERENCE ],
			WarningCode::ORDER_CUSTOMER_UNRESOLVED         => [ WarningFlag::UNRESOLVED_REFERENCE, WarningFlag::REFERENCE_UNRESOLVED ],
			// `Woo\Reader\OrderReader::line_items()`: 明細の数量が欠損・非整数・0以下のため
			// `max(1, ...)`で捏造した数量にフェイルクローズ済み（import方向の`OrderTransformer::
			// transform()`は同じ状況を例外で弾く、より厳しい既存方針と対称）。捏造した数量を
			// ColorMeへ恒久的な受注数量として送らない（E2-3 PR-Cレビュー指摘）。
			WarningCode::ORDER_LINE_QUANTITY_INVALID       => [ WarningFlag::EXPORT_BLOCKING ],
			// R3-0m: 決済/配送方法が未マッピングのまま取り込んだ受注。checksum をキャッシュすると、後から
			// マッピングを設定しても checksum 一致で飛ばされ、受注は空の決済/配送方法のまま直らない（再 dry-run も
			// 検証を飛ばして警告だけが消える）。キャッシュせず、次回のインポートで付け直させる。エクスポート方向では
			// 未マッピングの受注は送信されない（`ColorMeAdapter::order_skip_warnings()`）ため、この判定に届かない。
			WarningCode::PAYMENT_METHOD_UNMAPPED           => [ WarningFlag::UNRESOLVED_REFERENCE, WarningFlag::MAPPING_REQUIRED ],
			WarningCode::SHIPPING_METHOD_UNMAPPED          => [ WarningFlag::UNRESOLVED_REFERENCE, WarningFlag::MAPPING_REQUIRED ],
			WarningCode::ORDER_STATUS_UNKNOWN              => [],
			WarningCode::ORDER_TOTAL_RESIDUAL              => [],
			WarningCode::ORDER_SPLIT_TAX_UNKNOWN           => [],
			WarningCode::ORDER_TAX_SPLIT_UNAVAILABLE       => [],
			WarningCode::ORDER_TAX_TOTAL_INCOMPLETE        => [],
			WarningCode::ORDER_LINE_TAX_INCONSISTENT       => [],
			WarningCode::ORDER_CREATE_FAILED               => [],
			// `Woo\Reader\OrderReader`: 注文全体の合計（discount/shipping_fee/tax/total）が
			// 数値として不正（非数値・負値）なため`0`へフェイルクローズ済み。importの
			// `Woo\Writer\OrderWriter::validate_totals()`が同じ状況で注文全体の書込みを見送る
			// （`WC_Order`に一切触れる前に注文自体をskipする）のと対称に、exportも壊れた合計を
			// 実際の明細と一緒に「¥0の注文」としてpushしない。
			WarningCode::ORDER_TOTALS_INVALID              => [ WarningFlag::EXPORT_BLOCKING ],
			// `Woo\Reader\OrderReader::line_item_amounts()`: 明細の小計/税額が数値として不正
			// （非数値・負値）なため`0`へフェイルクローズ済み。`ColorMeAdapter::push_order()`の
			// `sale.details[].price`は明示指定するとColorMeに実際の金額として恒久的に記録される
			// ため、`PRODUCT_PRICE_INVALID`と同じ理由（金銭的リスク。CLAUDE.mdアーキテクチャ
			// 原則9）で無警告のままpushしない（E2-3 PR-Cレビュー指摘）。
			WarningCode::ORDER_LINE_AMOUNT_INVALID         => [ WarningFlag::EXPORT_BLOCKING ],
			// 参照先（商品・顧客）を先にエクスポートすれば解決する（note は `reference_pending_export`）。
			WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED   => [ WarningFlag::UNRESOLVED_REFERENCE, WarningFlag::PENDING_EXPORT ],
			WarningCode::ORDER_CUSTOMER_NOT_EXPORTED       => [ WarningFlag::UNRESOLVED_REFERENCE, WarningFlag::PENDING_EXPORT ],
			// `Woo\Reader\OrderReader`: 受注明細が参照していた商品/バリエーションが削除済みで
			// `remote_product_id`を恒久的に特定できない。対応ASPの受注作成APIは明細ごとの商品参照を
			// 必須とするため、参照を持たない行と区別せずpushしない（詳細は定数のdocblock参照）。
			WarningCode::ORDER_LINE_PRODUCT_DELETED        => [ WarningFlag::EXPORT_BLOCKING ],
			// `Woo\Reader\OrderReader`: 受注明細が一度も商品リンクを持たない（詳細は定数の
			// docblock参照）。
			WarningCode::ORDER_LINE_PRODUCT_MISSING        => [ WarningFlag::EXPORT_BLOCKING ],
			// `Woo\Reader\OrderReader`: バリエーション明細の親商品は解決できたが、バリエーション
			// 自体の識別に失敗した（削除済み、「Any」の軸を持つ〔D23〕、または軸3つ以上でoption1/2だけ
			// では区別不能。詳細は定数のdocblock参照）。
			WarningCode::ORDER_LINE_VARIATION_UNRESOLVED   => [ WarningFlag::EXPORT_BLOCKING ],
			// `Woo\Reader\OrderReader`: 受注が一部/全額返金済み。返金額を運ぶフィールドが無い
			// ため、返金前の金額のまま全額回収済みとしてpushしない（詳細は定数のdocblock参照）。
			WarningCode::ORDER_REFUNDED                    => [ WarningFlag::EXPORT_BLOCKING ],
			// `Woo\Reader\OrderReader`: 明細の`tax_class`が標準/軽減税率以外（非課税・送料のみ
			// 課税・zero-rate・カスタム税区分）。`CanonicalOrder::$line_items[].tax_reduced`は
			// bool（標準/軽減税率の2値）しか運べずこの状態自体を伝えられないため、無警告のまま
			// pushすると`ColorMeAdapter::push_order()`がマップ先商品の現在の税設定（標準/軽減の
			// いずれか）で課税された受注を恒久的に作成してしまう（例: 実際は非課税だった受注が
			// 通常課税として記録される。Codexレビュー指摘、金銭的リスク）。
			WarningCode::ORDER_LINE_TAX_CLASS_UNSUPPORTED  => [ WarningFlag::EXPORT_BLOCKING ],
			WarningCode::ORDER_UPDATE_NOT_SUPPORTED        => [],
			WarningCode::ORDER_SHIPPING_ADDRESS_INCOMPLETE => [],
			WarningCode::ORDER_LINE_ITEMS_EMPTY            => [],
			WarningCode::ORDER_LINE_PRICE_UNRESOLVED       => [],
			WarningCode::ORDER_DISCOUNT_NOT_PUSHED         => [],
			WarningCode::ORDER_FEE_NOT_PUSHED              => [],
			WarningCode::ORDER_PLACED_AT_NOT_PRESERVED     => [],
		];
	}

	/**
	 * 文言の書き方は `Woo\WarningCatalog::entry()` と同じ（detail を差し込む文言は `%` を `%%` と書く）。
	 */
	public static function describe( string $code, bool $import, string $row_entity ): ?WarningText {
		$blocking = WarningCatalog::SEVERITY_BLOCKING;
		$action   = WarningCatalog::SEVERITY_ACTION_REQUIRED;
		$info     = WarningCatalog::SEVERITY_INFO;

		return match ( $code ) {
			// 受注（取込み）。
			// 顧客がスタッフのアカウントと同じメールの受注は、ゲスト受注として書く（`OrderWriter`）。顧客の行は `CustomerWarnings` が説明する。
			WarningCode::CUSTOMER_ACCOUNT_PROTECTED => 'order' === $row_entity
				? self::make(
					$action,
					__( 'The customer’s email address belongs to an administrator or staff account (such as a shop manager), so this order is imported as a guest order instead of being assigned to that account.', 'cart-bridge-jp' ),
					__( 'If the account really is the buyer’s, assign the order to it in WooCommerce by hand after you finish importing (an order that is imported again becomes a guest order again).', 'cart-bridge-jp' )
				)
				: null,
			WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED => self::make(
				$action,
				__( 'The product of an order line is not found in WooCommerce, so the line is added without a link to a product. It is linked when the order is imported again after the product.', 'cart-bridge-jp' ),
				__( 'Import products before orders (a full import does this). If the product was deleted on the platform, the line stays without a product link.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a product. */
				__( 'The product %s of an order line is not found in WooCommerce, so the line is added without a link to a product. It is linked when the order is imported again after the product.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_VARIATION_UNMATCHED => self::make(
				$action,
				__( 'The options of an order line do not identify one variation of the product (the product’s options may have changed after the order), so the line is added without a link to a product.', 'cart-bridge-jp' ),
				__( 'While this warning remains, the order’s lines are recreated each time the order is imported, so a line linked by hand is undone. Link the line to the right variation by hand only after you finish importing.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a product. */
				__( 'The options of an order line do not identify one variation of the product %s (the product’s options may have changed after the order), so the line is added without a link to a product.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_CUSTOMER_UNRESOLVED => self::make(
				$action,
				__( 'The customer of this order is not found in WooCommerce, so the order is imported as a guest order. It is linked when the order is imported again after the customer.', 'cart-bridge-jp' ),
				__( 'Import customers before orders (a full import does this). If the customer was deleted on the platform, the order stays a guest order.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a customer. */
				__( 'The customer %s of this order is not found in WooCommerce, so the order is imported as a guest order. It is linked when the order is imported again after the customer.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_QUANTITY_INVALID => $import
				? self::make(
					$action,
					__( 'The quantity of an order line is missing, not a whole number, or zero or less, so the line is imported with a quantity of 1.', 'cart-bridge-jp' ),
					__( 'Check the order on the platform, and correct the quantity in the WooCommerce order after you finish importing (an order that is imported again is rewritten).', 'cart-bridge-jp' ),
					/* translators: %s: the platform's ID of a product. */
					__( 'The quantity of the order line for the product %s is missing, not a whole number, or zero or less, so the line is imported with a quantity of 1.', 'cart-bridge-jp' )
				)
				: self::make(
					$blocking,
					__( 'The quantity of an order line is not a positive whole number, so the order is not exported.', 'cart-bridge-jp' ),
					__( 'Correct the quantity on the WooCommerce order, or create the order on the platform by hand.', 'cart-bridge-jp' ),
					/* translators: %s: the platform's ID of a product. */
					__( 'The quantity of the order line for the platform’s product %s is not a positive whole number, so the order is not exported.', 'cart-bridge-jp' )
				),
			WarningCode::PAYMENT_METHOD_UNMAPPED => $import
				? self::make(
					$action,
					__( 'The platform’s payment method is not mapped to a WooCommerce payment method, so the order is imported without a payment method.', 'cart-bridge-jp' ),
					__( 'Map the payment method in the Mappings tab. The order is updated the next time it is imported.', 'cart-bridge-jp' ),
					/* translators: %s: the platform's ID of a payment method. */
					__( 'The platform’s payment method %s is not mapped to a WooCommerce payment method, so the order is imported without a payment method.', 'cart-bridge-jp' )
				)
				: self::make(
					$blocking,
					__( 'The order has no payment method, so it is not exported.', 'cart-bridge-jp' ),
					__( 'In the Mappings tab, map exactly one of the platform’s payment methods to the order’s WooCommerce payment method (set a payment method on the order if it has none), then export again.', 'cart-bridge-jp' ),
					/* translators: %s: the ID of a WooCommerce payment method, such as bacs. */
					__( 'The payment method “%s” is not mapped to exactly one of the platform’s payment methods, so the order is not exported.', 'cart-bridge-jp' )
				),
			WarningCode::SHIPPING_METHOD_UNMAPPED => $import
				? self::make(
					$action,
					__( 'The platform’s delivery method is not mapped to a WooCommerce shipping method, so the order is imported without a shipping method.', 'cart-bridge-jp' ),
					__( 'Map the delivery method in the Mappings tab. The order is updated the next time it is imported.', 'cart-bridge-jp' ),
					/* translators: %s: the platform's ID of a delivery method. */
					__( 'The platform’s delivery method %s is not mapped to a WooCommerce shipping method, so the order is imported without a shipping method.', 'cart-bridge-jp' )
				)
				: self::make(
					$blocking,
					__( 'The order has no shipping (for example, it has only virtual products), so it is not exported.', 'cart-bridge-jp' ),
					__( 'In the Mappings tab, map exactly one of the platform’s delivery methods to the order’s WooCommerce shipping method, then export again. An order without shipping cannot be exported; create it on the platform by hand.', 'cart-bridge-jp' ),
					/* translators: %s: the ID of a WooCommerce shipping method, such as flat_rate:3. */
					__( 'The shipping method “%s” is not mapped to exactly one of the platform’s delivery methods, so the order is not exported.', 'cart-bridge-jp' )
				),
			WarningCode::ORDER_STATUS_UNKNOWN => self::make(
				$action,
				__( 'The order status is not registered in WooCommerce, or cannot be used for imported orders (such as “checkout-draft”, which WooCommerce deletes after a day), so the order is imported as “On hold”.', 'cart-bridge-jp' ),
				__( 'In the order status mapping (Mappings tab), choose a regular WooCommerce order status that can be used for imported orders before importing. Correct orders that were already imported by hand after you finish importing (an order that is imported again is rewritten).', 'cart-bridge-jp' ),
				/* translators: %s: an order status. */
				__( 'The order status “%s” is not registered in WooCommerce, or cannot be used for imported orders, so the order is imported as “On hold”.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_TOTAL_RESIDUAL => self::make(
				$info,
				__( 'The order total from the platform does not equal the sum of its lines, shipping, fees and discount. The platform’s total is kept.', 'cart-bridge-jp' ),
				__( 'Compare the order with the platform, and correct it in WooCommerce after you finish importing if needed (an order that is imported again is rewritten).', 'cart-bridge-jp' ),
				/* translators: %s: an amount in yen. */
				__( 'The order total from the platform differs from the sum of its lines, shipping, fees and discount by %s yen. The platform’s total is kept.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_SPLIT_TAX_UNKNOWN => self::make(
				$info,
				__( 'This order is part of a split order, and the platform does not give the tax for the part, so the order’s tax is recorded as 0.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_TAX_SPLIT_UNAVAILABLE => self::make(
				$info,
				__( 'The platform does not give an order line’s price before tax, so the line is recorded at its price including tax with a tax of 0. The order total and tax are not affected.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_TAX_TOTAL_INCOMPLETE => self::make(
				$info,
				__( 'The platform does not give the tax breakdown for this order (older orders), so the order’s tax includes only the tax on products, not on shipping.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_TAX_INCONSISTENT => self::make(
				$info,
				__( 'An order line’s amount including tax is less than its price before tax times the quantity (a discount or rounding on the platform), so the line is recorded at its amount including tax with a tax of 0.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_CREATE_FAILED => self::make(
				$blocking,
				__( 'WooCommerce could not create the order (another plugin may have stopped it while it was being saved), so it is not imported.', 'cart-bridge-jp' ),
				__( 'Check WooCommerce > Status > Logs for the error, fix or turn off the plugin that caused it, then import again.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_TOTALS_INVALID => $import
				? self::make(
					$blocking,
					__( 'An amount of the order (total, discount, shipping fee or tax) from the platform is missing, not a number, or negative, so the order is not imported.', 'cart-bridge-jp' ),
					__( 'Correct the order’s amounts on the platform, then import again.', 'cart-bridge-jp' ),
					/* translators: %s: the name of an amount field, such as shipping_fee. */
					__( 'The order’s amount “%s” from the platform is missing, not a number, or negative, so the order is not imported.', 'cart-bridge-jp' )
				)
				: self::make(
					$blocking,
					__( 'An amount of the order (total, discount, shipping, a fee or tax) is not a number or is negative, so the order is not exported.', 'cart-bridge-jp' ),
					__( 'Correct the amounts on the WooCommerce order (a negative fee is a common cause), or create the order on the platform by hand.', 'cart-bridge-jp' ),
					/* translators: %s: the name of an amount field, such as payment_fee. */
					__( 'The order’s amount “%s” is not a number or is negative, so the order is not exported.', 'cart-bridge-jp' )
				),
			WarningCode::ORDER_LINE_AMOUNT_INVALID => $import
				? self::make(
					$action,
					__( 'An amount of the order (a line’s price, the shipping fee or a fee) from the platform is not a number or is negative, so it is recorded as 0 (for a line’s price before tax, the line’s tax is recorded as 0 instead). The order total from the platform is kept.', 'cart-bridge-jp' ),
					__( 'Compare the order with the platform, and correct the amounts in WooCommerce after you finish importing (an order that is imported again is rewritten).', 'cart-bridge-jp' )
				)
				: self::make(
					$blocking,
					__( 'An order line’s amount or tax is not a number or is negative, so the order is not exported.', 'cart-bridge-jp' ),
					__( 'Recalculate or correct the line on the WooCommerce order, or create the order on the platform by hand.', 'cart-bridge-jp' ),
					/* translators: %s: the platform's ID of a product. */
					__( 'The amount or tax of the order line for the platform’s product %s is not a number or is negative, so the order is not exported.', 'cart-bridge-jp' )
				),

			// 受注（エクスポート）。
			WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED => self::make(
				$action,
				__( 'A product in this order has not been exported to the platform yet. The order can be exported only after the product; a full export sends products before orders.', 'cart-bridge-jp' ),
				__( 'Include products in the export, or export them first. If the product is not exported because of its own warnings, fix those first.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a product. */
				__( 'The product %s in this order has not been exported to the platform yet. The order can be exported only after the product; a full export sends products before orders.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_CUSTOMER_NOT_EXPORTED => self::make(
				$action,
				__( 'The customer of this order has not been exported to the platform yet. If the order is exported before the customer, it is created as a guest order and cannot be linked to the customer later.', 'cart-bridge-jp' ),
				__( 'Include customers in the export (they are sent before orders), or export them first.', 'cart-bridge-jp' ),
				/* translators: %s: the WordPress ID of a user. */
				__( 'The customer (user ID %s) of this order has not been exported to the platform yet. If the order is exported before the customer, it is created as a guest order and cannot be linked to the customer later.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_PRODUCT_DELETED => self::make(
				$blocking,
				__( 'A product in this order has been deleted from WooCommerce, so the order is not exported.', 'cart-bridge-jp' ),
				__( 'Create the order on the platform by hand if you need it.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a deleted product. */
				__( 'The product %s in this order has been deleted from WooCommerce, so the order is not exported.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_PRODUCT_MISSING => self::make(
				$blocking,
				__( 'An order line is not linked to any product, so the order is not exported.', 'cart-bridge-jp' ),
				__( 'Create the order on the platform by hand if you need it.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_VARIATION_UNRESOLVED => self::make(
				$blocking,
				__( 'The variation of an order line cannot be identified (it was deleted, it uses “Any” for an attribute, or its product uses three or more attributes for variations), so the order is not exported.', 'cart-bridge-jp' ),
				__( 'If the product uses three or more attributes for variations, reduce them to two. Otherwise, create the order on the platform by hand.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a variation. */
				__( 'The variation %s of an order line cannot be identified (it was deleted, it uses “Any” for an attribute, or its product uses three or more attributes for variations), so the order is not exported.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_REFUNDED => self::make(
				$blocking,
				__( 'The order has been refunded (fully or partly), and refunds cannot be sent to the platform, so the order is not exported.', 'cart-bridge-jp' ),
				__( 'Create the order on the platform by hand if you need it.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_TAX_CLASS_UNSUPPORTED => self::make(
				$blocking,
				__( 'An order line’s tax class has no Japanese tax rate of 10% (standard) or 8% (reduced), or its product is not taxable, so the order is not exported.', 'cart-bridge-jp' ),
				__( 'If the tax class has no Japanese rate, add one: 10% (standard) or 8% (reduced). If the product’s tax status is not “Taxable”, change it. (The tax settings and the product’s tax fields are shown only when tax calculation is turned on in WooCommerce > Settings > General.)', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a product. Write a literal percent sign as %%. */
				__( 'The tax class of the order line for the platform’s product %s has no Japanese tax rate of 10%% (standard) or 8%% (reduced), or its product is not taxable, so the order is not exported.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_UPDATE_NOT_SUPPORTED => self::make(
				$blocking,
				__( 'The order was already exported, and the platform cannot update an order’s items, payment or delivery, so it is not sent again (that would create a duplicate).', 'cart-bridge-jp' ),
				__( 'If the order changed, update it on the platform by hand.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_SHIPPING_ADDRESS_INCOMPLETE => self::make(
				$blocking,
				__( 'The order’s delivery address is incomplete (a name, phone number, postcode, prefecture and address are required), so the order is not exported. A partly filled shipping address is not completed from the billing address.', 'cart-bridge-jp' ),
				__( 'Complete the shipping address on the WooCommerce order, or clear it so that the billing address is used, then export again.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_ITEMS_EMPTY => self::make(
				$blocking,
				__( 'The order has no product lines, and the platform requires at least one, so the order is not exported.', 'cart-bridge-jp' ),
				__( 'Create the order on the platform by hand if you need it.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_LINE_PRICE_UNRESOLVED => self::make(
				$blocking,
				__( 'The unit price of an order line cannot be worked out (the line total does not divide evenly by the quantity, or the platform’s tax setting is unknown), so the order is not exported.', 'cart-bridge-jp' ),
				__( 'Check the platform’s tax setting. If a line total does not divide evenly by its quantity, create the order on the platform by hand.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_DISCOUNT_NOT_PUSHED => self::make(
				$info,
				__( 'The order has a discount (such as a coupon), but the platform cannot receive discounts. When the order is sent, it is sent at prices before the discount, so its total on the platform is higher than the amount paid.', 'cart-bridge-jp' ),
				__( 'Adjust the order’s amount on the platform by hand if needed.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_FEE_NOT_PUSHED => self::make(
				$info,
				__( 'The order’s shipping and other fees cannot be sent. When the order is sent, the platform applies its own fees for the payment and delivery methods, so the totals can differ.', 'cart-bridge-jp' ),
				__( 'Check the fees on the platform’s order if needed.', 'cart-bridge-jp' )
			),
			WarningCode::ORDER_PLACED_AT_NOT_PRESERVED => self::make(
				$info,
				__( 'The platform cannot receive the order date, so the order date on the platform is the date of the export.', 'cart-bridge-jp' )
			),
			default => null,
		};
	}

	private static function make( string $severity, string $message, string $action = '', string $detail_message = '' ): WarningText {
		return new WarningText( $severity, $message, $action, $detail_message );
	}
}
