<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use Closure;

/**
 * ページを処理しているジョブの `updated_at` を進め、処理中であることを示す（R3-9・issue #121）。
 *
 * 孤立したジョブの回復（`OrphanedRunRecovery`）は、Action Scheduler に生きたアクションが無く、`updated_at` が猶予より古い run を失敗にする。
 * Action Scheduler は in-progress のアクションを 5 分（`action_scheduler_failure_period`）で失敗扱いにし、続きのページでは状態を遷移しないので、
 * 何もしなければ画像の取込み・多数のバリエーションの保存で長引いたページを、処理している途中に孤立と誤認する（誤認したジョブを Retry すると、
 * 古いページと新しいアクションが同じ cursor を処理する）。`JobManager::process_job()` がページの開始時・アイテムごと・HTTP の応答ごとに打つ。
 * 書込みは `INTERVAL_SECONDS` に 1 回まで（ページの開始時は間引かない）。
 */
final class JobHeartbeat {

	public const INTERVAL_SECONDS = 60;

	private ?int $last_beat_at = null;

	/**
	 * @param Closure():int|null $clock 今の UNIX 時刻（テストで差し替える。既定は `time()`）。
	 */
	public function __construct(
		private readonly JobRepository $jobs,
		private readonly int $job_id,
		private readonly ?Closure $clock = null
	) {}

	/**
	 * ページの開始時に、間引かずに打つ。
	 */
	public function start(): void {
		$this->last_beat_at = $this->now();
		$this->jobs->touch( $this->job_id );
	}

	/**
	 * 前に打ってから `INTERVAL_SECONDS` 以上経っていれば打つ。
	 */
	public function beat(): void {
		$now = $this->now();

		if ( null !== $this->last_beat_at && $now - $this->last_beat_at < self::INTERVAL_SECONDS ) {
			return;
		}

		$this->last_beat_at = $now;
		$this->jobs->touch( $this->job_id );
	}

	private function now(): int {
		return null === $this->clock ? time() : ( $this->clock )();
	}
}
