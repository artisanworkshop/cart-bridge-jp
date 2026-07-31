<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Core\Plugin;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_all_filters( 'cbjp/adapters/register' );

		// Plugin::boot()は実プロセスの`plugins_loaded`で一度だけ実行済みのため、
		// bootedフラグをリフレクションで戻し、本物の登録クロージャを改めてフックする
		// （クロージャを複製して検証すると本体の退行を検出できないため）。
		$plugin = Plugin::instance();
		$booted = new \ReflectionProperty( Plugin::class, 'booted' );
		$booted->setValue( $plugin, false );
		$plugin->boot();

		AdapterRegistry::reset_cache();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		parent::tear_down();
	}

	public function test_boot_resets_a_prematurely_populated_adapter_cache(): void {
		// フィルター登録（boot）より前に外部コードがレジストリの一覧取得を行い、
		// フィルター未登録の結果がキャッシュへ固定された状態を再現する。
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->assertSame( [], AdapterRegistry::all() );

		$plugin = Plugin::instance();
		$booted = new \ReflectionProperty( Plugin::class, 'booted' );
		$booted->setValue( $plugin, false );
		$plugin->boot();

		// boot()が登録後にキャッシュを破棄しない場合、ここでは固定済みの空配列が
		// 返り続け、ColorMeがリクエストの間ずっと見えない。
		$this->assertArrayHasKey( ColorMeAdapter::ID, AdapterRegistry::all() );
	}

	public function test_colorme_registration_survives_a_misbehaving_earlier_filter(): void {
		// 先行する外部フィルターが契約違反の非配列を返すシナリオ。登録クロージャが
		// array型宣言のままだとTypeErrorで全アダプタ登録が落ちる（§8の信頼境界）。
		add_filter( 'cbjp/adapters/register', '__return_false', 5 );
		AdapterRegistry::reset_cache();

		$adapters = AdapterRegistry::all();

		$this->assertArrayHasKey( ColorMeAdapter::ID, $adapters );
		$this->assertInstanceOf( ColorMeAdapter::class, $adapters[ ColorMeAdapter::ID ] );
	}
}
