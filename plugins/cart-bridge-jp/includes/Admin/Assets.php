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

	/**
	 * ビルドが書く資産の一覧（依存と版）のパス。
	 */
	private string $asset_file;

	/**
	 * @param string|null $asset_file 資産の一覧のパス（既定は `build/index.asset.php`）。CI の PHPUnit はビルドの前に走るので、
	 *                                テストは一時ファイルを渡して `enqueue()` を通す（PR #145 G3）。
	 */
	public function __construct( ?string $asset_file = null ) {
		$this->asset_file = $asset_file ?? CBJP_PATH . 'build/index.asset.php';
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! str_ends_with( $hook_suffix, '_page_' . Menu::PAGE_SLUG ) ) {
			return;
		}

		$asset_file = $this->asset_file;

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

		self::set_script_translations();

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

	/**
	 * 管理画面の JS の訳を登録する。訳は translate.wordpress.org の言語パック
	 * （`WP_LANG_DIR/plugins/cart-bridge-jp-<言語>-<md5( 'build/index.js' )>.json`）から読み、プラグインの中に訳を同梱しないのでパスは渡さない
	 * （issue #144・`docs/03` §6「翻訳（R3-2）」）。`enqueue()` はビルドが無いと早く戻るので、テストから呼べるよう分けた。
	 */
	public static function set_script_translations(): void {
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::HANDLE, 'cart-bridge-jp' );
		}
	}
}
