<?php
/**
 * Pro アドオン本体のシングルトン起動クラス。
 *
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Core;

/**
 * Pro アドオンのフックを配線して起動する。顧客・受注・クーポンの実体の種類は、R3-6 で無料版の拡張点に登録する。
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
	 * フックを登録する。`plugins_loaded`（無料版より後）から一度だけ呼ばれる想定。
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
	}

	public function is_booted(): bool {
		return $this->booted;
	}
}
