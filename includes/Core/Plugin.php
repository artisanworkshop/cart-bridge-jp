<?php
/**
 * プラグイン本体のシングルトン起動クラス。
 *
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Core;

use CartBridgeJP\Admin\Assets;
use CartBridgeJP\Admin\Menu;
use CartBridgeJP\Admin\RestController;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\LogCleanup;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\NotImplementedWriter;

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
				$this->job_manager()->process_job( $job_id );
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
	 * dry-run専用の JobManager。実移行の書込みは Phase 1 の Woo\WooRepository を待つ
	 * （`NotImplementedWriter` は import/export では呼ばれない。REST層が501を返すため）。
	 */
	private function job_manager(): JobManager {
		$mappings = new MappingRepository();

		return new JobManager(
			new JobRepository(),
			new LimitPolicy( $mappings ),
			new Importer( $mappings ),
			new NotImplementedWriter()
		);
	}
}
