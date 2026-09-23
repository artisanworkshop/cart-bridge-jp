---
paths:
  - "includes/Sync/**"
  - "includes/Woo/Tools/**"
  - "includes/Woo/Export/**"
  - "includes/Woo/Reader/**"
  - "includes/Woo/WarningCode.php"
  - "tests/unit/Sync/**"
  - "tests/unit/Woo/Tools/**"
  - "tests/unit/Woo/Reader/**"
---

# Sync・Export・Tools・mappings の設計上の罠

> 取込み/書出しのパイプライン（`includes/Sync/**`）、Reader/Export、管理ツール（`includes/Woo/Tools/**`。サンプルクリーンアップ・リンク再構築・県コード修復）、`cbjp_mappings`/`cbjp_dry_run_items` を触るときに効く。
> CLAUDE.md の「コーディング規約」「アーキテクチャ原則」と併せて守る。ここは**触るファイルに対応するときだけ読み込まれる**パス指定ルール（`paths` frontmatter）で、内容は CLAUDE.md にあった項目をそのまま移したもの（issue/PR の番号は各項目に残してある）。

- `Sync\Importer::process_items()` は per-itemの警告を**dry-runのときしか永続化しない**（`$is_dry_run` ガード内の `DryRunItemRepository::insert_many()` が唯一の書込経路）。実移行の結果レポートに個別の警告は残らないため、「警告に情報を足せばユーザーが気付ける」という前提の設計は成立しない。またdry-run行は `existing_local_id` 列（`Admin\DryRunReportCsv::HEADER`）を持つので、`WarningCode::with_detail()` を足す前にその情報が既に行に載っていないか確認すること（PR #37 でdetailを追加→冗長と判明し取り消した）
- `Sync\JobManager::filter_and_order_entities()`は`can_fetch_customers=false`のアダプタ（BASE等）では顧客エンティティのジョブ自体を除外し、`Woo\Writer\OrderWriter::apply_customer()`も既存`mappings`の解決のみで新規顧客作成は行わない。受注インポート時に抽出した顧客（`CustomerExtractor`等、D12）を永続化する経路は現状存在しないため、そのようなアダプタを実装する際はImporter/JobManager側にプラットフォーム非依存の新しい拡張点を設計する必要がある（`docs/04-plan-base.md` B4-5参照。issue #26）
- `Sync\JobManager`は`RateLimitExhaustedException`を固定`PAUSED_RESUME_DELAY_SECONDS`（60秒）後に再試行する実装で、日次上限のような長時間（翌日まで等）の再試行遅延を指定する仕組みが無い。1日◯件のような上限を持つASP（BASE等）のexport実装時は、再試行遅延を可変にする拡張点をJobManagerに追加する必要がある（`docs/10-tasks.md` E5-1参照。issue #26）
- 破壊的操作（サンプルクリーンアップ等）のプレビュー件数は、実行側と**同じ判定関数**で算出すること。mapping 行数をそのまま出すと、他プラットフォーム所有・削除済み・親削除でカスケードする variation・共有画像の分が実行結果とズレる（PR #34 のボットゲートで G1-3/12・G2-2・G3-2 と 3 ラウンド連続で同種の指摘を受けた。`SampleCleanup::can_delete_entity()` を preview/run で共用する構成を参照）
- `_cbjp_platform` は email 突合による採用で別プラットフォームに書き換わる**可変**の所有メタ。「誰が作成したか」の判定にはこれを使わず、作成時にのみ書く不変マーカー `_cbjp_created_by_import`（値は作成プラットフォームID）を使うこと。可変メタで判定すると採用→リンク解除の後に作成元が削除できなくなる（G1-4/G2-1/G3-1）。**逆に「インポートが書いたデータを補正・更新するツール」の所有判定には `_cbjp_platform`（最後に書いたプラットフォーム）を使う**: `CustomerWriter` は採用した既存アカウントにも住所を書くため、不変マーカーで絞ると採用アカウントの誤りが直らない（`PrefStateRepair`。Codex/Copilot が不変マーカーへの絞り込みを繰り返し誤提案した。issue #46）
- `cbjp_mappings` を減らす・リセットする経路（クリーンアップ等）は、対応する実体を削除できない状況（権限不足等）では実行自体を拒否すること。unlink だけして mappings とサンプルセットを消すと `LimitPolicy` の累積カウントが消え、無料版上限（アーキテクチャ原則 7）を回避してデータを増やし続けられる（G1-11）
- エクスポート方向の `cbjp_dry_run_items`/`cbjp_mappings` は列の意味を読み替えて使う（スキーマ変更なし）。`cbjp_mappings` は `local_id`/`remote_id` とも方向を持たない設計だが、export方向は起点が常にWooローカルID（`MappingRepository::find_remote_id()`/`find_many_by_local_ids()` で逆引きする）。`cbjp_dry_run_items.remote_id`（`NOT NULL`・`UNIQUE(job_id, entity, remote_id)`）は新規作成候補（既存remote_idが無い）の行では一意性確保のためプレースホルダ `local:{local_id}` を入れ、`existing_local_id` 列にWooローカルIDを格納する（`Sync\Exporter::dry_run_row()` 参照）
- `cbjp_mappings.checksum` は `CHAR(64)`固定長（生のsha256 hex digest専用）で、export/import等どちらか一方の書込みに文字列プレフィックスを付けて名前空間を分けるような拡張はできない（MySQLが黙って切り詰める）。同じ`(platform, entity_type, remote_id)`行を複数の書込元が共有し、かつ「生ハッシュとして同じ値でも意味が異なる」場合は、`hash('sha256', 名前空間文字列 . $item->canonical_json())`のようにハッシュ対象（入力）側に名前空間を混ぜ込むこと（出力は引き続き64文字に収まる。`Sync\Exporter::export_checksum()`参照。issue発覚: E2-2 R1でimport/exportが同じchecksum列を生ハッシュのまま共有し、一方が他方の変更検知を破壊しかけた）
- `MappingRepository::upsert()`は`(platform, entity_type, remote_id)`のユニークキーで`ON DUPLICATE KEY UPDATE`するため、同じlocal_idに対してアダプタが異なるremote_idを返した場合（例: リモート側で削除された実体をupdate時に再作成した）、新remote_idで別行がINSERTされるだけで旧remote_idの行が孤児として残る。`find_remote_id()`/`find_many_by_local_ids()`はid昇順の最初の行を採用するため、以後は削除済みremote_idへ永久に再送し続け、無料版の累計カウントも余分に消費する。remote_idが変わったことを検知したら`upsert()`の前に`delete_one()`で旧行を消すこと（`Sync\Exporter::process_items()`参照。PR #40 G3）
- `Woo\WarningCode::indicates_export_blocking()`へ新しい警告コードを追加する前に、その警告が「フレッシュな環境でも既定で発火するか」を確認すること。`PRICES_INCLUDE_TAX_DISABLED`（`woocommerce_prices_include_tax=no`）は多くの実店舗の既定設定で、blocking化すると無料版の挙動確認自体ができなくなる店舗が続出する（`JobManagerExportTest`が実際に2件壊れて発覚。issue #43）。少数の境界条件でしか発火しない警告（`PRODUCT_PRICE_INVALID`等）とは扱いを分けること
- Writer側の変換（例: `AddressMapper`）を直しても、Canonicalが**変換前の生の値**を保持している限りchecksumは変わらず、`Importer`のchecksum一致スキップにより**取込み済みデータは再インポートで直らない**。Woo側に生の値が残らないと修正前後のデータは値だけで判別できず（入れ替え・巡回は一律の再適用が二重適用になる）、是正は「ASPから権威の値を再取得し、現在値が旧バグの出力と一致する場合に限り書き換える」ツールで行う（`Woo\Tools\PrefStateRepair`、issue #46）
- 新しい警告コードを定義しても、それを消費すべき既存の判定関数（`Woo\WarningCode::indicates_export_blocking()`等）への登録が漏れることがある。`ORDER_LINE_TAX_CLASS_UNSUPPORTED`（非課税・zero-rate等）は定義済みだったが`indicates_export_blocking()`に未登録のまま残っており、対応するASPの税区分と異なる税額で受注が恒久的に作成されうる状態だった（Codex・Copilotが独立に同一箇所を指摘。issue #45）。新規コードを定義した際は、それが本来どの既存判定関数の対象であるべきかを確認し、類似コードと横並びで登録すること
- `Importer`/`Exporter`/`JobManager`/`SampleSelector` は mapping・無料版上限・サンプルのキーを**登録キーではなく `$adapter->id()`** から決める（`Importer` は `$adapter->id()` を `$platform` に使う）。`AdapterRegistry` は `id()` と登録キー（`cbjp/adapters/register` の配列キー）の一致を検証しないため、別キーで登録するモック・外部アダプタが `id()` を合わせないと、書込み用 Writer は登録キー・mapping は `id()` の名前空間、と黙ってズレる（`MockPlatformAdapter` の `platform_id` 引数で合わせる。PR #50 Codex 指摘）
- `JobManager::start_run()` は `has_active_job_for_platform()` で同一プラットフォームの同時実行を防ぐが、`JobManager::retry()` はこのガードを経由せず、対象ジョブが `failed` であることしか見ずにrequeueする。別タブ/別セッションから同一プラットフォームの別runが進行中でも、失敗ジョブのRetryが素通りし二重書き込みを招きうる（E2-4 PR #53 G2/G3、Codex/Copilot指摘。issue #54）。修正時は `has_active_job_for_platform()` をそのまま `retry()` に足すだけでは不十分な点に注意: `start_run()` は対象runの全エンティティジョブを最初に `pending` で作成するため、失敗した1ジョブの**同じrunの兄弟ジョブ**も「進行中」として誤検知し、正当な同一run内のRetryまでブロックしてしまう。ガードは `run_id` も考慮する必要がある
