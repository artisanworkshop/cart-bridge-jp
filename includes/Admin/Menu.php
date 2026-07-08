<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Admin;

/**
 * WooCommerceメニュー配下に管理画面ページを登録する。
 */
final class Menu {

	public const PAGE_SLUG = 'cart-bridge-jp';

	public function register(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Cart Bridge JP', 'cart-bridge-jp' ),
			__( 'Cart Bridge JP', 'cart-bridge-jp' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		echo '<div id="cbjp-admin-app" class="wrap"></div>';
	}
}
