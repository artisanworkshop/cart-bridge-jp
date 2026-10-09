<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\PlatformBusyException;
use CartBridgeJP\Support\PlatformLock;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Woo\Export\AdapterPlatformWriterFactory;
use CartBridgeJP\Woo\WooReaderRepositoryFactory;
use CartBridgeJP\Woo\WooRepositoryFactory;
use RuntimeException;
use Throwable;

/**
 * 移行実行（run）の開始とジョブ進行を管理する。
 * 1アクション（`process_job()` 1回）= 1ページ処理。Action Scheduler経由で自己再エンキューする。
 */
final class JobManager {

	public const ACTION_HOOK = 'cbjp_process_job';

	/**
	 * `process_job()` のアクションの Action Scheduler グループ（`JobRepository::has_in_flight_job_for_platform()` が照会する）。
	 */
	public const ACTION_GROUP = 'cart-bridge-jp';

	public const TYPE_DRY_RUN        = 'dry_run';
	public const TYPE_IMPORT         = 'import';
	public const TYPE_EXPORT         = 'export';
	public const TYPE_DRY_RUN_EXPORT = 'dry_run_export';

	/**
	 * レート制限枯渇で paused にしたジョブを再開するまでの待機秒数。
	 */
	private const PAUSED_RESUME_DELAY_SECONDS = 60;

	public function __construct(
		private readonly JobRepository $jobs,
		private readonly Importer $importer,
		private readonly WooWriterFactory $writer_factory,
		private readonly Logger $logger = new Logger(),
		private readonly PlatformLock $lock = new PlatformLock()
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
			new Importer( $mappings ),
			$writer_factory ?? new WooRepositoryFactory()
		);
	}

	/**
	 * 同時実行の判定からジョブの作成・先頭ジョブの開始までを、プラットフォーム単位のロックで囲む（issue #57。
	 * ほぼ同時に届いた 2 つの開始が、互いの作成前の状態を見て両方とも判定を通らないように）。
	 *
	 * @param array<int,string> $entities
	 *
	 * @throws RunAlreadyInProgressException 同一プラットフォームで進行中（pending/running/paused）のジョブ、または
	 *   ページを処理中のアクション（キャンセル直後の run を含む）がある場合。
	 * @throws PlatformBusyException 同一プラットフォームで別の操作が判定〜状態変更の区間を実行中の場合。
	 * @throws RuntimeException 未登録プラットフォーム、または対応エンティティが1つもない場合。
	 */
	public function start_run( string $type, string $platform, array $entities ): string {
		$known_types = [ self::TYPE_DRY_RUN, self::TYPE_IMPORT, self::TYPE_EXPORT, self::TYPE_DRY_RUN_EXPORT ];

		if ( ! in_array( $type, $known_types, true ) ) {
			throw new RuntimeException( "Unknown run type: {$type}" );
		}

		return $this->lock->run(
			$platform,
			PlatformLock::TTL_SHORT,
			fn (): string => $this->start_run_locked( $type, $platform, $entities )
		);
	}

	/**
	 * @param array<int,string> $entities
	 */
	private function start_run_locked( string $type, string $platform, array $entities ): string {
		if ( $this->jobs->is_platform_busy( $platform ) ) {
			throw new RunAlreadyInProgressException( $platform );
		}

		$adapter = AdapterRegistry::get( $platform );

		if ( null === $adapter ) {
			throw new RuntimeException( "Unknown platform: {$platform}" );
		}

		// 実行順と能力の判定は実体の種類が持つ（`Entities\EntityType::position()`・`supports_import()`/`supports_export()`。R3-6b1）。
		// 登録の無い種類・この接続先で扱えない種類は黙って外す。種類の判定は外部の種類の例外を「非対応」として握るので、アダプタの
		// 能力の読み取りの失敗は先にここで外へ出す（以前と同じく run を始めない。要求した種類が黙って外れた run にしない）。
		$adapter->capabilities();

		$is_export_type   = in_array( $type, [ self::TYPE_EXPORT, self::TYPE_DRY_RUN_EXPORT ], true );
		$ordered_entities = $is_export_type
			? EntityTypeRegistry::exportable( $adapter, $entities )
			: EntityTypeRegistry::importable( $adapter, $entities );

		if ( [] === $ordered_entities ) {
			throw new RuntimeException( 'No supported entities to run.' );
		}

		$run_id  = wp_generate_uuid4();
		$job_ids = [];

		foreach ( $ordered_entities as $entity ) {
			$job_ids[] = $this->jobs->create( $run_id, $type, $platform, $entity );
		}

		$first_job_id = $job_ids[0];

		// 作成した直後に（`GET /runs` で見つけた別のタブから）キャンセルされていれば始めない。キャンセルが
		// ジョブの作成の途中に届くと、その後に作ったジョブは pending のまま残り、進まない run がプラットフォームを
		// 塞ぐので、run の残りもキャンセルする（遷移の失敗が DB エラーだった場合も、始められない run を残さない）。
		if ( $this->jobs->transition( $first_job_id, [ JobRepository::STATUS_PENDING ], JobRepository::STATUS_RUNNING ) ) {
			$this->enqueue( $first_job_id );
		} else {
			$this->jobs->cancel_run( $run_id );
		}

		return $run_id;
	}

	/**
	 * 失敗ジョブを pending に戻し、Action Scheduler に再エンキューする。判定から状態変更までは
	 * `start_run()` と同じロックで囲む（issue #57）。
	 *
	 * @return bool 対象ジョブが存在し、failed だった場合のみ true。同じジョブへの Retry がほぼ同時に
	 *   2 回届いた場合、`failed → pending` の遷移に勝った 1 回だけが true になる（二重にエンキューしない）。
	 * @throws RunAlreadyInProgressException 対象ジョブとは異なる run が同一プラットフォームで
	 *   進行中（pending/running/paused）の場合、またはページを処理中のアクションがある場合。`start_run()`と
	 *   同じ例外・同じ理由（レート制限保護・二重書き込み防止）だが、進行中のジョブの判定からは対象ジョブ自身の
	 *   runを除外する（同一run内の未処理な兄弟ジョブまで「進行中」と誤検知して正当なRetryをブロックしないため）。
	 * @throws PlatformBusyException 同一プラットフォームで別の操作が判定〜状態変更の区間を実行中の場合。
	 */
	public function retry( int $job_id ): bool {
		$job = $this->jobs->find( $job_id );

		if ( null === $job || JobRepository::STATUS_FAILED !== $job['status'] ) {
			return false;
		}

		// platform・run_id はジョブの作成後に変わらないため、ロックの外で読んだ値を使ってよい。
		$platform = (string) $job['platform'];
		$run_id   = (string) $job['run_id'];

		return $this->lock->run(
			$platform,
			PlatformLock::TTL_SHORT,
			function () use ( $job_id, $platform, $run_id ): bool {
				if ( $this->jobs->is_platform_busy( $platform, $run_id ) ) {
					throw new RunAlreadyInProgressException( $platform );
				}

				if ( ! $this->jobs->transition( $job_id, [ JobRepository::STATUS_FAILED ], JobRepository::STATUS_PENDING ) ) {
					return false;
				}

				$this->enqueue( $job_id );

				return true;
			}
		);
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

		// 読んでから遷移するまでの間にキャンセルされていれば何もしない（`cancelled` を `running` で上書きしない）。
		// pending/paused のジョブへのアクションが重複していても、遷移に勝った 1 本だけが処理する。
		if ( in_array( $job['status'], [ JobRepository::STATUS_PENDING, JobRepository::STATUS_PAUSED ], true )
			&& ! $this->jobs->transition( $job_id, [ JobRepository::STATUS_PENDING, JobRepository::STATUS_PAUSED ], JobRepository::STATUS_RUNNING ) ) {
			return;
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

		$is_export_type = in_array( $job['type'], [ self::TYPE_EXPORT, self::TYPE_DRY_RUN_EXPORT ], true );
		$is_dry_run     = in_array( $job['type'], [ self::TYPE_DRY_RUN, self::TYPE_DRY_RUN_EXPORT ], true );
		$entity         = $job['entity'];

		try {
			// `for_platform()`はwriter組み立て（Writerクラス群のnew）であり現状は例外を投げないが、
			// 将来的に検証等が加わって例外を投げるようになった場合でも、この呼び出し全体を
			// 下のcatchで確実に拾い`mark_failed()`させるため、tryの外に出さない
			// （tryの外に置くと、例外発生時にジョブがmark_failed()もされずSTATUS_RUNNINGのまま
			// 停止してしまう）。
			if ( $is_export_type ) {
				$platform_writer               = $is_dry_run ? $this->platform_writer_factory()->for_dry_run( $adapter ) : $this->platform_writer_factory()->for_platform( $adapter );
				$reader                        = $this->reader_factory()->for_platform( $job['platform'] );
				[ $page_totals, $next_cursor ] = $this->process_export_page( $adapter, $platform_writer, $reader, $entity, $job, $is_dry_run );
			} else {
				$writer                        = $is_dry_run ? $this->writer_factory->for_dry_run( $job['platform'] ) : $this->writer_factory->for_platform( $job['platform'] );
				[ $page_totals, $next_cursor ] = $this->process_page( $adapter, $writer, $entity, $job, $is_dry_run );
			}
		} catch ( RateLimitExhaustedException ) {
			// レート制限の長期枯渇は一時停止して後で再開する（03 §3 ステートマシン / §4 RateLimiter）。
			// ページの処理中にキャンセルされていれば、`paused` で上書きせず再開もしない。
			if ( ! $this->jobs->transition( $job_id, [ JobRepository::STATUS_RUNNING ], JobRepository::STATUS_PAUSED ) ) {
				return;
			}

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
		$cursor = Cursor::from_json( $job['cursor_json'] );
		$result = $this->importer->run_page( $adapter, $writer, $entity, $cursor, $is_dry_run, (int) $job['id'], (string) $job['run_id'] );
		$totals = $result['totals'];

		if ( null !== $result['total'] ) {
			$totals['total'] = $result['total'];
		}

		return [ $totals, $result['next_cursor'] ];
	}

	/**
	 * ページの処理中にキャンセルされた（`running` でなくなった）ジョブは `completed` で上書きせず、次のジョブも
	 * 始めない（issue #57。キャンセルした run が復活しないように）。次のジョブは `pending` のときだけ始める
	 * （既に `running`/`paused` なら、別のアクションが扱っているので二重にエンキューしない）。
	 */
	private function complete_job_and_advance( int $job_id, string $run_id ): void {
		if ( ! $this->jobs->transition( $job_id, [ JobRepository::STATUS_RUNNING ], JobRepository::STATUS_COMPLETED ) ) {
			return;
		}

		$next_job = $this->jobs->find_next_incomplete_for_run( $run_id );

		if ( null === $next_job ) {
			return;
		}

		if ( $this->jobs->transition( (int) $next_job['id'], [ JobRepository::STATUS_PENDING ], JobRepository::STATUS_RUNNING ) ) {
			$this->enqueue( (int) $next_job['id'] );
		}
	}

	private function exporter(): Exporter {
		return new Exporter( new MappingRepository() );
	}

	private function platform_writer_factory(): PlatformWriterFactory {
		return new AdapterPlatformWriterFactory();
	}

	private function reader_factory(): WooReaderFactory {
		return new WooReaderRepositoryFactory();
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array{0:array<string,int>,1:?Cursor}
	 */
	private function process_export_page( PlatformAdapter $adapter, PlatformWriter $writer, WooReader $reader, string $entity, array $job, bool $is_dry_run ): array {
		$cursor = Cursor::from_json( $job['cursor_json'] );
		$result = $this->exporter()->run_page( $adapter, $writer, $reader, $entity, $cursor, $is_dry_run, (int) $job['id'], (string) $job['run_id'] );
		$totals = $result['totals'];

		if ( null !== $result['total'] ) {
			$totals['total'] = $result['total'];
		}

		return [ $totals, $result['next_cursor'] ];
	}

	/**
	 * @return array{total:int,processed:int,created:int,updated:int,skipped:int,unchanged:int,warned:int,failed:int,remote_amount:int}
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
			as_enqueue_async_action( self::ACTION_HOOK, [ 'job_id' => $job_id ], self::ACTION_GROUP );
		}
	}

	private function enqueue_delayed( int $job_id, int $delay_seconds ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay_seconds, self::ACTION_HOOK, [ 'job_id' => $job_id ], self::ACTION_GROUP );
		}
	}
}
