<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Admin;

/**
 * 管理画面React アプリのアセット読み込み。
 */
final class Assets {

	private const HANDLE = 'cart-bridge-jp-admin';

	public function enqueue( string $hook_suffix ): void {
		if ( ! str_ends_with( $hook_suffix, '_page_' . Menu::PAGE_SLUG ) ) {
			return;
		}

		$asset_file = CBJP_PATH . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			CBJP_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::HANDLE, 'cart-bridge-jp', CBJP_PATH . 'languages' );
		}

		if ( file_exists( CBJP_PATH . 'build/index.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				CBJP_URL . 'build/index.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		wp_localize_script(
			self::HANDLE,
			'cbjpAdmin',
			[
				'restUrl'   => esc_url_raw( rest_url() ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}
}
