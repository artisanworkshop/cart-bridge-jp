<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\WarningCode;
use Throwable;

/**
 * 読出（`WooReader`）→ push（`PlatformWriter`）→ mappings upsert のパイプライン
 * （`Importer` のASP向け対称形）。プラットフォーム固有の分岐は持たない（アーキテクチャ原則1）。
 *
 * 「SKU/email突合」（E2-2タスク文言）の実装範囲: ASP側APIへの投機的なSKU/email検索は行わず、
 * `cbjp_mappings`（local_id起点の逆引き）の有無のみでcreate/updateを判定する（D16の
 * 「SKU/email突合による誤リンクは不採用」方針と整合。計画承認済み）。
 *
 * `cbjp_mappings.checksum`はimport/export双方が同じ`(platform, entity_type, remote_id)`行を
 * 共有する（`docs/03-design-decisions.md` §10.2「import/export共有」）。しかし
 * `Importer`が書くchecksumはASP側`CanonicalModel`のハッシュ、`Exporter`が書くのはWoo側
 * `CanonicalModel`のハッシュであり、同じ実体でも一致しない別物である。素朴に同じ値として
 * 比較すると、一度でも同じ行を両方向が触った実体（例: importで作られWooで購入されたため
 * exportのサンプルにも選ばれた商品）で、以後のimportが「ASP側は変わっていないのに
 * checksum不一致」と誤判定し、`ProductWriter::write()`がユーザーのWoo側手動編集を無条件に
 * 上書きし続けてしまう（逆方向も同様）。`cbjp_mappings.checksum`は`CHAR(64)`固定長
 * （生のsha256 hex digest専用でマイグレーション無しに拡張できない）のため、`Importer`の
 * 生ハッシュへ単純な文字列プレフィックスを付ける方式は取れない。代わりに`Exporter`が書く
 * checksumは`canonical_json()`をハッシュする**前**に固定の名前空間文字列を混ぜ込み
 * （`hash('sha256', CHECKSUM_NAMESPACE . $item->canonical_json())`）、出力は引き続き
 * 64文字のsha256 hex digestに収まる。`Importer`側は一切変更せずにこの衝突を避ける
 * （どちらのchecksumも「自分が最後に書いたものと一致するか」だけを見るため、相手方向の
 * 生ハッシュとは構造的に一致しなくなり、安全側＝再同期に倒れる。SHA-256の衝突耐性に依拠する）。
 */
final class Exporter {

	/**
	 * `export_checksum()`がハッシュ対象に混ぜ込む名前空間文字列。上記docblock参照。
	 * テストが期待値を組み立てられるようpublicにする。
	 */
	public const CHECKSUM_NAMESPACE = 'cbjp-export:';

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly Logger $logger = new Logger(),
		private readonly DryRunItemRepository $dry_run_items = new DryRunItemRepository()
	) {}

	/**
	 * カーソル走査エンティティを1ページ処理する。`$only_local_ids`が渡された場合は
	 * そのWooローカルIDのみを対象にする（無料版サンプル選定。D15 §10.2 #8。
	 * 呼び出し側=JobManagerがサンプル対象を解決する）。
	 *
	 * @param array<int,int>|null $only_local_ids
	 * @return array{next_cursor:?Cursor,total:?int,totals:array<string,int>}
	 */
	public function run_page(
		PlatformAdapter $adapter,
		PlatformWriter $writer,
		WooReader $reader,
		string $entity,
		Cursor $cursor,
		bool $is_dry_run,
		?LimitPolicy $limit_policy = null,
		?array $only_local_ids = null,
		?int $job_id = null,
		?string $run_id = null
	): array {
		$page = $reader->read( $entity, $cursor, $only_local_ids );

		$totals = $this->process_items( $adapter, $writer, $entity, $page->items, $is_dry_run, $limit_policy, $job_id, $run_id );

		return [
			'next_cursor' => $page->next_cursor,
			'total'       => $page->total,
			'totals'      => $totals,
		];
	}

	/**
	 * @param array<int,ReadItem> $items
	 * @return array<string,int>
	 */
	private function process_items(
		PlatformAdapter $adapter,
		PlatformWriter $writer,
		string $entity,
		array $items,
		bool $is_dry_run,
		?LimitPolicy $limit_policy,
		?int $job_id = null,
		?string $run_id = null
	): array {
		$totals = [
			'processed'     => 0,
			'created'       => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'warned'        => 0,
			'remote_amount' => 0,
		];

		$dry_run_rows = [];
		$platform     = $adapter->id();

		// ページ内アイテムの既存mapping（remote_id/checksum）を一括プリロードする
		// （`Importer::process_items()`の逆方向、同じ理由）。
		$local_ids = array_map( static fn ( ReadItem $read_item ): int => $read_item->local_id, $items );
		$existing  = $this->mappings->find_many_by_local_ids( $platform, $entity, $local_ids );

		// 残枠はページ開始時に一度だけ解決する（`Importer`と同じ理由）。
		$remaining = ( null !== $limit_policy ) ? $limit_policy->remaining( $platform, $entity ) : null;

		foreach ( $items as $read_item ) {
			++$totals['processed'];

			$local_id           = $read_item->local_id;
			$item               = $read_item->item;
			$row                = $existing[ $local_id ] ?? null;
			$existing_remote_id = $row['remote_id'] ?? null;

			// checksum一致＝変更なしはスキップする（03 §5 冪等性。読出時点の警告
			// （`$read_item->warnings`）はchecksumの対象外のため、警告の有無だけでは
			// スキップ判定を変えない）。`export_checksum()`の名前空間混ぜ込みにより、importが
			// 最後に書いた生ハッシュとは構造的に一致しないため、import/export混在時は安全側
			// （再送）に倒れる（クラスdocblock参照）。
			// Copilot指摘（PR #40, G3）: `ALL_VARIATIONS_EXCLUDED`等はReadItemの警告に積むだけでは
			// 実際のpush自体を止めない。`CanonicalModel`が実体を正しく表現できない状態のまま
			// `$writer->write()`へ渡すと、変換先（E2-3以降）で構造が破壊されうる
			// （variants=[]のvariable商品がsimple商品としてpushされ、remote側の既存
			// バリエーションが失われる等）。フェイルクローズし、pushせずskipped扱いで
			// 警告を残す（dry-runレポートにも通常どおり反映される）。
			if ( WarningCode::indicates_export_blocking( $read_item->warnings ) ) {
				++$totals['skipped'];
				++$totals['warned'];

				if ( $is_dry_run ) {
					$dry_run_rows[] = $this->dry_run_row( $entity, $local_id, $existing_remote_id, DryRunLabel::for_entity( $entity, $item ), PushResult::OPERATION_SKIPPED, $read_item->warnings );
				}

				continue;
			}

			if ( null !== $row && null !== $row['checksum'] && self::export_checksum( $item ) === $row['checksum'] ) {
				++$totals['skipped'];

				// Copilot指摘（PR #40）: checksum一致でスキップしても`$read_item->warnings`
				// （`VARIATION_STOCK_SHARED_WITH_PARENT`等、warningsはchecksum対象外のため
				// データが変わらない限り毎回同じ内容になる）はwarnedカウント・dry-run行の両方に
				// 引き続き反映する。反映しないと、一度警告が出たアイテムがchecksum安定後は
				// 警告そのものが二度と結果に現れなくなり、実際には解消していない問題を
				// 「解消済み」であるかのように見せてしまう。
				if ( [] !== $read_item->warnings ) {
					++$totals['warned'];
				}

				if ( $is_dry_run ) {
					$dry_run_rows[] = $this->dry_run_row( $entity, $local_id, $existing_remote_id, DryRunLabel::for_entity( $entity, $item ), PushResult::OPERATION_SKIPPED, $read_item->warnings );
				}

				continue;
			}

			$consumed_quota_slot = false;

			if ( ! $is_dry_run && null === $existing_remote_id && null !== $remaining ) {
				if ( $remaining <= 0 ) {
					++$totals['skipped'];
					continue;
				}

				--$remaining;
				$consumed_quota_slot = true;
			}

			try {
				$result = $writer->write( $entity, $item, $existing_remote_id );
			} catch ( RateLimitExhaustedException $exception ) {
				// Copilot指摘（PR #40）: レート制限は「このアイテムだけの異常」ではなく
				// ジョブ全体を一時停止して後で再開すべきシグナル（`JobManager::process_job()`の
				// 専用catch。03 §3 ステートマシン）。`Importer`は該当するASP APIコール
				// （`fetch_page()`）がこの1件tryの外側にあるため元々この問題が起きないが、
				// exportは`push_*()`がアイテムごとに呼ばれるためここで拾ってしまうと、
				// レート制限に達した以降の全アイテム（このページ・後続ページとも）が
				// 「1件ずつ失敗」として握り潰され、`JobManager`の一時停止・再開が機能しなくなる。
				// 下の汎用`Throwable`catchより先に拾い、そのまま再送出する。
				throw $exception;
			} catch ( Throwable $exception ) {
				// `Importer::process_items()`と同じ方針: 1件の異常（capability未対応の
				// `UnsupportedOperationException`を含む）でページ全体を失敗させない。
				// PR-A時点のColorMeは`push_*`が全てこの例外を投げるため、実行(非dry-run)の
				// exportジョブは全件がここを通りskipped/warned扱いで完了する（ジョブ自体は
				// STATUS_FAILEDにならない）のが現状の期待動作（E2-3で解消）。
				if ( $consumed_quota_slot ) {
					++$remaining;
				}

				++$totals['skipped'];
				++$totals['warned'];
				// Support\Loggerの個人情報禁止ルールに従い、固定文言と例外クラス名のみ記録する。
				$this->logger->error(
					"PlatformWriter threw while pushing a {$entity} item.",
					[
						'local_id'  => $local_id,
						'exception' => $exception::class,
					],
					$job_id
				);

				if ( $is_dry_run ) {
					$dry_run_rows[] = $this->dry_run_row( $entity, $local_id, $existing_remote_id, DryRunLabel::for_entity( $entity, $item ), PushResult::OPERATION_SKIPPED, array_merge( $read_item->warnings, [ WarningCode::VALIDATION_EXCEPTION ] ) );
				}

				continue;
			}

			// $resultはWoo\Export\AdapterPlatformWriter経由でcbjp/adapters/registerが登録した
			// 外部アダプタのpush_*()が直接返す（importのWriteResultと異なり、Woo内部コードを
			// 経由しない信頼境界そのもの。アーキテクチャ原則8）。`$operation`/`$warnings`の
			// 型宣言はdocblock上の契約でしかなく実行時に強制されないため、まず正規化してから使う
			// （Copilot指摘, PR #40）: `$warnings`に非string要素が混じっていると、この後の
			// `WarningCode::indicates_unresolved_reference()`/`split()`が`explode()`に
			// 非string値を渡し`TypeError`でページ全体を落としかねない。
			$sanitized_result_warnings = array_values( array_filter( $result->warnings, 'is_string' ) );

			// 未知のoperationはskippedへフェイルクローズする（既知の3値以外がtotalsの
			// 配列キーに使われると集計が破損するため）。
			$operation = $result->operation;

			if ( ! in_array( $operation, [ PushResult::OPERATION_CREATED, PushResult::OPERATION_UPDATED, PushResult::OPERATION_SKIPPED ], true ) ) {
				$operation = PushResult::OPERATION_SKIPPED;
			}

			// remote_idが空文字列、または`$operation`が実質的にcreated/updatedでない場合は
			// 「実際にpushされなかった」ことを表す契約（`Importer`のlocal_id===0と同じ役割。
			// dry-runは`DryRunPlatformWriter`の仕様として常に空文字列/既存remote_idを返し
			// 何も永続化しないため、この判定の対象外にする）。Copilot指摘（PR #40）:
			// `$operation`を検証する前のremote_idのみでの判定だと、非空remote_id＋未知
			// operationを返す契約違反アダプタに対し、`$operation`をskippedへ正規化した後でも
			// mappingsへは既にupsertしてしまっていた（「skippedと報告したのに実は永続化した」
			// という矛盾）。正規化後の`$operation`も判定条件に含める。
			$did_push = ! $is_dry_run
				&& '' !== $result->remote_id
				&& in_array( $operation, [ PushResult::OPERATION_CREATED, PushResult::OPERATION_UPDATED ], true );

			if ( $did_push ) {
				$all_warnings   = array_merge( $read_item->warnings, $sanitized_result_warnings );
				$fully_resolved = $read_item->fully_resolved && ! WarningCode::indicates_unresolved_reference( $all_warnings );

				// `Importer`と同じ理由: 未解決参照（category_map欠落等）が残る場合はchecksumを
				// キャッシュせず、解決可能になった時点で再試行させる。
				$checksum = $fully_resolved ? self::export_checksum( $item ) : null;

				// Codex指摘（PR #40, G3）: アダプタが既存remote_idと異なる新しいremote_idを
				// 返す場合がある（例: リモート側で削除された実体をupdate時に再作成した）。
				// `upsert()`のユニークキー（platform, entity_type, remote_id）は新remote_idで
				// 別行をINSERTするだけで、旧remote_idの行は孤児として残る。
				// `find_many_by_local_ids()`/`find_remote_id()`はid昇順の最初の行（＝古い方）を
				// 採用するため、以後のエクスポートは永久に削除済みのremote_idへ再送し続け、
				// 重複行が無料版の累計カウント（`LimitPolicy`）も押し上げてしまう。
				// 新しいremote_idをupsertする前に旧行を削除する。
				if ( null !== $existing_remote_id && $existing_remote_id !== $result->remote_id ) {
					$this->mappings->delete_one( $platform, $entity, $existing_remote_id );
				}

				$this->mappings->upsert( $platform, $entity, $result->remote_id, $local_id, $checksum );

				// `Importer`と同じ理由: 直前に確定したremote_idでこの場のスナップショットも
				// 更新し、同一ページ内の以後の処理に反映する。
				$existing[ $local_id ] = [
					'remote_id' => $result->remote_id,
					'checksum'  => $checksum,
				];
			} else {
				if ( $consumed_quota_slot ) {
					++$remaining;
				}

				// dry-runを除き、実際にpushされなかった結果はtotals集計上もskipped扱いに倒す
				// （`$operation`が正規化済みでもcreated/updatedのままの場合があるため）。
				if ( ! $is_dry_run ) {
					$operation = PushResult::OPERATION_SKIPPED;
				}
			}

			++$totals[ $operation ];

			$warnings = array_merge( $read_item->warnings, $sanitized_result_warnings );

			if ( [] !== $warnings ) {
				++$totals['warned'];
			}

			if ( $is_dry_run ) {
				$dry_run_rows[] = $this->dry_run_row( $entity, $local_id, $existing_remote_id, DryRunLabel::for_entity( $entity, $item ), $operation, $warnings );
			}
		}

		if ( $is_dry_run && [] !== $dry_run_rows && null !== $run_id && null !== $job_id ) {
			$this->dry_run_items->insert_many( $run_id, $job_id, $dry_run_rows );
		}

		return $totals;
	}

	/**
	 * export方向のchecksum。`CHECKSUM_NAMESPACE`をハッシュ対象に混ぜ込むことで
	 * `CanonicalModel::checksum()`（生のsha256）とは異なる64文字のsha256 hex digestになる
	 * （クラスdocblock参照）。テストが期待値を組み立てられるようpublic staticにする。
	 */
	public static function export_checksum( CanonicalModel $item ): string {
		return hash( 'sha256', self::CHECKSUM_NAMESPACE . $item->canonical_json() );
	}

	/**
	 * `cbjp_dry_run_items`の行を組み立てる。`remote_id`列（NOT NULL・
	 * `UNIQUE(job_id, entity, remote_id)`）は既存の紐付けがあればそのremote_id、新規作成候補は
	 * 一意性確保のためのプレースホルダ`local:{local_id}`を入れる（マイグレーション不要で
	 * export方向を表現するための列読み替え。`docs/03-design-decisions.md` §10.2参照）。
	 * `existing_local_id`列にはWooローカルID（エクスポートでは常に既知）を格納する。
	 *
	 * @param array<int,string> $warnings
	 * @return array{entity:string,remote_id:string,label:string,operation:string,existing_local_id:int,warnings:array<int,string>}
	 */
	private function dry_run_row( string $entity, int $local_id, ?string $existing_remote_id, string $label, string $operation, array $warnings ): array {
		return [
			'entity'            => $entity,
			'remote_id'         => $existing_remote_id ?? "local:{$local_id}",
			'label'             => $label,
			'operation'         => $operation,
			'existing_local_id' => $local_id,
			'warnings'          => $warnings,
		];
	}
}
