<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;

/**
 * fetch → 書込 → mappings upsert のパイプライン。
 *
 * リモートIDは `CanonicalModel::remote_id()` から取得する（Product/Customer/Coupon/Review は
 * アダプタが `extras['remote_id']` に格納する契約。null の場合はアダプタ実装バグとして例外）。
 *
 * 件数の上限は無い（D27。R3-6a で D15 のサンプル・上限を外した）。全エンティティをカーソル走査する。
 */
final class Importer {

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly Logger $logger = new Logger(),
		private readonly DryRunItemRepository $dry_run_items = new DryRunItemRepository()
	) {}

	/**
	 * カーソル走査エンティティを1ページ処理する。
	 *
	 * @return array{next_cursor:?Cursor,total:?int,totals:array<string,int>}
	 */
	public function run_page(
		PlatformAdapter $adapter,
		WooWriter $writer,
		string $entity,
		Cursor $cursor,
		bool $is_dry_run,
		?int $job_id = null,
		?string $run_id = null
	): array {
		[ $items, $next_cursor, $total ] = $this->fetch_page( $adapter, $entity, $cursor );

		$totals = $this->process_items( $adapter, $writer, $entity, $items, $is_dry_run, $job_id, $run_id );

		return [
			'next_cursor' => $next_cursor,
			'total'       => $total,
			'totals'      => $totals,
		];
	}

	/**
	 * @param array<int,CanonicalModel> $items
	 * @return array<string,int>
	 */
	private function process_items(
		PlatformAdapter $adapter,
		WooWriter $writer,
		string $entity,
		array $items,
		bool $is_dry_run,
		?int $job_id = null,
		?string $run_id = null
	): array {
		$totals = [
			'processed'     => 0,
			'created'       => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'unchanged'     => 0,
			'warned'        => 0,
			'remote_amount' => 0,
		];

		// dry-run実行が処理した各アイテムを1行ずつ`cbjp_dry_run_items`へ記録する（F1-6のCSV
		// レポート用）。ループを抜けてから1回のバッチINSERTでまとめて書き込む
		// （アイテム毎にINSERTするとページ内アイテム数だけクエリが積み重なるため）。
		// remote_id欠損（キーが作れない）は記録の対象外。checksum一致スキップは記録する
		// （下記参照）。
		$dry_run_rows = [];

		$platform = $adapter->id();

		// ページ内アイテムの既存mapping（local_id/checksum）を一括プリロードし、
		// アイテム毎のSELECTを避ける。dry-runでも読み取り専用で使い、新規/更新/スキップを分類する（D16）。
		// `remote_id_of()`はアダプタの契約違反（remote_id欠損）をnullで表す（例外を投げない）。
		// ここで例外を投げると、ページ内の1件だけが契約違反でも`array_map()`がループに入る前に
		// 中断し、下のforeachで確立している「1件の異常データで移行全体を止めない」方針
		// （186行目以降）が、このremote_id解決自体には適用されずページ全体が失敗してしまう。
		$remote_ids = array_map(
			fn( CanonicalModel $item ): ?string => $this->remote_id_of( $item ),
			$items
		);
		$existing   = $this->mappings->find_many( $platform, $entity, array_filter( $remote_ids, static fn ( ?string $id ): bool => null !== $id ) );

		// 種類ごとの部分（検証レポートの金額・dry-run のラベル）はページごとに 1 回だけ引く。
		$type = EntityTypeRegistry::get( $entity );

		foreach ( $items as $index => $item ) {
			++$totals['processed'];

			// 移行後検証レポート（D17）用: この run で ASP から取得したアイテムの金額（受注の種類だけが返す）を、書込の成否・
			// スキップに関わらず全 processed 分で累積する（`JobRepository::empty_totals()`）。
			$totals['remote_amount'] += $this->remote_amount_of( $type, $item, $entity, $job_id );

			$remote_id = $remote_ids[ $index ];

			if ( null === $remote_id ) {
				// アダプタの契約違反（`extras['remote_id']`欠損）。この1件はmappingを解決できず
				// 永続化もできないため、ページ全体を止めずこのアイテムだけをskipped扱いにする
				// （下の`catch`節と同じ「1件の異常データで移行全体を止めない」方針）。
				++$totals['skipped'];
				++$totals['warned'];
				$this->logger->error( "Adapter returned a \"{$entity}\" item without a remote id.", [], $job_id );
				continue;
			}

			$row               = $existing[ $remote_id ] ?? null;
			$existing_local_id = $row['local_id'] ?? null;

			// checksum一致＝変更なしはスキップする（03 §5 冪等性）。
			if ( null !== $row && null !== $row['checksum'] && $row['checksum'] === $item->checksum() ) {
				++$totals['skipped'];
				// unchanged は skipped の内訳で、既に移行済みで変更が無い件数（JobRepository の empty_totals を参照）。
				++$totals['unchanged'];

				// dry-runはCSVレポートを「全量出力」する契約（03 §10.4）のため、この分岐で
				// `continue`するとCSVに当該アイテムの行が一切現れず、再実行（差分なし）の
				// dry-runがほぼ空のCSVになってしまう。validate()は呼ばない（checksum一致を
				// 判定するためだけに毎回全アイテムを再検証すると、このスキップ自体の
				// パフォーマンス上の意味がなくなる）ため、warningsは前回時点のものを再掲せず
				// 空のまま「変更なし」として記録する。
				if ( $is_dry_run ) {
					$dry_run_rows[] = [
						'entity'            => $entity,
						'remote_id'         => $remote_id,
						'label'             => DryRunLabel::for_type( $type, $item ),
						'operation'         => WriteResult::OPERATION_SKIPPED,
						'existing_local_id' => $existing_local_id ?? 0,
						'warnings'          => [],
					];
				}

				continue;
			}

			try {
				$result = $writer->write( $entity, $item, $existing_local_id );
			} catch ( Throwable $exception ) {
				// 1件のアイテムでの例外がページ全体を失敗させると、`JobManager::process_job()`が
				// ジョブを恒久的にfailedへ遷移させ、このページ内の他の正常なアイテムの処理まで
				// 巻き添えになる（このページ手前までの進捗も、ページ自体が例外で完了しなかった
				// ため`update_progress()`に到達せず永続化されない）。1件の異常データで移行全体が
				// 止まらないよう、このアイテムのみskipped扱いにして処理を継続する
				// （local_id 0と同様mappingsは書かないため、次回実行時に再試行される）。
				++$totals['skipped'];
				++$totals['warned'];
				// `Support\Logger`の契約（個人情報禁止ルール。ID以外を含めない）はcontextだけでなく
				// message自体にも及ぶ（`JobManager::process_job()`の同種のcatch節も固定文言のみを
				// 渡し、例外メッセージはログに含めない）。`$exception->getMessage()`は
				// `WC_Data_Exception`等が投げる自由文字列でありワークライター経由で顧客の
				// メールアドレス等の値をそのまま含みうるため、ここでも固定文言＋
				// 例外クラス名（`exception`キー）にとどめる。
				$this->logger->error(
					"Writer threw while processing a {$entity} item.",
					[
						'remote_id' => $remote_id,
						'exception' => $exception::class,
					],
					$job_id
				);

				if ( $is_dry_run ) {
					// この分岐は`try`ブロック内の成功パス（371行目以降）を通らないため、
					// 何もしないとtotals['warned']は加算されるのにCSVレポートには当該アイテムの
					// 行が一切現れない（`warned`件数とCSVの行数が食い違う）。例外詳細は
					// `Support\Logger`と同じ理由でCSVにも含めない（固定コードのみ）。
					$dry_run_rows[] = [
						'entity'            => $entity,
						'remote_id'         => $remote_id,
						'label'             => DryRunLabel::for_type( $type, $item ),
						'operation'         => WriteResult::OPERATION_SKIPPED,
						'existing_local_id' => $existing_local_id ?? 0,
						'warnings'          => [ WarningCode::VALIDATION_EXCEPTION ],
					];
				}

				continue;
			}

			// local_id 0 は「ローカル実体を作成/更新できなかった」ことを表す契約
			// （例: stockの対象商品がまだ未インポート）。checksumを保存すると次回実行時の
			// checksum一致スキップに掛かり永久に再試行できなくなるため、mappingsを書かない。
			// dry-runは`Woo\DryRunRepository`が仕様として常にlocal_id=0でcreated/updatedを返す
			// （何も永続化しないため）ため、この判定の対象外にする。
			$did_persist = ! $is_dry_run && 0 !== $result->local_id;

			if ( $did_persist ) {
				// `$result->fully_resolved`がfalse（category/tag参照・customer_ref等の一部が
				// 未解決のまま実体だけ保存された）の場合はchecksumをキャッシュしない
				// （`MappingRepository::upsert()`はnullをNULLIF経由でSQLのNULLへ変換する）。
				// checksumをキャッシュすると、参照先が後から解決可能になった場合でも
				// 182行目のchecksum一致スキップに永久に掛かり、二度と再試行されなくなる。
				$checksum = $result->fully_resolved ? $item->checksum() : null;

				$this->mappings->upsert( $platform, $entity, $remote_id, $result->local_id, $checksum );

				// ループ開始前に一括プリロードした`$existing`はこのループ内で行われた更新を
				// 反映しない。同一ページ内（アダプタのページング境界バグ等）に同じremote_idの
				// アイテムが複数含まれると、後続のアイテムがこの古いスナップショットを見て
				// 「未作成」と誤認し、別の孤立エンティティを新規作成してしまう
				// （`upsert()`はremote_id単位でON DUPLICATE KEY UPDATEするため、mappingは
				// 最後に処理したエンティティだけを指し、先行するエンティティは孤立して残る）。
				// 直前に確定したlocal_idでこの場で更新し、以後の同一remote_idの再利用に備える。
				$existing[ $remote_id ] = [
					'local_id' => $result->local_id,
					'checksum' => $checksum,
				];
			}

			// この契約は `WooWriter`/`EntityWriter` インターフェース上で型として強制できない
			// （PHPの型システムでは「local_idが0ならoperationはskippedでなければならない」を
			// 表現できない）ため、ここで防御的に正規化する。将来のwriter実装や
			// `cbjp/adapters/register`経由の外部アダプタがlocal_id=0のままcreated/updatedを
			// 返す契約違反を犯しても、totals集計（結果レポート）上は実態どおりskipped扱いになる。
			// dry-runでは`$did_persist`が常にfalseになるため、`! $is_dry_run`を別途チェックして
			// dry-run結果レポートの新規/更新件数が常に0件になることを防ぐ
			// （対象にすると常に0件表示になってしまう）。
			$operation = ( ! $is_dry_run && ! $did_persist ) ? WriteResult::OPERATION_SKIPPED : $result->operation;

			++$totals[ $operation ];

			// D25（issue #98）: エクスポートで結ばれた実体を上書きしなかった結果（local_id 0 で mapping には触れない）。mapping がある実体は
			// 既に結ばれているので、Exporter の同じ扱いと揃えて`unchanged`（`skipped`のうち、既に結ばれていて書かなかった件数）にも数える。
			// 在庫は在庫の mapping が無くても届くので、mapping（`$row`）があるときだけ数える。
			if ( WriteResult::OPERATION_SKIPPED === $operation && null !== $row && WarningCode::indicates_kept_by_link_direction( $result->warnings ) ) {
				++$totals['unchanged'];
			}

			if ( [] !== $result->warnings ) {
				++$totals['warned'];
			}

			if ( $is_dry_run ) {
				$dry_run_rows[] = [
					'entity'            => $entity,
					'remote_id'         => $remote_id,
					'label'             => DryRunLabel::for_type( $type, $item ),
					'operation'         => $operation,
					'existing_local_id' => $existing_local_id ?? 0,
					'warnings'          => $result->warnings,
				];
			}
		}

		if ( $is_dry_run && [] !== $dry_run_rows && null !== $run_id && null !== $job_id ) {
			$this->dry_run_items->insert_many( $run_id, $job_id, $dry_run_rows );
		}

		return $totals;
	}

	/**
	 * @return array{0:array<int,CanonicalModel>,1:?Cursor,2:?int}
	 */
	private function fetch_page( PlatformAdapter $adapter, string $entity, Cursor $cursor ): array {
		$type = EntityTypeRegistry::get( $entity );

		if ( null === $type ) {
			throw new RuntimeException( "Entity \"{$entity}\" is not a cursor-walk entity." );
		}

		$page = $type->fetch_page( $adapter, $cursor );

		return [ $page->items, $page->next_cursor, $page->total ];
	}

	/**
	 * 外部の種類の戻り値・例外は信用しない（原則 8）。1 件の金額が読めなくても、ページ全体を止めず 0 として数える
	 * （例外クラスは記録する。`Support\Logger` の個人情報禁止ルールに従い、メッセージは記録しない）。
	 */
	private function remote_amount_of( ?EntityType $type, CanonicalModel $item, string $entity, ?int $job_id ): int {
		if ( null === $type ) {
			return 0;
		}

		try {
			$amount = $type->remote_amount( $item );
		} catch ( Throwable $exception ) {
			$this->logger->error( "Entity type failed to report the amount of a {$entity} item.", [ 'exception' => $exception::class ], $job_id );

			return 0;
		}

		return is_int( $amount ) ? $amount : 0;
	}

	private function remote_id_of( CanonicalModel $item ): ?string {
		return $item->remote_id();
	}
}
