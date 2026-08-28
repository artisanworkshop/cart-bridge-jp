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
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Woo\WooRepositoryFactory;
use RuntimeException;
use Throwable;

/**
 * 移行実行（run）の開始とジョブ進行を管理する。
 * 1アクション（`process_job()` 1回）= 1ページ処理。Action Scheduler経由で自己再エンキューする。
 */
final class JobManager {

	public const ACTION_HOOK = 'cbjp_process_job';

	public const TYPE_DRY_RUN = 'dry_run';
	public const TYPE_IMPORT  = 'import';
	public const TYPE_EXPORT  = 'export';

	/**
	 * レート制限枯渇で paused にしたジョブを再開するまでの待機秒数。
	 */
	private const PAUSED_RESUME_DELAY_SECONDS = 60;

	/**
	 * エンティティ実行順（`docs/03-design-decisions.md` §3）。
	 */
	private const ENTITY_ORDER = [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ];

	/**
	 * サンプルID指定取得で取り込むエンティティ（D15 §10.2 #4）。
	 */
	private const SAMPLE_ID_FETCH_ENTITIES = [ 'product', 'customer' ];

	public function __construct(
		private readonly JobRepository $jobs,
		private readonly LimitPolicy $limits,
		private readonly Importer $importer,
		private readonly WooWriterFactory $writer_factory,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * 既定の配線でJobManagerを生成する共有ファクトリ。
	 * `Woo\WooRepositoryFactory` が実移行・dry-run双方のwriterを組み立てる
	 * （dry-runは`Woo\DryRunRepository`に差し替わり、`Writer\EntityWriter::validate()`
	 * のみを呼ぶため何も永続化しない。F1-6）。
	 */
	public static function create( ?WooWriterFactory $writer_factory = null ): self {
		$mappings = new MappingRepository();

		return new self(
			new JobRepository(),
			new LimitPolicy( $mappings ),
			new Importer( $mappings ),
			$writer_factory ?? new WooRepositoryFactory()
		);
	}

	/**
	 * @param array<int,string> $entities
	 *
	 * @throws RunAlreadyInProgressException 同一プラットフォームで進行中（pending/running/paused）のジョブがある場合。
	 * @throws RuntimeException 未登録プラットフォーム、または対応エンティティが1つもない場合。
	 */
	public function start_run( string $type, string $platform, array $entities ): string {
		if ( ! in_array( $type, [ self::TYPE_DRY_RUN, self::TYPE_IMPORT, self::TYPE_EXPORT ], true ) ) {
			throw new RuntimeException( "Unknown run type: {$type}" );
		}

		if ( $this->jobs->has_active_job_for_platform( $platform ) ) {
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
	 * 失敗ジョブを pending に戻し、Action Scheduler に再エンキューする。
	 *
	 * @return bool 対象ジョブが存在し、failed だった場合のみ true。
	 */
	public function retry( int $job_id ): bool {
		$job = $this->jobs->find( $job_id );

		if ( null === $job || JobRepository::STATUS_FAILED !== $job['status'] ) {
			return false;
		}

		$this->jobs->update_status( $job_id, JobRepository::STATUS_PENDING );
		$this->enqueue( $job_id );

		return true;
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

		if ( in_array( $job['status'], [ JobRepository::STATUS_PENDING, JobRepository::STATUS_PAUSED ], true ) ) {
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

		$is_dry_run = self::TYPE_DRY_RUN === $job['type'];
		$entity     = $job['entity'];

		try {
			// `for_platform()`はwriter組み立て（Writerクラス群のnew）であり現状は例外を投げないが、
			// 将来的に検証等が加わって例外を投げるようになった場合でも、この呼び出し全体を
			// 下のcatchで確実に拾い`mark_failed()`させるため、tryの外に出さない
			// （tryの外に置くと、例外発生時にジョブがmark_failed()もされずSTATUS_RUNNINGのまま
			// 停止してしまう）。
			$writer                        = $is_dry_run ? $this->writer_factory->for_dry_run( $job['platform'] ) : $this->writer_factory->for_platform( $job['platform'] );
			[ $page_totals, $next_cursor ] = $this->process_page( $adapter, $writer, $entity, $job, $is_dry_run );
		} catch ( RateLimitExhaustedException ) {
			// レート制限の長期枯渇は一時停止して後で再開する（03 §3 ステートマシン / §4 RateLimiter）。
			$this->jobs->update_status( $job_id, JobRepository::STATUS_PAUSED );
			$this->logger->warning(
				'Job paused: rate limit exhausted.',
				[
					'platform' => $job['platform'],
					'entity'   => $entity,
					'job_id'   => $job_id,
				]
			);
			$this->enqueue_delayed( $job_id, self::PAUSED_RESUME_DELAY_SECONDS );

			return;
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
	 * paused（レート制限枯渇）のジョブは即時再試行しても進捗しないため早期リターンし、
	 * 再開タイミングは呼び出し側に委ねる（本番はenqueue_delayed()経由で再開される）。
	 */
	public function run_to_completion( string $run_id, int $max_actions = 1000 ): void {
		for ( $i = 0; $i < $max_actions; $i++ ) {
			$job = $this->jobs->find_next_incomplete_for_run( $run_id );

			if ( null === $job || JobRepository::STATUS_PAUSED === $job['status'] ) {
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
		// サンプリング（無料版）は商品上限の有無で判定する。stock/reviewの範囲は
		// 「サンプル商品に紐づくもの」なので、商品上限が解除（Pro）されたら連動して解除される。
		$sampling_active = ! $is_dry_run && null !== $this->limits->limit_for( 'product' );

		if ( ! $is_dry_run && in_array( $entity, self::SAMPLE_ID_FETCH_ENTITIES, true ) && null !== $this->limits->limit_for( $entity ) ) {
			$sample     = $this->sample_selector_for( $adapter )->select_or_load( $adapter->id() );
			$remote_ids = 'product' === $entity ? $sample->product_remote_ids : $sample->customer_refs;
			$result     = $this->importer->run_sample_page( $adapter, $writer, $entity, $remote_ids, false, $this->limits, (int) $job['id'], (string) $job['run_id'] );

			// サンプルID指定取得は1回で全件確定するため、件数がそのまま進捗率の分母になる。
			return [ array_merge( $result['totals'], [ 'total' => count( $remote_ids ) ] ), null ];
		}

		if ( 'stock' === $entity && $sampling_active ) {
			// §10.2 #4: 在庫の全量走査はレート制限を浪費するため、サンプル商品のID指定取得から導出する。
			// バリエーションを持つ商品は複数件のCanonicalStockに展開されるため、進捗率の分母は
			// サンプル商品数（`count($sample->product_remote_ids)`）ではなく`Importer`が実際に
			// 処理した件数（`$result['total']`）を使う（商品数のままだとprocessedがtotalを超えうる）。
			$sample = $this->sample_selector_for( $adapter )->select_or_load( $adapter->id() );
			$result = $this->importer->run_sample_stock_page( $adapter, $writer, $sample->product_remote_ids, false, (int) $job['id'], (string) $job['run_id'] );

			return [ array_merge( $result['totals'], [ 'total' => $result['total'] ] ), null ];
		}

		$cursor       = Cursor::from_json( $job['cursor_json'] );
		$sample       = ( 'review' === $entity && $sampling_active )
			? $this->sample_selector_for( $adapter )->select_or_load( $adapter->id() )
			: null;
		$limit_policy = $is_dry_run ? null : $this->limits;

		$result = $this->importer->run_page( $adapter, $writer, $entity, $cursor, $is_dry_run, $limit_policy, $sample, (int) $job['id'], (string) $job['run_id'] );
		$totals = $result['totals'];

		if ( null !== $result['total'] ) {
			$totals['total'] = $result['total'];
		}

		return [ $totals, $result['next_cursor'] ];
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
			// `total` はアダプタが報告する全体件数（進捗率の分母）であり、ページ毎に
			// 加算する値ではなく、これまでに報告された最大値を採用する。
			if ( 'total' === $key ) {
				$accumulated['total'] = max( $accumulated['total'] ?? 0, $value );
				continue;
			}

			$accumulated[ $key ] = ( $accumulated[ $key ] ?? 0 ) + $value;
		}

		return $accumulated;
	}

	private function enqueue( int $job_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_HOOK, [ 'job_id' => $job_id ], 'cart-bridge-jp' );
		}
	}

	private function enqueue_delayed( int $job_id, int $delay_seconds ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay_seconds, self::ACTION_HOOK, [ 'job_id' => $job_id ], 'cart-bridge-jp' );
		}
	}
}
