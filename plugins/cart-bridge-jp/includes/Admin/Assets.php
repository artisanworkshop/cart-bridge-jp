<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Admin;

use CartBridgeJP\Entities\EntityTypeRegistry;

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

		// wp-scriptsのビルドはJSエントリー「index」に対応するCSSを
		// 「index.css」ではなく「style-index.css」として出力する。
		if ( file_exists( CBJP_PATH . 'build/style-index.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				CBJP_URL . 'build/style-index.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		wp_localize_script(
			self::HANDLE,
			'cbjpAdmin',
			[
				'restUrl'      => esc_url_raw( rest_url() ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				// 日時・金額の書式の言語（`src/i18n.ts` の `displayLocale()`）。JS の `toLocaleString()` はブラウザの言語になり、
				// 翻訳（ユーザーの言語）と食い違うため渡す。BCP 47 に寄せて `_` を `-` にする（不正なタグは JS 側でブラウザの既定へ倒す）。
				'locale'       => str_replace( '_', '-', determine_locale() ),
				// 実体の種類（とリンク再構築の対象）の表示名（R3-6b1。キー => 名前）。Pro アドオンが足す種類も入る。
				'entityLabels' => EntityTypeRegistry::labels(),
			]
		);
	}
}
