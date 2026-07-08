<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Support\Logger;
use RuntimeException;
use Throwable;

/**
 * 移行実行（run）の開始とジョブ進行を管理する。
 * 1アクション（`process_job()` 1回）= 1ページ処理。Action Scheduler経由で自己再エンキューする。
 */
final class JobManager {

	public const ACTION_HOOK = 'cbjp_process_job';

	/**
	 * エンティティ実行順（`docs/03-design-decisions.md` §3）。
	 */
	private const ENTITY_ORDER = [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ];

	private const SAMPLE_DRIVEN_ENTITIES     = [ 'product', 'customer' ];
	private const SAMPLE_MEMBERSHIP_FILTERED = [ 'stock', 'review' ];

	public function __construct(
		private readonly JobRepository $jobs,
		private readonly LimitPolicy $limits,
		private readonly Importer $importer,
		private readonly WooWriter $writer,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * @param array<int,string> $entities
	 *
	 * @throws RunAlreadyInProgressException 同一プラットフォームで既に running のジョブがある場合。
	 * @throws RuntimeException 未登録プラットフォーム、または対応エンティティが1つもない場合。
	 */
	public function start_run( string $type, string $platform, array $entities ): string {
		if ( $this->jobs->has_running_job_for_platform( $platform ) ) {
			throw new RunAlreadyInProgressException( $platform );
		}

		$adapter = AdapterRegistry::get( $platform );

		if ( null === $adapter ) {
			throw new RuntimeException( "Unknown platform: {$platform}" );
		}

		$ordered_entities = $this->filter_and_order_entities( $entities, $adapter );

		if ( [] === $ordered_entities ) {
			throw new RuntimeException( 'No supported entities to run.' );
		}

		$run_id  = wp_generate_uuid4();
		$job_ids = [];

		foreach ( $ordered_entities as $entity ) {
			$job_ids[] = $this->jobs->create( $run_id, $type, $platform, $entity );
		}

		$first_job_id = $job_ids[0];
		$this->jobs->update_status( $first_job_id, JobRepository::STATUS_RUNNING );
		$this->enqueue( $first_job_id );

		return $run_id;
	}

	/**
	 * 1ページ処理する。Action Schedulerのアクションコールバック、またはテスト/同期実行から直接呼ばれる。
	 */
	public function process_job( int $job_id ): void {
		$job = $this->jobs->find( $job_id );

		if ( null === $job ) {
			return;
		}

		if ( in_array( $job['status'], [ JobRepository::STATUS_COMPLETED, JobRepository::STATUS_FAILED, JobRepository::STATUS_CANCELLED ], true ) ) {
			return;
		}

		if ( JobRepository::STATUS_PENDING === $job['status'] ) {
			$this->jobs->update_status( $job_id, JobRepository::STATUS_RUNNING );
		}

		$adapter = AdapterRegistry::get( $job['platform'] );

		if ( null === $adapter ) {
			$this->jobs->mark_failed(
				$job_id,
				[
					'code'    => 'adapter_not_found',
					'message' => "Adapter not found for platform \"{$job['platform']}\".",
				]
			);
			$this->logger->error(
				'Adapter not found for job.',
				[
					'platform' => $job['platform'],
					'job_id'   => $job_id,
				]
			);

			return;
		}

		$is_dry_run = 'dry_run' === $job['type'];
		$writer     = $is_dry_run ? new DryRunReporter() : $this->writer;
		$entity     = $job['entity'];

		try {
			[ $page_totals, $next_cursor ] = $this->process_page( $adapter, $writer, $entity, $job, $is_dry_run );
		} catch ( Throwable $exception ) {
			$this->jobs->mark_failed(
				$job_id,
				[
					'code'    => 'exception',
					'message' => $exception->getMessage(),
				]
			);
			$this->logger->error(
				'Job failed with an exception.',
				[
					'platform' => $job['platform'],
					'entity'   => $entity,
					'job_id'   => $job_id,
				]
			);

			return;
		}

		$accumulated = $this->merge_totals( $this->decode_totals( $job['totals_json'] ), $page_totals );
		$this->jobs->update_progress( $job_id, $next_cursor?->to_json(), $accumulated );

		if ( null === $next_cursor ) {
			$this->complete_job_and_advance( $job_id, $job['run_id'] );
		} else {
			$this->enqueue( $job_id );
		}
	}

	/**
	 * テスト・同期実行用: runが完了する（または安全上限に達する）までprocess_job()を繰り返す。
	 * Action Schedulerのキュー実行を待たずに、resume可能性を含めて検証できる。
	 */
	public function run_to_completion( string $run_id, int $max_actions = 1000 ): void {
		for ( $i = 0; $i < $max_actions; $i++ ) {
			$job = $this->jobs->find_next_incomplete_for_run( $run_id );

			if ( null === $job ) {
				return;
			}

			$this->process_job( (int) $job['id'] );
		}
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array{0:array<string,int>,1:?Cursor}
	 */
	private function process_page( PlatformAdapter $adapter, WooWriter $writer, string $entity, array $job, bool $is_dry_run ): array {
		if ( ! $is_dry_run && in_array( $entity, self::SAMPLE_DRIVEN_ENTITIES, true ) && null !== $this->limits->limit_for( $entity ) ) {
			$sample     = $this->sample_selector_for( $adapter )->select_or_load( $adapter->id() );
			$remote_ids = 'product' === $entity ? $sample->product_remote_ids : $sample->customer_refs;
			$result     = $this->importer->run_sample_page( $adapter, $writer, $entity, $remote_ids, false );

			return [ $result['totals'], null ];
		}

		$cursor       = Cursor::from_json( $job['cursor_json'] );
		$sample       = ( ! $is_dry_run && in_array( $entity, self::SAMPLE_MEMBERSHIP_FILTERED, true ) )
			? $this->sample_selector_for( $adapter )->select_or_load( $adapter->id() )
			: null;
		$limit_policy = $is_dry_run ? null : $this->limits;

		$result = $this->importer->run_page( $adapter, $writer, $entity, $cursor, $is_dry_run, $limit_policy, $sample );

		return [ $result['totals'], $result['next_cursor'] ];
	}

	private function complete_job_and_advance( int $job_id, string $run_id ): void {
		$this->jobs->update_status( $job_id, JobRepository::STATUS_COMPLETED );

		$next_job = $this->jobs->find_next_incomplete_for_run( $run_id );

		if ( null === $next_job ) {
			return;
		}

		$this->jobs->update_status( (int) $next_job['id'], JobRepository::STATUS_RUNNING );
		$this->enqueue( (int) $next_job['id'] );
	}

	/**
	 * @param array<int,string> $requested
	 * @return array<int,string>
	 */
	private function filter_and_order_entities( array $requested, PlatformAdapter $adapter ): array {
		$capabilities = $adapter->capabilities();

		$supported = array_filter(
			self::ENTITY_ORDER,
			static function ( string $entity ) use ( $requested, $capabilities ): bool {
				if ( ! in_array( $entity, $requested, true ) ) {
					return false;
				}

				return match ( $entity ) {
					'tag' => $capabilities->has_tags,
					'coupon' => $capabilities->has_coupons,
					'review' => $capabilities->has_reviews,
					'customer' => $capabilities->can_fetch_customers,
					default => true,
				};
			}
		);

		return array_values( $supported );
	}

	private function sample_selector_for( PlatformAdapter $adapter ): SampleSelector {
		return new SampleSelector( $adapter );
	}

	/**
	 * @return array{total:int,processed:int,created:int,updated:int,skipped:int,warned:int,failed:int}
	 */
	private function decode_totals( ?string $totals_json ): array {
		$decoded = null !== $totals_json ? json_decode( $totals_json, true ) : null;

		return is_array( $decoded ) ? array_merge( $this->jobs->empty_totals(), $decoded ) : $this->jobs->empty_totals();
	}

	/**
	 * @param array<string,int> $accumulated
	 * @param array<string,int> $page_totals
	 * @return array<string,int>
	 */
	private function merge_totals( array $accumulated, array $page_totals ): array {
		foreach ( $page_totals as $key => $value ) {
			$accumulated[ $key ] = ( $accumulated[ $key ] ?? 0 ) + $value;
		}

		return $accumulated;
	}

	private function enqueue( int $job_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_HOOK, [ 'job_id' => $job_id ], 'cart-bridge-jp' );
		}
	}
}
