<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\JobHeartbeat;
use CartBridgeJP\Sync\JobRepository;
use WP_UnitTestCase;

/**
 * 処理中のジョブのハートビート（R3-9・issue #121）: ページの開始時は間引かずに打ち、その後は `INTERVAL_SECONDS` に 1 回まで。
 */
final class JobHeartbeatTest extends WP_UnitTestCase {

	private const OLD = '2020-01-01 00:00:00';

	private JobRepository $jobs;

	private int $job_id;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->jobs   = new JobRepository();
		$this->job_id = $this->jobs->create( 'run-h', 'import', 'mock', 'category' );
		$this->jobs->update_status( $this->job_id, JobRepository::STATUS_RUNNING );
	}

	private function make_old(): void {
		global $wpdb;

		$wpdb->update( $wpdb->prefix . 'cbjp_jobs', [ 'updated_at' => self::OLD ], [ 'id' => $this->job_id ], [ '%s' ], [ '%d' ] );
	}

	private function touched(): bool {
		return self::OLD !== $this->jobs->find( $this->job_id )['updated_at'];
	}

	public function test_beats_at_the_start_and_then_at_most_once_per_interval(): void {
		$now       = 1_000_000;
		$heartbeat = new JobHeartbeat(
			$this->jobs,
			$this->job_id,
			static function () use ( &$now ): int {
				return $now;
			}
		);

		$this->make_old();
		$heartbeat->start();
		$this->assertTrue( $this->touched(), 'The start of a page always beats.' );

		$this->make_old();
		$now += JobHeartbeat::INTERVAL_SECONDS - 1;
		$heartbeat->beat();
		$this->assertFalse( $this->touched(), 'A beat within the interval is skipped.' );

		++$now;
		$heartbeat->beat();
		$this->assertTrue( $this->touched(), 'A beat after the interval touches the job.' );

		$this->make_old();
		$now += JobHeartbeat::INTERVAL_SECONDS - 1;
		$heartbeat->beat();
		$this->assertFalse( $this->touched(), 'The interval restarts from the last beat.' );
	}

	public function test_the_first_beat_without_a_start_touches_the_job(): void {
		$heartbeat = new JobHeartbeat( $this->jobs, $this->job_id );

		$this->make_old();
		$heartbeat->beat();

		$this->assertTrue( $this->touched() );
	}
}
