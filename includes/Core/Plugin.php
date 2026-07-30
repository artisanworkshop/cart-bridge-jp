<?php
/**
 * プラグイン本体のシングルトン起動クラス。
 *
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Core;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Admin\Assets;
use CartBridgeJP\Admin\Menu;
use CartBridgeJP\Admin\RestController;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\LogCleanup;
use CartBridgeJP\Sync\LogRepository;

/**
 * 各レイヤーのフックを配線して起動する。
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * 各層のフックを登録する。`plugins_loaded` から一度だけ呼ばれる想定。
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'cart-bridge-jp', false, dirname( plugin_basename( CBJP_FILE ) ) . '/languages' );

		// プラグイン更新時はactivation hookが発火しないため、管理画面アクセス時に
		// DBスキーマバージョンを比較して必要ならマイグレーションする。
		add_action( 'admin_init', [ Activator::class, 'maybe_upgrade' ] );

		add_action( 'admin_notices', [ $this, 'render_missing_sodium_notice' ] );

		add_filter(
			'cbjp/adapters/register',
			static function ( $adapters ): array {
				// 型宣言でarrayを強制すると、先行する外部フィルターが不正値を返した
				// 場合にTypeErrorで全アダプタ登録が落ちる（AdapterRegistry::all()の
				// is_arrayフォールバックにも到達しない）。ここで正規化する（§8）。
				if ( ! is_array( $adapters ) ) {
					$adapters = [];
				}

				$adapters[ ColorMeAdapter::ID ] = new ColorMeAdapter();

				return $adapters;
			}
		);

		$menu = new Menu();
		add_action( 'admin_menu', [ $menu, 'register' ] );

		$assets = new Assets();
		add_action( 'admin_enqueue_scripts', [ $assets, 'enqueue' ] );

		add_action(
			'rest_api_init',
			function () {
				( new RestController() )->register_routes();
			}
		);

		add_action(
			JobManager::ACTION_HOOK,
			function ( int $job_id ) {
				JobManager::create()->process_job( $job_id );
			}
		);

		add_action(
			LogCleanup::ACTION_HOOK,
			function () {
				( new LogCleanup( new LogRepository() ) )->run();
			}
		);

		add_action(
			'init',
			function () {
				( new LogCleanup( new LogRepository() ) )->schedule();
			}
		);
	}

	/**
	 * sodium拡張なしのPHPビルド（--without-sodium）向けの管理画面通知（03 §4）。
	 * TokenStoreの暗号化が動作しないため、接続前に環境の問題を知らせる。
	 */
	public function render_missing_sodium_notice(): void {
		// WordPress core は ext-sodium が無いホストでもsodium_compatポリフィルを読み込み、
		// sodium_crypto_secretbox() 等のユーザーランド実装を提供してしまうため、
		// function_exists() では常にtrueになりネイティブ拡張の有無を判定できない。
		// extension_loaded() でネイティブ拡張自体の有無を見る。
		if ( extension_loaded( 'sodium' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Cart Bridge JP requires the PHP sodium extension to store API credentials securely. Please contact your hosting provider.', 'cart-bridge-jp' )
		);
	}
}
