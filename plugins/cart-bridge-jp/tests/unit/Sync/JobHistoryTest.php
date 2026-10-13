<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\JobHistory;
use CartBridgeJP\Sync\JobRepository;
use WP_UnitTestCase;

/**
 * ジョブの記録を読む拡張点（R3-10）。種類・プラットフォーム・実体で絞り、行の値を判定せずに返す。
 */
final class JobHistoryTest extends WP_UnitTestCase {

	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->jobs = new JobRepository();
	}

	public function test_returns_only_the_matching_jobs_in_id_order(): void {
		$first  = $this->jobs->create( 'run-a', 'import', 'colorme', 'category' );
		$second = $this->jobs->create( 'run-b', 'import', 'colorme', 'category' );
		$this->jobs->create( 'run-c', 'dry_run', 'colorme', 'category' );
		$this->jobs->create( 'run-d', 'import', 'other', 'category' );
		$this->jobs->create( 'run-e', 'import', 'colorme', 'product' );

		$ids = array_column( ( new JobHistory() )->jobs( 'import', 'colorme', 'category' ), 'id' );

		$this->assertSame( [ $first, $second ], $ids );
	}

	public function test_returns_the_stored_values_without_judging_them(): void {
		global $wpdb;

		$id = $this->jobs->create( 'run-a', 'import', 'colorme', 'category' );
		$this->jobs->mark_failed(
			$id,
			[
				'code'    => 'action_lost',
				'message' => 'lost',
			]
		);
		$wpdb->update(
			$wpdb->prefix . 'cbjp_jobs',
			[
				'totals_json' => '{"created":3,"processed":4}',
				'created_at'  => '2026-09-01 00:00:00',
				'updated_at'  => '2026-09-01 00:05:00',
			],
			[ 'id' => $id ]
		);

		$jobs = ( new JobHistory() )->jobs( 'import', 'colorme', 'category' );

		$this->assertSame(
			[
				[
					'id'         => $id,
					'status'     => 'failed',
					'created_at' => '2026-09-01 00:00:00',
					'updated_at' => '2026-09-01 00:05:00',
					'totals'     => [
						'created'   => 3,
						'processed' => 4,
					],
					'error_code' => 'action_lost',
				],
			],
			$jobs
		);
	}

	/**
	 * 今の版が作るジョブの totals には `remote_amount` がある（0.1.0 には無い。拡張がジョブの版を見分けるのに使う）。
	 */
	public function test_new_jobs_carry_the_current_totals_keys(): void {
		$this->jobs->create( 'run-a', 'import', 'colorme', 'category' );

		$job = ( new JobHistory() )->jobs( 'import', 'colorme', 'category' )[0];

		$this->assertArrayHasKey( 'remote_amount', $job['totals'] );
		$this->assertNull( $job['error_code'] );
	}

	/**
	 * 壊れた JSON は空の totals・null のエラーコードにする（一覧を落とさない）。
	 */
	public function test_tolerates_broken_json(): void {
		global $wpdb;

		$id = $this->jobs->create( 'run-a', 'import', 'colorme', 'category' );
		$wpdb->update(
			$wpdb->prefix . 'cbjp_jobs',
			[
				'totals_json' => '{broken',
				'error_json'  => '{"code":7}',
			],
			[ 'id' => $id ]
		);

		$job = ( new JobHistory() )->jobs( 'import', 'colorme', 'category' )[0];

		$this->assertSame( [], $job['totals'] );
		$this->assertNull( $job['error_code'] );
	}
}
