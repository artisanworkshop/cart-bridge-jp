<?php
/**
 * Cart Bridge JP Pro: password setup email after a store migration (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/cbjp-pro-password-setup.php.
 *
 * @package CartBridgeJP\Pro
 *
 * @var string                                         $email_heading      The heading.
 * @var string                                         $additional_content The additional content set in the email settings.
 * @var string                                         $user_display_name  The customer name.
 * @var string                                         $set_password_url   The link that sets the password.
 * @var string                                         $lost_password_url  The page that sends a new link.
 * @var string                                         $link_lifetime      How long the link works, such as "1 day".
 * @var string                                         $store_url          The address of this store.
 * @var \CartBridgeJP\Pro\PasswordEmail\PasswordSetupEmail $email          The email.
 */

defined( 'ABSPATH' ) || exit;

/** This action is documented in woocommerce/templates/emails/customer-new-account.php */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce のメールの見出し。
?>

<?php /* translators: %s: the customer name. */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'cart-bridge-jp-pro' ), esc_html( $user_display_name ) ); ?></p>
<p><?php esc_html_e( 'Our online store has moved to a new website:', 'cart-bridge-jp-pro' ); ?><br /><a href="<?php echo esc_url( $store_url ); ?>"><?php echo esc_html( $store_url ); ?></a></p>
<p><?php esc_html_e( 'Your customer account has been moved to the new store, but your password could not be moved. To sign in, set a new password using the link below.', 'cart-bridge-jp-pro' ); ?></p>
<p><a href="<?php echo esc_url( $set_password_url ); ?>"><?php esc_html_e( 'Set your password', 'cart-bridge-jp-pro' ); ?></a></p>
<?php /* translators: %s: how long the link works, such as "1 day". */ ?>
<p><?php printf( esc_html__( 'This link expires in %s.', 'cart-bridge-jp-pro' ), esc_html( $link_lifetime ) ); ?></p>
<p><?php esc_html_e( 'If the link has expired, you can request a new one on this page:', 'cart-bridge-jp-pro' ); ?><br /><a href="<?php echo esc_url( $lost_password_url ); ?>"><?php echo esc_html( $lost_password_url ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/** This action is documented in woocommerce/templates/emails/customer-new-account.php */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce のメールのフッター。
