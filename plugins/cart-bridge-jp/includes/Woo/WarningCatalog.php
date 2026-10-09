<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use ArgumentCountError;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Entities\WarningText;
use Throwable;
use ValueError;

/**
 * 警告コード（`WarningCode`）の店舗向けの説明: 重大度・原因・対処（R3-0k）。dry-run の CSV（`Admin\DryRunReportCsv`）の
 * `severity`・`message`・`action` 列に使う。
 *
 * 同じコードでも取込みとエクスポートで意味が違う（同じ事実を Reader と Writer の両方が見て、片方は止め、片方は代替値で書く等）ため、
 * 「コード × 向き」で引く。文言はプラットフォーム名を出さない（プラットフォーム非依存。原則 1）。
 *
 * 重大度は翻訳しない安定キー:
 * - `blocking`: この警告のため、その実体を書かない（取込み）・送らない（エクスポート）
 * - `action_required`: 書いた・送ったが、店舗の対処が要る（代替値で書いた、設定すれば直る等）。対処の文言を必ず持つ
 * - `info`: 対処の要らない知らせ（既存の実体を使った、運べない項目がある等）
 * - `unknown`: カタログに無いコード（外部アダプタ独自のコード等）・知らない向き。楽観的に `info` へ倒さない（原則 9）
 *
 * 顧客・受注・クーポンのコードは実体の種類が説明する（`Entities\EntityType::describe_warning()`。R3-6b1 で `Entities\Commerce\*Warnings` へ移し、
 * R3-6c で Pro アドオンへ移す）。行の種別（entity）は、その行の種類の説明を先に引くのに使う。`CUSTOMER_ACCOUNT_PROTECTED` は
 * 顧客の行（プロフィールを書かずに飛ばす。`CustomerWriter`）と受注の行（ゲスト受注として書く。`OrderWriter`）で説明が違い、
 * 種別が分からなければ重いほう（顧客の行）に倒す（原則 9）。
 *
 * `VARIATION_STOCK_MANAGEMENT_MIXED` はプラットフォームの能力（`Capabilities::$supports_per_variant_stock_management`）で
 * 止まるかが決まるが、v1.0 の同梱アダプタ（ColorMe）では止まるので `blocking` にしている（バリエーション単位で在庫管理できる
 * アダプタを足すときは見直す）。
 *
 * エントリは `match` の一致したアームだけを評価する（CSV の数千行で全コードの `__()` を毎回呼ばない）。
 */
final class WarningCatalog {

	public const IMPORT = 'import';
	public const EXPORT = 'export';

	public const SEVERITY_BLOCKING        = 'blocking';
	public const SEVERITY_ACTION_REQUIRED = 'action_required';
	public const SEVERITY_INFO            = 'info';
	public const SEVERITY_UNKNOWN         = 'unknown';

	private function __construct() {}

	/**
	 * `"{code}:{detail}"` 形式の警告を、向き（`IMPORT`/`EXPORT`）に応じて説明する。
	 *
	 * @param string $entity その警告を持つ行の種別（`customer`・`order` 等）。分からなければ空（重いほうの説明になる）。
	 * @return array{severity:string,message:string,action:string}
	 */
	public static function describe( string $warning, string $direction, string $entity = '' ): array {
		[ $code, $detail ] = WarningCode::split( $warning );
		$entry             = in_array( $direction, [ self::IMPORT, self::EXPORT ], true ) ? self::entry( $code, self::IMPORT === $direction, $entity ) : null;

		if ( null === $entry ) {
			/* translators: %s: the warning code. */
			$template = __( 'Unknown warning (%s).', 'cart-bridge-jp' );

			return [
				'severity' => self::SEVERITY_UNKNOWN,
				'message'  => self::format( $template, $code ) ?? $code,
				'action'   => '',
			];
		}

		$message = $entry['message'];

		if ( null !== $detail && '' !== $detail && '' !== $entry['detail_message'] ) {
			$message = self::format( $entry['detail_message'], $detail ) ?? $message;
		}

		return [
			'severity' => $entry['severity'],
			'message'  => $message,
			'action'   => $entry['action'],
		];
	}

	/**
	 * 無料版のカタログが説明するコードか（どちらかの向きで）。登録された種類はこのコードの判定の印を変えられない
	 * （`Entities\EntityTypeRegistry::warning_flags()`）。
	 */
	public static function is_core_code( string $code ): bool {
		return null !== self::core_entry( $code, true ) || null !== self::core_entry( $code, false );
	}

	/**
	 * 翻訳された書式へ値を 1 つ差し込む。翻訳は外部の入力で、余分なプレースホルダ（`%2$s` 等）を持つと `sprintf()` が例外を
	 * 投げるため、CSV の書き出しを止めずに null を返す（呼び出し側は差し込まない文言へ倒す）。値の `%` は書式として解釈されない。
	 */
	private static function format( string $template, string $value ): ?string {
		try {
			return sprintf( $template, $value );
		} catch ( ValueError | ArgumentCountError ) {
			return null;
		}
	}

	/**
	 * @return array{severity:string,message:string,action:string,detail_message:string}
	 */
	private static function make( string $severity, string $message, string $action = '', string $detail_message = '' ): array {
		return [
			'severity'       => $severity,
			'message'        => $message,
			'action'         => $action,
			'detail_message' => $detail_message,
		];
	}

	/**
	 * 説明を引く順: 行の種類（`$entity`）が登録した種類の説明 → 無料版のカタログ → ほかの登録された種類（実行順）。
	 * 顧客・受注・クーポンのコードは種類が説明する（`Entities\EntityType::describe_warning()`。R3-6b1）。
	 *
	 * @return array{severity:string,message:string,action:string,detail_message:string}|null
	 */
	private static function entry( string $code, bool $import, string $entity = '' ): ?array {
		$own  = '' !== $entity ? EntityTypeRegistry::get( $entity ) : null;
		$text = null !== $own ? self::registered( $own, $code, $import, $entity ) : null;

		if ( null !== $text ) {
			return $text->to_array();
		}

		$core = self::core_entry( $code, $import );

		if ( null !== $core ) {
			return $core;
		}

		foreach ( EntityTypeRegistry::all() as $type ) {
			if ( $type === $own ) {
				continue;
			}

			$text = self::registered( $type, $code, $import, $entity );

			if ( null !== $text ) {
				return $text->to_array();
			}
		}

		return null;
	}

	/**
	 * 外部の種類の説明は信用しない（原則 8）。例外を投げる・形が違う説明は「説明が無い」に倒す（CSV を途中で切らない）。
	 */
	private static function registered( EntityType $type, string $code, bool $import, string $entity ): ?WarningText {
		try {
			$text = $type->describe_warning( $code, $import, $entity );
		} catch ( Throwable ) {
			return null;
		}

		return $text instanceof WarningText ? $text : null;
	}

	/**
	 * 無料版のコードの説明。
	 *
	 * @return array{severity:string,message:string,action:string,detail_message:string}|null
	 */
	private static function core_entry( string $code, bool $import ): ?array {
		$blocking = self::SEVERITY_BLOCKING;
		$action   = self::SEVERITY_ACTION_REQUIRED;
		$info     = self::SEVERITY_INFO;

		// 文言の `%` の扱い: detail を差し込む文言（第 4 引数）は `sprintf()` に通すので `%%` と書く（`%)` のままだと `ValueError` になり、
		// detail の無い文言に倒れる）。それ以外は `sprintf()` に通さないので `%` のまま書く。ただし `%` の直後に空白と英字を続けない
		// （`8% for` の `% f` を make-pot が書式〈`php-format`〉と読み、翻訳にも同じ並びを求める。R3-2）。
		return match ( $code ) {
			// 共通。
			WarningCode::ENTITY_NOT_SUPPORTED => self::make(
				$blocking,
				__( 'This kind of data is not supported, so it is skipped.', 'cart-bridge-jp' )
			),
			WarningCode::VALIDATION_EXCEPTION => self::make(
				$blocking,
				__( 'An unexpected error occurred while processing this item, so it is skipped. The error is recorded in the Logs tab.', 'cart-bridge-jp' ),
				__( 'Check the error in the Logs tab and fix its cause (usually the item’s data or another plugin), then run again.', 'cart-bridge-jp' )
			),
			WarningCode::LINKED_BY_IMPORT_NOT_EXPORTED => self::make(
				$info,
				__( 'This item was imported from the platform, so it is not exported back to it.', 'cart-bridge-jp' )
			),
			WarningCode::LINKED_BY_EXPORT_NOT_IMPORTED => self::make(
				$info,
				__( 'This item was created in WooCommerce and exported to the platform, so importing does not overwrite it.', 'cart-bridge-jp' )
			),

			// 価格・税。
			WarningCode::PRICES_INCLUDE_TAX_DISABLED => self::make(
				$action,
				__( 'WooCommerce does not treat entered prices as including tax, while the platform’s prices include tax and are imported as they are. This is a store-wide setting; only one product per batch is flagged.', 'cart-bridge-jp' ),
				__( 'If WooCommerce calculates tax, set “Prices entered with tax” to “Yes, I will enter prices inclusive of tax” in WooCommerce > Settings > Tax. Otherwise tax is added on top at checkout. If tax calculation is turned off, no change is needed.', 'cart-bridge-jp' )
			),
			WarningCode::CURRENCY_MISMATCH => $import
				? self::make(
					$action,
					__( 'The store currency is not Japanese yen. The order is imported in the store currency with the platform’s yen amounts unchanged (for example, ¥1,000 becomes 1,000 in the store currency).', 'cart-bridge-jp' ),
					__( 'Set the store currency to Japanese yen in WooCommerce > Settings > General before importing orders. Orders that were already imported keep their currency.', 'cart-bridge-jp' )
				)
				: self::make(
					$blocking,
					__( 'The currency is not Japanese yen, so the item is not exported (the platform would treat the amounts as yen).', 'cart-bridge-jp' ),
					'',
					/* translators: %s: a currency code, such as USD. */
					__( 'The currency %s is not Japanese yen, so the item is not exported (the platform would treat the amounts as yen).', 'cart-bridge-jp' )
				),
			WarningCode::PRICES_CONVERTED_TO_TAX_INCLUSIVE => self::make(
				$info,
				__( 'Prices in WooCommerce are entered without tax, so they are exported with tax added at the rate for the store’s address. Only the first product in each batch is flagged; the others are converted the same way.', 'cart-bridge-jp' )
			),
			WarningCode::SALE_END_DATE_NOT_PUSHED => self::make(
				$action,
				__( 'The sale price is exported, but the sale’s end date cannot be sent, so the platform keeps the sale price after the sale ends in WooCommerce.', 'cart-bridge-jp' ),
				__( 'After the sale ends, export the product again or change the price on the platform.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a variation. */
				__( 'The sale price of variation %s is exported, but the sale’s end date cannot be sent, so the platform keeps the sale price after the sale ends in WooCommerce.', 'cart-bridge-jp' )
			),
			WarningCode::PRICE_TAX_BASIS_UNRESOLVED => self::make(
				$blocking,
				__( 'The price cannot be converted to a price including tax: the tax class has tax rates, but none of them applies to the store’s address. The product is not exported.', 'cart-bridge-jp' ),
				__( 'Add a tax rate that applies to the store’s address (or to all locations) to the tax class in WooCommerce > Settings > Tax, or correct the store address.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a variation. */
				__( 'The price of variation %s cannot be converted to a price including tax: its tax class has tax rates, but none of them applies to the store’s address. The product is not exported.', 'cart-bridge-jp' )
			),
			WarningCode::TAX_CLASS_MISSING => self::make(
				$action,
				__( 'The tax class given by the platform does not exist in WooCommerce, so the item uses the standard tax class.', 'cart-bridge-jp' ),
				__( 'Create the tax class in WooCommerce > Settings > Tax and set it on the item. If tax calculation is turned off in WooCommerce > Settings > General, no change is needed.', 'cart-bridge-jp' ),
				/* translators: %s: the slug of a tax class. */
				__( 'The tax class “%s” given by the platform does not exist in WooCommerce, so the item uses the standard tax class.', 'cart-bridge-jp' )
			),
			WarningCode::TAX_RATES_NOT_CONFIGURED => self::make(
				$action,
				__( 'The item is put in a tax class that has no tax rates (for a reduced-rate item, the default reduced rate class), so WooCommerce charges no tax on it.', 'cart-bridge-jp' ),
				__( 'If WooCommerce calculates tax, add the correct Japanese tax rate to that tax class in WooCommerce > Settings > Tax (for the reduced rate class, 8%); you do not need to import again. If tax calculation is turned off in WooCommerce > Settings > General, no change is needed.', 'cart-bridge-jp' )
			),
			WarningCode::REDUCED_TAX_CLASS_NOT_FOUND => self::make(
				$action,
				__( 'WooCommerce has no tax class with a Japanese rate (8%) for reduced-rate items, so the item uses the standard tax class.', 'cart-bridge-jp' ),
				__( 'In WooCommerce > Settings > Tax (shown when tax calculation is turned on in WooCommerce > Settings > General), give a tax class for the reduced rate (such as “Reduced rate”) a Japanese rate of 8%. Then import again: products are corrected, but orders that were already imported keep the standard tax class.', 'cart-bridge-jp' )
			),
			WarningCode::TAX_CLASS_UNSUPPORTED => self::make(
				$blocking,
				__( 'The product’s tax class has no Japanese tax rate of 10% (standard) or 8% (reduced), so the product is not exported (it would be sold with the wrong tax).', 'cart-bridge-jp' ),
				__( 'Change the product’s tax class to the standard or reduced rate, or give the tax class a Japanese tax rate: 10% (standard) or 8% (reduced). (The tax settings and the product’s tax fields are shown only when tax calculation is turned on in WooCommerce > Settings > General.)', 'cart-bridge-jp' ),
				/* translators: %s: the name of a tax class. Write a literal percent sign as %%. */
				__( 'The tax class “%s” has no Japanese tax rate of 10%% (standard) or 8%% (reduced), so the product is not exported (it would be sold with the wrong tax).', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_TAX_CLASS_UNSUPPORTED => self::make(
				$blocking,
				__( 'The tax class of a published variation has no Japanese tax rate of 10% (standard) or 8% (reduced), so the product is not exported.', 'cart-bridge-jp' ),
				__( 'Change the variation’s tax class (or the product’s, if the variation uses the same as its parent) to the standard or reduced rate, or give the tax class a Japanese tax rate: 10% (standard) or 8% (reduced). (The tax settings and the product’s tax fields are shown only when tax calculation is turned on in WooCommerce > Settings > General.)', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a variation. Write a literal percent sign as %%. */
				__( 'The tax class of variation %s has no Japanese tax rate of 10%% (standard) or 8%% (reduced), so the product is not exported.', 'cart-bridge-jp' )
			),
			WarningCode::TAX_STATUS_NOT_TAXABLE => self::make(
				$blocking,
				__( 'The product’s tax status is “Shipping only” or “None”, which the platform cannot represent, so the product is not exported.', 'cart-bridge-jp' ),
				__( 'If the product is taxed, set its tax status to “Taxable”. Otherwise, add the product on the platform by hand. (The tax settings and the product’s tax fields are shown only when tax calculation is turned on in WooCommerce > Settings > General.)', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_PRICE_NOT_CONVERTIBLE => self::make(
				$blocking,
				__( 'The product’s prices cannot be converted to the platform’s prices because the platform’s tax settings cannot be used, so the product is not exported.', 'cart-bridge-jp' ),
				__( 'Check the tax settings on the platform (prices including or excluding tax, the standard and reduced rates, and rounding), then export again.', 'cart-bridge-jp' )
			),

			// 商品。
			WarningCode::SKU_DUPLICATE => self::make(
				$action,
				__( 'The SKU is already used by another product in WooCommerce, so the item is imported without a SKU.', 'cart-bridge-jp' ),
				__( 'Make the SKU unique (change it on the platform, or change or delete the WooCommerce product that uses it), then enter the SKU on the imported item.', 'cart-bridge-jp' ),
				/* translators: %s: a SKU. */
				__( 'The SKU “%s” is already used by another product in WooCommerce, so the item is imported without a SKU.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_PRICE_INVALID => $import
				? self::make(
					$action,
					__( 'The product’s price from the platform is not a number or is negative, so the price is not set. A new product cannot be bought until it has a price; an existing product keeps its previous price.', 'cart-bridge-jp' ),
					__( 'Correct the price on the platform and import again, or set the price in WooCommerce.', 'cart-bridge-jp' ),
					/* translators: %s: the price value received from the platform. */
					__( 'The product’s price from the platform (%s) is not a number or is negative, so the price is not set. A new product cannot be bought until it has a price; an existing product keeps its previous price.', 'cart-bridge-jp' )
				)
				: self::make(
					$blocking,
					__( 'The product has no valid price, or its price cannot be converted to a price including tax, so it is not exported. A simple product needs a regular price; a variable product needs at least one enabled variation with a price that is shown in the store.', 'cart-bridge-jp' ),
					__( 'Set a regular price. For a variable product, enable a variation with a price; if “Hide out of stock items” is on, at least one variation must be in stock. If the product also has a warning that its price cannot be converted to a price including tax, fix the tax rate as that warning describes.', 'cart-bridge-jp' )
				),
			WarningCode::SALE_PRICE_INVALID => self::make(
				$action,
				__( 'The sale price from the platform is not valid (it must be more than 0 and lower than the regular price), so the product is imported without a sale.', 'cart-bridge-jp' ),
				__( 'Correct the sale price on the platform, then import again.', 'cart-bridge-jp' ),
				/* translators: %s: the sale price value received from the platform. */
				__( 'The sale price from the platform (%s) is not valid (it must be more than 0 and lower than the regular price), so the product is imported without a sale.', 'cart-bridge-jp' )
			),
			WarningCode::IMAGE_DOWNLOAD_FAILED => self::make(
				$action,
				__( 'An image could not be downloaded from the platform, so it is not added. The item’s other images are added.', 'cart-bridge-jp' ),
				__( 'Check that the image can be opened from your server, then add it to the item in WooCommerce.', 'cart-bridge-jp' ),
				/* translators: %s: the URL of an image. */
				__( 'The image %s could not be downloaded from the platform, so it is not added. The item’s other images are added.', 'cart-bridge-jp' )
			),
			WarningCode::ATTRIBUTE_NAME_COLLISION => self::make(
				$action,
				__( 'An attribute has the same name as one of the product’s variation options, so the attribute is not imported.', 'cart-bridge-jp' ),
				__( 'Rename the attribute on the platform, then import again.', 'cart-bridge-jp' ),
				/* translators: %s: the name of an attribute. */
				__( 'The attribute “%s” has the same name as one of the product’s variation options, so it is not imported.', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_REMOVED => self::make(
				$info,
				__( 'A variation that was imported earlier no longer exists on the platform, so it is deleted from WooCommerce.', 'cart-bridge-jp' ),
				'',
				/* translators: %s: the platform's ID of a variation. */
				__( 'The variation %s no longer exists on the platform, so it is deleted from WooCommerce.', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_PRICE_INVALID => $import
				? self::make(
					$action,
					__( 'A variation’s price from the platform is not a number or is negative, so the variation is not created or updated.', 'cart-bridge-jp' ),
					__( 'Correct the variation’s price on the platform, then import again.', 'cart-bridge-jp' ),
					/* translators: %s: the platform's ID of a variation. */
					__( 'The price of variation %s from the platform is not a number or is negative, so the variation is not created or updated.', 'cart-bridge-jp' )
				)
				: self::make(
					$action,
					__( 'A variation has no valid regular price, or its price cannot be converted to a price including tax, so it is left out of the export.', 'cart-bridge-jp' ),
					__( 'Set a regular price on the variation, then export again. If its price cannot be converted to a price including tax, the whole product is not exported; fix the tax rate as that warning describes. A variation that was exported before stays on the platform; hide it there if needed.', 'cart-bridge-jp' ),
					/* translators: %s: the WooCommerce ID of a variation. */
					__( 'Variation %s has no valid regular price, or its price cannot be converted to a price including tax, so it is left out of the export.', 'cart-bridge-jp' )
				),
			WarningCode::VARIATION_SNAPSHOT_INCOMPLETE => self::make(
				$info,
				__( 'Some variations from the platform could not be read, so variations that no longer exist on the platform are not deleted for this product.', 'cart-bridge-jp' ),
				__( 'Correct the variations on the platform (usually their prices), then import again.', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_SAVE_FAILED => self::make(
				$blocking,
				__( 'WooCommerce could not save a variation, so it is not created. The product and its other variations are imported.', 'cart-bridge-jp' ),
				__( 'Check the PHP error log for the cause.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a variation. */
				__( 'WooCommerce could not save the variation %s, so it is not created. The product and its other variations are imported.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_SAVE_FAILED => self::make(
				$blocking,
				__( 'WooCommerce could not save the product, so it is not imported.', 'cart-bridge-jp' ),
				__( 'Check the PHP error log for the cause, then import again.', 'cart-bridge-jp' )
			),
			WarningCode::CATEGORY_MAP_UNRESOLVED => self::make(
				$action,
				__( 'A category of the product is not mapped to a category on the platform, so the product is exported without it.', 'cart-bridge-jp' ),
				__( 'Map the category in the Mappings tab, then export again.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a product category. */
				__( 'The category (ID %s) is not mapped to a category on the platform, so the product is exported without it.', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_UNPUBLISHED => self::make(
				$action,
				__( 'A variation is not enabled, so it is left out of the export.', 'cart-bridge-jp' ),
				__( 'If the variation should be sold, enable it and export again. A variation that was exported before stays on the platform; hide it there if needed.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a variation. */
				__( 'Variation %s is not enabled, so it is left out of the export.', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_AXIS_LIMIT_EXCEEDED => self::make(
				$blocking,
				__( 'The product uses three or more attributes for variations, but the platform supports at most two, so the product is not exported.', 'cart-bridge-jp' ),
				__( 'Use at most two attributes for variations (combine attributes, or turn off “Used for variations” on the others).', 'cart-bridge-jp' )
			),
			WarningCode::ALL_VARIATIONS_EXCLUDED => self::make(
				$blocking,
				__( 'None of the product’s variations can be exported (they are not enabled or have no valid price, or the product has no variations), so the product is not exported.', 'cart-bridge-jp' ),
				__( 'Enable at least one variation with a valid price. The other warnings for this product show why each variation was left out.', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_ANY_ATTRIBUTE_UNSUPPORTED => self::make(
				$blocking,
				__( 'A variation uses “Any” for an attribute, which the platform cannot represent, so the product is not exported.', 'cart-bridge-jp' ),
				__( 'Replace the “Any” variation with one variation for each value of the attribute.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a variation. */
				__( 'Variation %s uses “Any” for an attribute, which the platform cannot represent, so the product is not exported.', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED => self::make(
				$blocking,
				__( 'The variations’ stock settings are mixed: some manage stock and others do not, or variations that do not manage stock differ in stock status (in stock and out of stock). A platform that manages stock per product cannot represent this, so the product and its stock are not exported.', 'cart-bridge-jp' ),
				__( 'Turn on stock management for all variations, or turn it off for all variations and give them all the same stock status (all in stock or all out of stock).', 'cart-bridge-jp' )
			),
			WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT => self::make(
				$action,
				__( 'A variation’s stock is managed by its parent product, and the platform cannot share stock between variations, so the variation is exported as out of stock.', 'cart-bridge-jp' ),
				__( 'Turn on “Manage stock” for each variation and give it its own quantity.', 'cart-bridge-jp' )
			),

			// カテゴリー・タグ。
			WarningCode::CATEGORY_PARENT_UNRESOLVED => self::make(
				$action,
				__( 'The parent category is not found in WooCommerce, so the category is placed at the top level for now. It is moved under its parent when it is imported again after the parent.', 'cart-bridge-jp' ),
				__( 'Import categories; parent categories are imported first. If this remains after an import, check why the parent category was not imported.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a category. */
				__( 'The parent category %s is not found in WooCommerce, so the category is placed at the top level for now. It is moved under its parent when it is imported again after the parent.', 'cart-bridge-jp' )
			),
			WarningCode::CATEGORY_REF_UNRESOLVED => self::make(
				$action,
				__( 'A category of the product is not found in WooCommerce, so the product is imported without it.', 'cart-bridge-jp' ),
				__( 'Import categories before products (a full import does this). If this remains, the category is not imported, for example because it is hidden on the platform or its name conflicts with an existing category.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a category. */
				__( 'The product’s category %s is not found in WooCommerce, so the product is imported without it.', 'cart-bridge-jp' )
			),
			WarningCode::TAG_REF_UNRESOLVED => self::make(
				$action,
				__( 'A tag (group) of the product is not found in WooCommerce, so the product is imported without it.', 'cart-bridge-jp' ),
				__( 'Import tags before products (a full import does this). If this remains, the tag is not imported, for example because it is not public on the platform or its name conflicts with an existing tag.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a tag (group). */
				__( 'The product’s tag (group) %s is not found in WooCommerce, so the product is imported without it.', 'cart-bridge-jp' )
			),
			WarningCode::TERM_REUSED_EXISTING => self::make(
				$info,
				__( 'A category or tag with the same name under the same parent was imported from this platform before, so it is reused.', 'cart-bridge-jp' ),
				__( 'If the platform has two categories with the same name that should stay separate, rename one of them on the platform.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a category or tag. */
				__( 'A category or tag with the same name (ID %s) under the same parent was imported from this platform before, so it is reused.', 'cart-bridge-jp' )
			),
			WarningCode::TERM_NAME_CONFLICT => self::make(
				$blocking,
				__( 'A category or tag with the same name under the same parent already exists in WooCommerce and was not imported from this platform, so it is not imported. Products in it are imported without it.', 'cart-bridge-jp' ),
				__( 'Rename or delete the existing WooCommerce category or tag, or rename it on the platform, then import again.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a category or tag. */
				__( 'A category or tag with the same name (ID %s) already exists under the same parent in WooCommerce and was not imported from this platform, so it is not imported. Products in it are imported without it.', 'cart-bridge-jp' )
			),
			WarningCode::TERM_UPDATE_FAILED => self::make(
				$action,
				__( 'WordPress could not update the category or tag, so its name, description or parent may be out of date.', 'cart-bridge-jp' ),
				__( 'Check that it has a name on the platform and that its parent category exists, then import again.', 'cart-bridge-jp' ),
				/* translators: %s: a WordPress error code. */
				__( 'WordPress could not update the category or tag (%s), so its name, description or parent may be out of date.', 'cart-bridge-jp' )
			),
			WarningCode::TERM_CREATE_FAILED => self::make(
				$blocking,
				__( 'WordPress could not create the category or tag, so it is not imported. Products in it are imported without it.', 'cart-bridge-jp' ),
				__( 'Check that it has a name on the platform, then import again.', 'cart-bridge-jp' ),
				/* translators: %s: a WordPress error code. */
				__( 'WordPress could not create the category or tag (%s), so it is not imported. Products in it are imported without it.', 'cart-bridge-jp' )
			),

			// 在庫。
			WarningCode::STOCK_PRODUCT_UNRESOLVED => self::make(
				$blocking,
				__( 'The product or variation for this stock is not found in WooCommerce, so the stock is not imported.', 'cart-bridge-jp' ),
				__( 'Import products before stock (a full import does this). If this remains, the product was not imported; check the warnings for that product in the preview (dry-run) report.', 'cart-bridge-jp' ),
				/* translators: %s: the platform's ID of a product or variation. */
				__( 'The product or variation %s for this stock is not found in WooCommerce, so the stock is not imported.', 'cart-bridge-jp' )
			),
			WarningCode::STOCK_PARENT_OF_VARIABLE => self::make(
				$blocking,
				__( 'The stock is for a product that has variations in WooCommerce, and stock is kept on each variation, so it is not imported.', 'cart-bridge-jp' ),
				__( 'If the product no longer has variations on the platform, import products again so that the WooCommerce product is updated, then import stock.', 'cart-bridge-jp' )
			),
			WarningCode::STOCK_PRODUCT_NOT_EXPORTED => self::make(
				$blocking,
				__( 'The product or variation for this stock has not been exported to the platform yet, so the stock is not exported. A full export sends products before stock.', 'cart-bridge-jp' ),
				__( 'Include products in the export. If the product or this variation is not exported because of other warnings, fix those first.', 'cart-bridge-jp' ),
				/* translators: %s: the WooCommerce ID of a product or variation. */
				__( 'The product or variation %s for this stock has not been exported to the platform yet, so the stock is not exported. A full export sends products before stock.', 'cart-bridge-jp' )
			),
			WarningCode::STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE => self::make(
				$info,
				__( 'The variation does not manage stock, and the platform cannot mark a single variation as not managed, so its stock is not sent. The product itself is exported as not managing stock.', 'cart-bridge-jp' ),
				__( 'To send stock for each variation, turn on stock management for all published variations of the product.', 'cart-bridge-jp' )
			),

			// 送信（エクスポートの本実行）。
			WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE => self::make(
				$action,
				__( 'The product was created on the platform, but sending its remaining details (such as the subcategory and stock) failed for now. They are sent on the next export.', 'cart-bridge-jp' ),
				__( 'Run the export again.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_DETAILS_PUSH_FAILED => self::make(
				$action,
				__( 'The product was created on the platform, but the platform rejected its remaining details (such as the subcategory and stock), so they may be missing.', 'cart-bridge-jp' ),
				__( 'Check the product’s category and stock on the platform and correct them by hand.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE => self::make(
				$action,
				__( 'The product was sent, but setting up some of its variations failed for now. They are sent on the next export.', 'cart-bridge-jp' ),
				__( 'Run the export again.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_VARIANT_PUSH_FAILED => self::make(
				$action,
				__( 'The product was sent, but some of its variations could not be set up on the platform (for example, two attributes have the same name, a variation has no value for an attribute, or the platform rejected the change).', 'cart-bridge-jp' ),
				__( 'Give the attributes used for variations different names and set a value for every attribute on each variation. Then change the product so that it is exported again, and check its variations on the platform.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_IMAGE_PUSH_INCOMPLETE => self::make(
				$action,
				__( 'The product was sent, but uploading some of its images failed (a network error, or an error on your site or the platform). They are tried again on the next export.', 'cart-bridge-jp' ),
				__( 'Run the export again. If this keeps happening, make sure your site can download its own media files (a firewall, a blocked request to the site itself, or an untrusted SSL certificate can stop this).', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_IMAGE_PUSH_FAILED => self::make(
				$action,
				__( 'The product was sent, but some of its images were rejected (for example, more than 50 images, a missing file, a file your site does not let the plugin download, or an unsupported format).', 'cart-bridge-jp' ),
				__( 'Keep 50 or fewer images per product, fix or replace missing or unsupported images, and make sure your site can download its own media files (basic authentication or a firewall can block this). Add the remaining images on the platform by hand.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_IMAGES_NOT_PUSHED => self::make(
				$info,
				__( 'The product was sent without its images (image upload is turned off, or the platform’s plan does not allow it).', 'cart-bridge-jp' ),
				__( 'If the platform’s plan allows image upload, turn on “Upload product images (Beta)” in the Export tab and export again. Otherwise, add the images on the platform.', 'cart-bridge-jp' )
			),
			WarningCode::PRODUCT_VARIANT_SURPLUS_ON_REMOTE => self::make(
				$action,
				__( 'The platform creates every combination of the product’s options, so it now has variations that do not exist in WooCommerce. They are not deleted and can be bought if stock is not managed.', 'cart-bridge-jp' ),
				__( 'Review the product’s variations on the platform and hide or delete the extra combinations.', 'cart-bridge-jp' )
			),
			WarningCode::PUSH_INTERRUPTED_AFTER_CREATE => self::make(
				$action,
				__( 'The item was created on the platform, but the export stopped before it was finished (for example, at the platform’s request limit). It is completed on the next export.', 'cart-bridge-jp' ),
				__( 'Run the export again.', 'cart-bridge-jp' )
			),
			WarningCode::PUSH_OUTCOME_UNCONFIRMED => self::make(
				$blocking,
				__( 'An earlier export of this item ended without confirming whether it was created on the platform, so it is not sent again until you check.', 'cart-bridge-jp' ),
				__( 'In the Export tab, check whether the item exists on the platform, then use “Link and resolve” or “Mark as not created”.', 'cart-bridge-jp' )
			),

			default => null,
		};
	}
}
