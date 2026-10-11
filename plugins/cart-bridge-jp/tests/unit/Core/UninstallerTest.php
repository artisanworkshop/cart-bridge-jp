<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Core\Uninstaller;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\LogCleanup;
use CartBridgeJP\Sync\LogRepository;
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

	public function tear_down(): void {
		as_unschedule_all_actions( '', [], Uninstaller::ACTION_GROUP );
		parent::tear_down();
	}

	public function test_uninstall_is_a_noop_without_opt_in(): void {
		update_option( 'cbjp_token_colorme', 'encrypted-blob', false );

		Uninstaller::uninstall();

		$this->assertSame( 'encrypted-blob', get_option( 'cbjp_token_colorme' ) );
	}

	public function test_uninstall_deletes_all_cbjp_options_when_opted_in(): void {
		Uninstaller::set_delete_data( true );
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
		$this->assertFalse( get_option( Uninstaller::DELETE_DATA_OPTION ) );
	}

	/**
	 * 削除するのは保存値が `'yes'` のときだけ（R3-5a）。`update_option( true )` は DB に `'1'` で入るので、`true` も `'1'` も
	 * 「消す」ではない。壊れた値・型違いは消さない側へ倒す（データを消す判定のフェイルクローズ）。
	 *
	 * @dataProvider provide_values_that_keep_the_data
	 */
	public function test_uninstall_keeps_the_data_unless_the_stored_value_is_yes( mixed $stored ): void {
		update_option( Uninstaller::DELETE_DATA_OPTION, $stored, false );
		update_option( 'cbjp_token_colorme', 'encrypted-blob', false );

		$this->assertFalse( Uninstaller::delete_data_enabled() );

		Uninstaller::uninstall();

		$this->assertSame( 'encrypted-blob', get_option( 'cbjp_token_colorme' ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function provide_values_that_keep_the_data(): array {
		return [
			'no'           => [ 'no' ],
			'bool true'    => [ true ],
			'string 1'     => [ '1' ],
			'int 1'        => [ 1 ],
			'string true'  => [ 'true' ],
			'upper YES'    => [ 'YES' ],
			'yes + space'  => [ 'yes ' ],
			'array of yes' => [ [ 'yes' ] ],
			'empty string' => [ '' ],
		];
	}

	public function test_set_delete_data_stores_yes_or_no_without_autoload(): void {
		global $wpdb;

		Uninstaller::set_delete_data( true );

		$this->assertSame( 'yes', get_option( Uninstaller::DELETE_DATA_OPTION ) );
		$this->assertTrue( Uninstaller::delete_data_enabled() );

		Uninstaller::set_delete_data( false );

		$this->assertSame( 'no', get_option( Uninstaller::DELETE_DATA_OPTION ) );
		$this->assertFalse( Uninstaller::delete_data_enabled() );

		// アンインストールと設定画面でしか読まないので、毎リクエストの autoload に載せない。
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Uninstaller::DELETE_DATA_OPTION )
		);

		$this->assertContains( $autoload, [ 'no', 'off' ] );
	}

	/**
	 * `update_option()` は値が同じなら autoload を変えずに戻る。WP-CLI などで autoload ありに作られた行も、同じ値の保存で
	 * autoload なしへ揃える（G1-1）。
	 *
	 * @dataProvider provide_stored_values
	 */
	public function test_set_delete_data_turns_off_autoload_even_when_the_value_is_unchanged( bool $enabled, string $stored ): void {
		global $wpdb;

		add_option( Uninstaller::DELETE_DATA_OPTION, $stored, '', true );

		Uninstaller::set_delete_data( $enabled );

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Uninstaller::DELETE_DATA_OPTION )
		);

		$this->assertSame( $stored, get_option( Uninstaller::DELETE_DATA_OPTION ) );
		$this->assertContains( $autoload, [ 'no', 'off' ] );
	}

	/**
	 * @return array<string,array{0:bool,1:string}>
	 */
	public static function provide_stored_values(): array {
		return [
			'yes kept' => [ true, 'yes' ],
			'no kept'  => [ false, 'no' ],
		];
	}

	/**
	 * データを消すときは、未実行のジョブ・ログ整理のアクションも取り消す（プラグインが消えた後に期限が来て、コールバックの無いまま
	 * 失敗として積まれないように）。消さないときは取り消さない（アンインストールでは何も変えない）。
	 */
	public function test_uninstall_unschedules_the_plugin_actions_only_when_deleting_data(): void {
		as_schedule_single_action( time() + HOUR_IN_SECONDS, JobManager::ACTION_HOOK, [ 'job_id' => 42 ], JobManager::ACTION_GROUP );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'cbjp_unrelated_hook_in_another_group', [], 'another-plugin' );

		Uninstaller::uninstall();

		$this->assertNotFalse( as_next_scheduled_action( JobManager::ACTION_HOOK, [ 'job_id' => 42 ], JobManager::ACTION_GROUP ) );

		Uninstaller::set_delete_data( true );
		Uninstaller::uninstall();

		$this->assertFalse( as_next_scheduled_action( JobManager::ACTION_HOOK, [ 'job_id' => 42 ], JobManager::ACTION_GROUP ) );
		// 他のプラグインのアクションには触れない。
		$this->assertNotFalse( as_next_scheduled_action( 'cbjp_unrelated_hook_in_another_group', [], 'another-plugin' ) );

		as_unschedule_all_actions( '', [], 'another-plugin' );
	}

	/**
	 * `uninstall.php` は autoload の無い経路でも動くよう、グループ名を `JobManager` から参照せず書き写している。
	 */
	public function test_action_group_matches_the_group_the_plugin_schedules_in(): void {
		$this->assertSame( JobManager::ACTION_GROUP, Uninstaller::ACTION_GROUP );

		// ブートストラップ（`init`）が予定したログ整理がコミット済みで残っていることがある（tests サイトと同じ DB）。先に消してから
		// 予定し直さないと、`schedule()` が何もしないまま下の表明が通る（取り消しはテストの終わりに戻る）。
		as_unschedule_all_actions( LogCleanup::ACTION_HOOK );
		$this->assertFalse( as_has_scheduled_action( LogCleanup::ACTION_HOOK ) );

		( new LogCleanup( new LogRepository() ) )->schedule();

		$this->assertTrue( as_has_scheduled_action( LogCleanup::ACTION_HOOK, [], Uninstaller::ACTION_GROUP ) );
	}
}
