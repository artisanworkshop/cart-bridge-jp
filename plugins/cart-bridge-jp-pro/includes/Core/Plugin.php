<?php
/**
 * Pro アドオン本体のシングルトン起動クラス。
 *
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Core;

use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Pro\Adapters\CommerceAdapters;
use CartBridgeJP\Pro\Entities\CommerceEntityTypes;

/**
 * Pro アドオンのフックを配線して起動する。顧客・受注・クーポンの実体の種類を無料版の拡張点（`cbjp/entity_types/register`）に
 * 登録し（R3-6c1 で無料版から移した。D27）、それらに要る OAuth のスコープを無料版の認可の要求に足す（R3-6c2）。
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

		// 無料版の起動（plugins_loaded の優先度 10）より後なので、ここより前に一覧が引かれていた場合に備えて登録後にキャッシュを捨てる
		// （`EntityTypeRegistry` は plugins_loaded の途中の結果をキャッシュしないが、外部コードが早く呼ぶ経路もある）。
		add_filter( EntityTypeRegistry::FILTER, [ CommerceEntityTypes::class, 'register' ] );
		EntityTypeRegistry::reset_cache();

		// 無料版は商品のスコープだけを要求する。顧客・受注・クーポンのスコープは Pro が足す（R3-6c2。`docs/03` §10.0 決め残し 9。フィルター名は
		// 無料版の `ColorMeOAuth::SCOPES_FILTER`。`ColorMeOAuth` は Pro が使ってよい API の一覧に無いのでクラスを参照しない）。
		add_filter( 'cbjp/oauth/scopes', [ CommerceAdapters::class, 'add_oauth_scopes' ], 10, 2 );
	}

	public function is_booted(): bool {
		return $this->booted;
	}
}
