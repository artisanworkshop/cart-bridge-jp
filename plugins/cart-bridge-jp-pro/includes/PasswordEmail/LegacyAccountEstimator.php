<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

use CartBridgeJP\Pro\Woo\Writer\CustomerWriter;
use CartBridgeJP\Sync\JobHistory;
use WP_User_Query;

/**
 * 0.1.0 の取込みで作ったアカウントの推定（R3-10。ユーザー決定 2026-10-13）。0.1.0 の `CustomerWriter` は作成の印（`_cbjp_created_by_import`。F1-7 から）を
 * 書かなかったので、印の無いアカウントのうち、紐づけの印（`_cbjp_platform`・`_cbjp_remote_id`）があり、登録日時（`user_registered`）がそのプラットフォームの
 * 0.1.0 の顧客の取込みジョブの実行中に入るものを「0.1.0 の取込みで作った」と推定する。取込みより前からあったアカウント（メールの一致で採用した）は登録日時が窓の外になる。
 *
 * 使うジョブ（窓）は 0.1.0 のものだけ: totals に F1-7 で足した `remote_amount` が無く（0.1.0 の `empty_totals()`。続きを今の版が処理したジョブには既定値がマージされて付く）、
 * 状態が completed か failed で孤立の回復のエラーでなく（取消・孤立の回復・Retry は今の版が `updated_at` を今にして窓を広げる）、作った件数が 1 以上のもの。
 * どれかのプラットフォームで、推定した件数が窓の作った件数の合計を超えたら、推定をすべて捨てる（窓の取り違えの安全網。フェイルクローズ）。
 *
 * **推定はパスワード設定メールの送る相手の判定にだけ使い、作成の印は書かない**（R3-5b の保護〔プロフィールとメールを書くのは作ったアカウントだけ〕を緩めない）。
 * 送り先はアカウントの今のメールなので、推定を誤っても乗っ取りの経路にはならない。
 */
final class LegacyAccountEstimator {

	/**
	 * 推定するアカウントの上限（推定は画面に一覧で出し、開始のときに控える。0.1.0 は既定で顧客を 10 件しか取り込まなかったので、ふつうはずっと少ない）。
	 * 超えたら推定しない。
	 */
	public const MAX_ACCOUNTS = 5000;

	/** 0.1.0 から変わらないジョブの状態（無料版の `JobRepository::STATUS_*`）。 */
	private const FINISHED_STATUSES = [ 'completed', 'failed' ];

	/** 今の版が後から失敗にしたジョブ（無料版の `OrphanedRunRecovery::ERROR_*`。`updated_at` が回復の時刻）。 */
	private const RECOVERY_ERRORS = [ 'action_lost', 'fatal_error' ];

	private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/';

	private const CHUNK = 500;

	/**
	 * プラットフォームごとの窓と作った件数のメモ（1 回の推定の中だけ）。
	 *
	 * @var array<string,array{windows:list<array{0:string,1:string}>,created:int}>
	 */
	private array $platforms = [];

	public function __construct( private readonly JobHistory $history ) {}

	public static function create(): self {
		return new self( new JobHistory() );
	}

	/**
	 * 推定したアカウントの ID（昇順）と、安全網で捨てたか。
	 *
	 * @return array{ids:list<int>,discarded:bool}
	 */
	public function estimate(): array {
		$this->platforms = [];
		$by_platform     = [];
		$page            = 1;

		do {
			$query = new WP_User_Query(
				[
					'fields'      => [ 'ID', 'user_registered' ],
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'number'      => self::CHUNK,
					'paged'       => $page,
					'count_total' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者の操作・1 回の推定で、紐づけの印を持つユーザーだけを読む。
					'meta_query'  => [
						'relation' => 'AND',
						[
							'key'     => '_cbjp_remote_id',
							'compare' => 'EXISTS',
						],
						[
							'relation' => 'OR',
							[
								'key'     => CustomerWriter::CREATED_BY_IMPORT_META,
								'compare' => 'NOT EXISTS',
							],
							[
								'key'   => CustomerWriter::CREATED_BY_IMPORT_META,
								'value' => '',
							],
						],
					],
				]
			);
			$rows      = $query->get_results();
			$chunk_ids = array_map( static fn ( $row ): int => (int) $row->ID, $rows );

			// メタは 1 回のクエリで温め、読み終えたら捨てる（全員を読むときのメモリを抑える。R1-1）。
			update_meta_cache( 'user', $chunk_ids );

			foreach ( $rows as $row ) {
				$user_id  = (int) $row->ID;
				$platform = get_user_meta( $user_id, '_cbjp_platform', true );
				$remote   = get_user_meta( $user_id, '_cbjp_remote_id', true );

				if ( ! is_string( $platform ) || '' === $platform || ! is_string( $remote ) || '' === $remote ) {
					continue;
				}

				if ( $this->registered_in_window( $platform, (string) $row->user_registered ) ) {
					$by_platform[ $platform ][] = $user_id;
				}
			}

			foreach ( $chunk_ids as $user_id ) {
				wp_cache_delete( $user_id, 'user_meta' );
			}

			++$page;
			$read = count( $rows );
		} while ( self::CHUNK === $read );

		$ids = [];

		foreach ( $by_platform as $platform => $platform_ids ) {
			// 窓で作った件数より多いアカウントが窓に入るなら、窓の取り違え（後から触られた行など）。推定をすべて捨てる。
			if ( count( $platform_ids ) > $this->platform( $platform )['created'] ) {
				return [
					'ids'       => [],
					'discarded' => true,
				];
			}

			$ids = array_merge( $ids, $platform_ids );
		}

		if ( count( $ids ) > self::MAX_ACCOUNTS ) {
			return [
				'ids'       => [],
				'discarded' => true,
			];
		}

		sort( $ids );

		return [
			'ids'       => $ids,
			'discarded' => false,
		];
	}

	/**
	 * 登録日時（UTC の `Y-m-d H:i:s`）がそのプラットフォームの窓のどれかに入るか。両端を含む。同じ書式の文字列のまま比べる（タイムゾーンの設定に依存しない）。
	 */
	private function registered_in_window( string $platform, string $registered ): bool {
		if ( 1 !== preg_match( self::DATETIME_PATTERN, $registered ) ) {
			return false;
		}

		foreach ( $this->platform( $platform )['windows'] as [ $start, $end ] ) {
			if ( strcmp( $start, $registered ) <= 0 && strcmp( $registered, $end ) <= 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{windows:list<array{0:string,1:string}>,created:int}
	 */
	private function platform( string $platform ): array {
		if ( isset( $this->platforms[ $platform ] ) ) {
			return $this->platforms[ $platform ];
		}

		$windows = [];
		$created = 0;

		foreach ( $this->history->jobs( 'import', $platform, 'customer' ) as $job ) {
			$job_created = $job['totals']['created'] ?? null;

			if ( array_key_exists( 'remote_amount', $job['totals'] )
				|| ! in_array( $job['status'], self::FINISHED_STATUSES, true )
				|| in_array( $job['error_code'], self::RECOVERY_ERRORS, true )
				|| ! is_int( $job_created ) || $job_created < 1
				|| 1 !== preg_match( self::DATETIME_PATTERN, $job['created_at'] )
				|| 1 !== preg_match( self::DATETIME_PATTERN, $job['updated_at'] )
				|| strcmp( $job['created_at'], $job['updated_at'] ) > 0
			) {
				continue;
			}

			$windows[] = [ $job['created_at'], $job['updated_at'] ];
			$created  += $job_created;
		}

		$this->platforms[ $platform ] = [
			'windows' => $windows,
			'created' => $created,
		];

		return $this->platforms[ $platform ];
	}
}
