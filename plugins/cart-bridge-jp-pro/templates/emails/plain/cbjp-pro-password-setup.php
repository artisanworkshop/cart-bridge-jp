<?php
/**
 * Cart Bridge JP Pro: password setup email after a store migration (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/cbjp-pro-password-setup.php.
 *
 * @package CartBridgeJP\Pro
 *
 * @var string $email_heading      The heading.
 * @var string $additional_content The additional content set in the email settings.
 * @var string $user_display_name  The customer name.
 * @var string $set_password_url   The link that sets the password.
 * @var string $lost_password_url  The page that sends a new link.
 * @var string $link_lifetime      How long the link works, such as "1 day".
 * @var string $store_url          The address of this store.
 */

defined( 'ABSPATH' ) || exit;

echo "=================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=================\n\n";

/* translators: %s: the customer name. */
echo esc_html( sprintf( __( 'Hi %s,', 'cart-bridge-jp-pro' ), $user_display_name ) ) . "\n\n";
// URL は文の外に出して、エスケープせずに書く（`esc_html()` を通すと `&` が `&amp;` になり、プレーンテキストのリンクが壊れる）。
echo esc_html__( 'Our online store has moved to a new website:', 'cart-bridge-jp-pro' ) . "\n";
echo esc_url_raw( $store_url ) . "\n\n";
echo esc_html__( 'Your customer account has been moved to the new store, but your password could not be moved. To sign in, set a new password using the link below.', 'cart-bridge-jp-pro' ) . "\n\n";
echo esc_url_raw( $set_password_url ) . "\n\n";
/* translators: %s: how long the link works, such as "1 day". */
echo esc_html( sprintf( __( 'This link expires in %s.', 'cart-bridge-jp-pro' ), $link_lifetime ) ) . "\n";
echo esc_html__( 'If the link has expired, you can request a new one on this page:', 'cart-bridge-jp-pro' ) . "\n";
echo esc_url_raw( $lost_password_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce のメールのフッターの文。
