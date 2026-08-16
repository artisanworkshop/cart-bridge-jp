<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

/**
 * `WriteResult::$warnings` に積む警告コード定数。`"{code}:{detail}"` 形式の文字列にする
 * （F1-6のdry-run CSV・結果レポートが`:`で分解できる契約）。コード自体はi18nしない安定キーで、
 * 表示文言はUI側で `__()` する。
 */
final class WarningCode {

	private function __construct() {}

	public const ENTITY_NOT_SUPPORTED = 'entity_not_supported';

	public const PRICES_INCLUDE_TAX_DISABLED = 'prices_include_tax_disabled';
	public const CURRENCY_MISMATCH           = 'currency_mismatch';

	public const SKU_DUPLICATE            = 'sku_duplicate';
	public const TAX_CLASS_MISSING        = 'tax_class_missing';
	public const TAX_RATES_NOT_CONFIGURED = 'tax_rates_not_configured';
	public const IMAGE_DOWNLOAD_FAILED    = 'image_download_failed';
	public const VARIATION_AXIS_EXCEEDED  = 'variation_axis_exceeded';
	public const ATTRIBUTE_NAME_COLLISION = 'attribute_name_collision';
	public const VARIATION_REMOVED        = 'variation_removed';

	public const CATEGORY_PARENT_UNRESOLVED = 'category_parent_unresolved';
	public const CATEGORY_REF_UNRESOLVED    = 'category_ref_unresolved';
	public const TAG_REF_UNRESOLVED         = 'tag_ref_unresolved';
	public const TERM_REUSED_EXISTING       = 'term_reused_existing';

	public const CUSTOMER_REUSED_EXISTING = 'customer_reused_existing';
	public const ADDRESS_OVERSEAS         = 'address_overseas';

	public const ORDER_LINE_PRODUCT_UNRESOLVED = 'order_line_product_unresolved';
	public const ORDER_CUSTOMER_UNRESOLVED     = 'order_customer_unresolved';
	public const PAYMENT_METHOD_UNMAPPED       = 'payment_method_unmapped';
	public const SHIPPING_METHOD_UNMAPPED      = 'shipping_method_unmapped';
	public const ORDER_STATUS_UNKNOWN          = 'order_status_unknown';
	public const ORDER_TOTAL_RESIDUAL          = 'order_total_residual';
	public const ORDER_SPLIT_TAX_UNKNOWN       = 'order_split_tax_unknown';
	public const ORDER_TAX_SPLIT_UNAVAILABLE   = 'order_tax_split_unavailable';

	public const STOCK_PRODUCT_UNRESOLVED = 'stock_product_unresolved';
	public const STOCK_PARENT_OF_VARIABLE = 'stock_parent_of_variable';

	public const COUPON_REUSED_EXISTING         = 'coupon_reused_existing';
	public const COUPON_GROUP_LIMIT_UNSUPPORTED = 'coupon_group_limit_unsupported';

	/**
	 * `"{code}:{detail}"` 形式の警告文字列を組み立てる。
	 */
	public static function with_detail( string $code, string $detail ): string {
		return '' === $detail ? $code : "{$code}:{$detail}";
	}
}
