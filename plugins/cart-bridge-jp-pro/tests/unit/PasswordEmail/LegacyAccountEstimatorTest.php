<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\PasswordEmail;

use CartBridgeJP\Pro\PasswordEmail\LegacyAccountEstimator;
use CartBridgeJP\Pro\Tests\Fixtures\CreatesImportedCustomers;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\OrphanedRunRecovery;
use CartBridgeJP\Tests\Woo\WooTestCase;
use ReflectionClassConstant;

/**
 * 0.1.0 の取込みで作ったアカウントの推定（R3-10。ユーザー決定 2026-10-13）。登録日時が 0.1.0 の顧客の取込みジョブの実行中に入る、作成の印の無いアカウント。
 */
final class LegacyAccountEstimatorTest extends WooTestCase {

	use CreatesImportedCustomers;

	private const START = '2026-09-01 10:00:00';
	private const END   = '2026-09-01 10:05:00';

	/**
	 * 窓の両端を含み、1 秒外は含まない（同じ秒に作ると境界を壊しても通るので、秒をずらして書く）。
	 */
	public function test_estimates_accounts_registered_while_a_legacy_import_ran(): void {
		$this->legacy_import_job( self::START, self::END );
		$at_start  = $this->legacy_customer( '1', self::START );
		$inside    = $this->legacy_customer( '2', '2026-09-01 10:02:30' );
		$at_end    = $this->legacy_customer( '3', self::END );
		$before    = $this->legacy_customer( '4', '2026-09-01 09:59:59' );
		$after     = $this->legacy_customer( '5', '2026-09-01 10:05:01' );
		$estimated = LegacyAccountEstimator::create()->estimate();

		$this->assertSame( [ $at_start, $inside, $at_end ], $estimated['ids'] );
		$this->assertNotContains( $before, $estimated['ids'], '取込みより前からあったアカウント（メールの一致で採用した）' );
		$this->assertNotContains( $after, $estimated['ids'] );
		$this->assertFalse( $estimated['discarded'] );
	}

	/**
	 * 作成の印があるアカウント（今の取込みで作った・判定は印で行う）と、紐づけの印の無いアカウント（取込みと関係ない）は推定しない。
	 */
	public function test_ignores_marked_and_unlinked_accounts(): void {
		$this->legacy_import_job( self::START, self::END );
		$marked = $this->legacy_customer( '1', self::START );
		update_user_meta( $marked, '_cbjp_created_by_import', 'colorme' );
		$unlinked     = self::factory()->user->create(
			[
				'role'            => 'customer',
				'user_registered' => self::START,
			]
		);
		$empty_remote = $this->legacy_customer( '2', self::START );
		update_user_meta( $empty_remote, '_cbjp_remote_id', '' );

		$this->assertSame( [], LegacyAccountEstimator::create()->estimate()['ids'] );
		$this->assertNotSame( 0, $unlinked );
	}

	/**
	 * 窓に使うのは 0.1.0 のジョブだけ: 今の版のジョブ（totals に `remote_amount`）・後から `updated_at` が動きうる状態（取消・未終了）・孤立の回復で失敗にした
	 * ジョブ・何も作らなかったジョブ・別のプラットフォームのジョブは使わない。
	 *
	 * @dataProvider unusable_jobs
	 *
	 * @param array<string,int> $totals
	 */
	public function test_ignores_jobs_that_do_not_mark_a_legacy_import( array $totals, string $status, ?string $error_code, string $platform ): void {
		$this->legacy_import_job( self::START, self::END, $totals, $status, $error_code, $platform );
		$this->legacy_customer( '1', '2026-09-01 10:01:00' );

		$this->assertSame( [], LegacyAccountEstimator::create()->estimate()['ids'] );
	}

	/**
	 * @return array<string,array{0:array<string,int>,1:string,2:?string,3:string}>
	 */
	public static function unusable_jobs(): array {
		return [
			'current version'  => [
				[
					'created'       => 10,
					'remote_amount' => 0,
				],
				'completed',
				null,
				'colorme',
			],
			'cancelled'        => [ [ 'created' => 10 ], 'cancelled', null, 'colorme' ],
			'running'          => [ [ 'created' => 10 ], 'running', null, 'colorme' ],
			'pending'          => [ [ 'created' => 10 ], 'pending', null, 'colorme' ],
			'recovered lost'   => [ [ 'created' => 10 ], 'failed', 'action_lost', 'colorme' ],
			'recovered fatal'  => [ [ 'created' => 10 ], 'failed', 'fatal_error', 'colorme' ],
			'nothing created'  => [ [ 'created' => 0 ], 'completed', null, 'colorme' ],
			'no created count' => [ [ 'processed' => 3 ], 'completed', null, 'colorme' ],
			'other platform'   => [ [ 'created' => 10 ], 'completed', null, 'base' ],
		];
	}

	/**
	 * 何も作らなかったジョブの窓は使わない（安全網〔作った件数の合計〕だけに頼らない: 別のジョブが作った件数で合計が足りると、窓の外のアカウントが入る）。
	 */
	public function test_does_not_use_the_window_of_a_job_that_created_nothing(): void {
		$this->legacy_import_job( self::START, self::END, [ 'created' => 1 ] );
		$this->legacy_import_job( '2026-09-02 10:00:00', '2026-09-02 10:05:00', [ 'created' => 0 ] );
		$this->legacy_import_job( '2026-09-03 10:00:00', '2026-09-03 10:05:00', [ 'processed' => 1 ] );
		$created = $this->legacy_customer( '1', '2026-09-01 10:01:00' );
		$this->legacy_customer( '2', '2026-09-02 10:01:00' );
		$this->legacy_customer( '3', '2026-09-03 10:01:00' );

		$this->assertSame( [ $created ], LegacyAccountEstimator::create()->estimate()['ids'] );
	}

	/**
	 * 0.1.0 の失敗したジョブ（回復のエラーでない）も、失敗までに作ったアカウントの窓になる。
	 */
	public function test_uses_a_legacy_job_that_failed_by_itself(): void {
		$this->legacy_import_job( self::START, self::END, [ 'created' => 2 ], 'failed', 'api_error' );
		$user_id = $this->legacy_customer( '1', '2026-09-01 10:01:00' );

		$this->assertSame( [ $user_id ], LegacyAccountEstimator::create()->estimate()['ids'] );
	}

	/**
	 * 今の無料版が作るジョブは totals に `remote_amount` を持つので、窓に使わない（推定の前提。無料版がこの鍵を外すとこのテストが落ちる）。
	 */
	public function test_jobs_created_by_the_current_free_plugin_are_not_legacy(): void {
		global $wpdb;

		$this->legacy_import_job( '2000-01-01 00:00:00', '2000-01-01 00:00:01' );
		$jobs = new JobRepository();
		$id   = $jobs->create( 'run', 'import', 'colorme', 'customer' );
		$wpdb->update(
			$wpdb->prefix . 'cbjp_jobs',
			[
				'status'     => 'completed',
				'created_at' => self::START,
				'updated_at' => self::END,
			],
			[ 'id' => $id ]
		);
		$this->legacy_customer( '1', '2026-09-01 10:01:00' );

		$this->assertSame( [], LegacyAccountEstimator::create()->estimate()['ids'] );
	}

	/**
	 * 窓に入るアカウントが窓で作った件数より多ければ、窓の取り違えとみなして推定をすべて捨てる。
	 */
	public function test_discards_the_estimate_when_more_accounts_fall_in_the_windows_than_were_created(): void {
		$this->legacy_import_job( self::START, self::END, [ 'created' => 1 ] );
		$this->legacy_customer( '1', '2026-09-01 10:01:00' );
		$this->legacy_customer( '2', '2026-09-01 10:02:00' );

		$this->assertSame(
			[
				'ids'       => [],
				'discarded' => true,
			],
			LegacyAccountEstimator::create()->estimate()
		);
	}

	/**
	 * 読んだ候補のメタのキャッシュを手放す（全員を読むときのメモリを抑える。R1-1）。
	 */
	public function test_releases_the_meta_cache_after_reading(): void {
		$this->legacy_import_job( self::START, self::END );
		$user_id = $this->legacy_customer( '1', '2026-09-01 10:01:00' );
		wp_cache_delete( $user_id, 'user_meta' );

		$this->assertSame( [ $user_id ], LegacyAccountEstimator::create()->estimate()['ids'] );
		$this->assertFalse( wp_cache_get( $user_id, 'user_meta' ) );
	}

	/**
	 * 判定に使う状態・エラーコードの文字列は、無料版の定数と同じ（無料版の名前を Pro のコードに書かないので、テストで突き合わせる）。
	 */
	public function test_status_and_error_literals_match_the_free_plugin(): void {
		$this->assertSame(
			[ JobRepository::STATUS_COMPLETED, JobRepository::STATUS_FAILED ],
			( new ReflectionClassConstant( LegacyAccountEstimator::class, 'FINISHED_STATUSES' ) )->getValue()
		);
		$this->assertSame(
			[ OrphanedRunRecovery::ERROR_ACTION_LOST, OrphanedRunRecovery::ERROR_FATAL ],
			( new ReflectionClassConstant( LegacyAccountEstimator::class, 'RECOVERY_ERRORS' ) )->getValue()
		);
	}
}
