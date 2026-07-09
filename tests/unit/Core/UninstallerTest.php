<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Core\Uninstaller;
use WP_UnitTestCase;

/**
 * 注: テーブルDROPの検証はここでは行わない。WPテストスイートは `query` フィルターで
 * `DROP TABLE` を `DROP TEMPORARY TABLE` に書き換えて共有DBの実テーブルを保護するため、
 * ユニットテストからは観測できない（テーブル削除はwp-envでの手動アンインストールで確認する）。
 */
final class UninstallerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
	}

	public function test_uninstall_is_a_noop_without_opt_in(): void {
		update_option( 'cbjp_token_colorme', 'encrypted-blob', false );

		Uninstaller::uninstall();

		$this->assertSame( 'encrypted-blob', get_option( 'cbjp_token_colorme' ) );
	}

	public function test_uninstall_deletes_all_cbjp_options_when_opted_in(): void {
		update_option( Uninstaller::DELETE_DATA_OPTION, true, false );
		update_option( 'cbjp_token_colorme', 'encrypted-blob', false );
		update_option( 'cbjp_token_lock_colorme', '123', false );
		update_option( 'cbjp_sample_colorme', [ 'order_remote_ids' => [] ], false );
		update_option( 'cbjp_rate_limit_colorme', '{"tokens":1}', false );

		Uninstaller::uninstall();

		// オプション名は素の cbjp_ 接頭辞（テーブル接頭辞なし）で保存されているため、
		// 暗号化トークンを含む全 cbjp_* オプションが削除されること。
		$this->assertFalse( get_option( 'cbjp_token_colorme' ) );
		$this->assertFalse( get_option( 'cbjp_token_lock_colorme' ) );
		$this->assertFalse( get_option( 'cbjp_sample_colorme' ) );
		$this->assertFalse( get_option( 'cbjp_rate_limit_colorme' ) );
		$this->assertFalse( get_option( Activator::DB_VERSION_OPTION ) );
	}
}
