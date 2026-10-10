<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Entities\WarningFlag;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Woo\CommerceWarningCode;
use CartBridgeJP\Woo\WarningCatalog;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- 向き・行の種類を使わない説明もある（`describe()` のシグネチャは共通）。

/**
 * 顧客の警告コードの判定の印と店舗向けの説明（`CustomerType`。R3-6b1 で `Woo\WarningCode`・`Woo\WarningCatalog` から移した）。
 * **R3-6c で Pro アドオンへ移す**（`Entities/Commerce/` ごと）。文言を変えたら dry-run の CSV の説明も変わる（`WarningCatalogTest`）。
 */
final class CustomerWarnings {

	private function __construct() {}

	/**
	 * コード => 判定の印（`EntityType::warning_flags()`）。印の無いコードも、説明があるので載せる。
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function flags(): array {
		return [
			CommerceWarningCode::CUSTOMER_REUSED_EXISTING => [],
			CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED => [],
			CommerceWarningCode::CUSTOMER_EMAIL_CONFLICT  => [],
			CommerceWarningCode::CUSTOMER_CREATE_FAILED   => [],
			CommerceWarningCode::ADDRESS_OVERSEAS         => [],
			CommerceWarningCode::CUSTOMER_REQUIRED_FIELD_MISSING => [],
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
			CommerceWarningCode::CUSTOMER_REUSED_EXISTING => self::make(
				$info,
				__( 'A WordPress user with the same email address already exists, so that account is linked and updated with the platform’s details instead of creating a new one.', 'cart-bridge-jp' ),
				__( 'Check that the existing account belongs to the same person.', 'cart-bridge-jp' ),
				/* translators: %s: the WordPress ID of a user. */
				__( 'A WordPress user with the same email address already exists (user ID %s), so that account is linked and updated with the platform’s details instead of creating a new one.', 'cart-bridge-jp' )
			),
			// 受注の行（`order`）は `OrderWarnings` が説明する（ゲスト受注として書く）。それ以外の行は顧客の行として扱う（重いほうに倒す）。
			CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED => 'order' === $row_entity
				? null
				: self::make(
					$blocking,
					__( 'The customer’s email address belongs to an administrator or staff account (such as a shop manager), so the customer’s details are not imported and that account is not changed. Orders from this customer are imported as guest orders.', 'cart-bridge-jp' ),
					__( 'If the account really is the buyer’s, assign the orders to it in WooCommerce by hand after you finish importing (an order that is imported again becomes a guest order again).', 'cart-bridge-jp' )
				),
			CommerceWarningCode::CUSTOMER_EMAIL_CONFLICT => self::make(
				$blocking,
				__( 'The customer’s email address on the platform is already used by another WordPress user, so the customer is not updated.', 'cart-bridge-jp' ),
				__( 'Change or remove the email address on the other WordPress user, or correct it on the platform, then import again.', 'cart-bridge-jp' )
			),
			CommerceWarningCode::CUSTOMER_CREATE_FAILED => self::make(
				$blocking,
				__( 'WooCommerce could not create the customer account (for example, the email address is not valid, or another plugin blocked the registration), so the customer is not imported.', 'cart-bridge-jp' ),
				__( 'Check the customer’s email address on the platform. If a plugin blocks registrations (such as CAPTCHA or anti-spam), turn it off during the import. Then import again.', 'cart-bridge-jp' ),
				/* translators: %s: a WordPress error code. */
				__( 'WooCommerce could not create the customer account (%s), so the customer is not imported.', 'cart-bridge-jp' )
			),
			CommerceWarningCode::ADDRESS_OVERSEAS => self::make(
				$action,
				__( 'The customer’s address is outside Japan, and the platform does not say which country, so the country and state are left empty.', 'cart-bridge-jp' ),
				__( 'Set the country (and state) of the customer’s billing and shipping addresses in WooCommerce.', 'cart-bridge-jp' )
			),
			CommerceWarningCode::CUSTOMER_REQUIRED_FIELD_MISSING => self::make(
				$blocking,
				__( 'The customer is missing details the platform requires, so the customer is not exported: a name of 50 characters or fewer, and a billing postcode, prefecture and address (and, for a new customer, a phone number using only digits and hyphens).', 'cart-bridge-jp' ),
				__( 'Fill in the customer’s name, billing address and phone number in WooCommerce, then export again.', 'cart-bridge-jp' )
			),
			default => null,
		};
	}

	private static function make( string $severity, string $message, string $action = '', string $detail_message = '' ): WarningText {
		return new WarningText( $severity, $message, $action, $detail_message );
	}
}
