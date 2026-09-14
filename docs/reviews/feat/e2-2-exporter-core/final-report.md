# dev-cycle 最終報告: feat/e2-2-exporter-core

## 開発内容

- **タスク**: E2-2「Exporter パイプライン」（`docs/10-tasks.md`）の PR-A ぶん — Exporter コア配線
  （`Sync\Exporter`/`PlatformWriter`/`WooReader`）+ `ProductReader`。SKU/email突合upsert・
  dry-run・無料版サンプル上限・本番書込み警告（D17）・`RestController` の `type=export` 501解除。
  `CustomerReader`/`OrderReader`/`StockReader`/`CouponReader` は PR-B（別PR）へ分割済み。
- **PR**: [#40](https://github.com/artisanworkshop/cart-bridge-jp/pull/40)
- **承認された計画の要約**（`/Users/shoheitanaka/.claude/plans/steady-exploring-parasol.md`）:
  - 「SKU/email突合」= `cbjp_mappings` の有無のみで create/update を判定（ASP側への投機的な
    SKU/email検索はしない。D16の誤リンク不採用方針と整合。ユーザー確認済み）
  - `LimitPolicy` の累積カウンタは import/export で共有（`cbjp_mappings` の行は方向を持たない）
  - `cbjp_dry_run_items`/`cbjp_mappings` はスキーマ変更せず列の意味を読み替えて export に転用
  - エクスポート対象エンティティは capability で機械的に絞る（PR-A は product のみ実装、
    実際の絞り込みロジックは PHPStan の到達不能コード指摘を受けてシンプルな `in_array` に変更）
  - Woo カテゴリは `category_map`（Woo→ASP、E2-1導入）で解決、タグは v1.0 では転送しない
  - 受注の決済/配送/ステータスの曖昧性解決は PR-B（`OrderReader`）へ先送り（D19）
  - `Adapters\PushResult` に `fully_resolved`（デフォルトtrue）を末尾追加
  - 本番書込み警告（D17）: `POST /runs`（`type=export`）は `acknowledge_production_write`
    （`true`/`'1'`/`'true'`のみ受理、フェイルクローズ）が真であることを要求
- **コミット一覧**（`main..feat/e2-2-exporter-core`、17件）:

| sha | 種別 | 内容 |
|---|---|---|
| b006acd | feat | Exporter コア配線 + ProductReader（backend + tests） |
| 359a1ff | docs | PR-A設計・実装ノート |
| ff6ea45 | fix | R1指摘の修正（checksum・価格・在庫） |
| 01864b3 | docs | R1レビュー結果の記録 |
| 028181a | fix | R2: variable商品の可視バリエーション0件時のTypeErrorクラッシュ防止 |
| 654aef4 | docs | R2レビュー結果の記録 |
| 3589fb9 | docs | R3: 古いバリエーション価格ガイダンス・コメントの修正 |
| 7d4bedc | docs | R3承認・ラウンドHEAD確定 |
| 45809b7 | docs | PR #40作成の記録 |
| 174253b | docs | CI green・ボットレビュー依頼の記録 |
| 3a83bc6 | fix | G1: Copilot指摘（レート制限例外・非string警告・operation検証）修正 |
| c72781f | docs | G1ゲートラウンド記録 |
| 1f7e44b | docs | G2ボット再依頼の記録 |
| de40abb | fix | G2: 非公開/価格不正バリエーション除外・軸超過警告 |
| a0c76a7 | docs | G2ゲートラウンド記録 |
| 11e88aa | docs | G3ボット再依頼の記録 |
| 12c608a | fix | G3: pushブロック・孤児mapping行修正・税警告・サンプル補完修正 |
| 8dff630 | docs | G3ゲートラウンド記録 |

## 設計ドキュメントからの逸脱（計画で要判断とした事項の実装結果）

- `docs/03-design-decisions.md` §10.2 に「エクスポート方向の実装（E2-2 PR-A）」節を新設し、
  上記の設計判断（capability絞り込みの簡略化・checksum名前空間混ぜ込み・dry_run_items列読替え等）
  を記録済み。
- `cbjp_mappings.checksum`（`CHAR(64)`固定長）は import/export の生ハッシュをそのまま共有すると
  誤った一致判定・上書きが起きうることが R1 で判明したため、`Exporter` 側のハッシュ入力に
  固定名前空間文字列を混ぜ込む方式（`Exporter::export_checksum()`）に変更（CLAUDE.md記載済み）。
- `docs/10-tasks.md` の E2-3 行に、本PRのレビューで判明した2件の必須対応
  （バリエーションremote_id永続化経路が無い／複数リクエストpushの部分完了契約が無い）を記録。

## review-loop（PR前レビュー）

| ラウンド | 指摘 | 修正 | backlog送り | 判定 |
|---|---|---|---|---|
| R1 | H1〜H7・M1〜M8（独立サブエージェント併用） | H1-H6, M1-M4, M6-M7 | H7, M5, M8（PR-B/backlog） | CHANGES REQUESTED→修正完了 |
| R2 | 新規Critical1・High1（R1修正が誘発） | 2件とも修正 | - | 新規なし |
| R3（最終検証） | 新規Critical/Highゼロ確認 | Medium1・Low4をその場で修正 | - | **APPROVE** |

詳細: `docs/reviews/feat/e2-2-exporter-core/R1.md` / `R2.md` / `R3.md`

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留/backlog | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot（依頼1回目） | 4（インライン3+本文1） | 4 | 0 | 未収束 |
| G1 | Codex | 自動レビューTIMEOUT | - | - | 未依頼（`@codex review`で再依頼、依頼回数は1回目として計上） |
| G2 | Copilot（依頼2回目） | 6（インライン2+本文4） | 3 | 3（backlog） | 未収束 |
| G2 | Codex（依頼1回目） | 4（インライン4） | 2 | 2（backlog） | 未収束 |
| G3 | Copilot（依頼3回目・上限到達） | 3（インライン1+本文2） | 2 | 1（部分対応・backlog） | 3回目で上限到達 |
| G3 | Codex（依頼2回目） | 4（インライン4） | 3 | 1（設計課題として申し送り） | 未収束のまま4回目の依頼はしない |

詳細: `docs/reviews/feat/e2-2-exporter-core/G1.md` / `G2.md` / `G3.md`

Copilot が G3 で `cbj-dev-cycle` の依頼上限（bot毎に最大3回）に到達したため、新規指摘の有無に
関わらずこれ以上のレビュー依頼は行っていない。Codex も未収束のまま2回目で止めている（本ラウンドの
修正は push・CI確認済みだが、4回目の依頼はしない、というスキルの規定に従った）。

### 修正した指摘（主なもの。全件は各 G*.md 参照）

| ID | bot | 重大度 | 内容 | コミット |
|---|---|---|---|---|
| G1-1 | Copilot | High | `RateLimitExhaustedException`が汎用catchで握り潰されジョブ一時停止が機能しない | 3a83bc6 |
| G1-2 | Copilot | High | `PushResult::$warnings`の非string要素で`TypeError` | 3a83bc6 |
| G1-3 | Copilot | High | 未知operationでもmappingsへ永続化されてしまう矛盾 | 3a83bc6 |
| G2-1 | Codex+Copilot | High | 3軸目以降のバリエーション属性を無警告で切り詰め | de40abb |
| G2-2 | Codex | High | 非公開バリエーションが販売可能として復活しうる | de40abb |
| G2-3 | Copilot | High | 全バリエーション除外時にvariable→simple化するリスク | de40abb |
| G2-4/5/6 | Copilot | High | 価格の数値・非負検証漏れ（variants/simple/variable） | de40abb |
| G3-1 | Copilot | High | `ALL_VARIATIONS_EXCLUDED`が警告のみでpush自体を止めない | 12c608a |
| G3-3 | Codex | Medium | `tax_status`（非課税等）が無警告で失われる | 12c608a |
| G3-4 | Codex | High | remote_id変化時に旧mapping行が孤児として残る | 12c608a |
| G3-6 | Copilot | High | サンプルが完全に空のまま永久に再選定されない | 12c608a |
| G3-7 | Copilot | Medium | exportサンプルクリーンアップのテスト欠落 | 12c608a |

### 修正しなかった指摘（PR上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G2-7 | Codex | Medium | Pro版フルカタログのoffsetページング頑健性。無料版サンプル上限では到達しない経路 | [#discussion_r4001676743](https://github.com/artisanworkshop/cart-bridge-jp/pull/40#discussion_r4001676743) |
| G2-8 | Codex | Medium | `RateLimitExhaustedException`再スロー時の部分totals過小報告。設計変更が要る | [#discussion_r4001676748](https://github.com/artisanworkshop/cart-bridge-jp/pull/40#discussion_r4001676748) |
| G2-9 | Copilot | Low | `variants()`内`wc_get_product()`のN+1。既存の同種指摘と同基準で保留 | 本文指摘・スレッドなし |
| G3-2 | Codex | High（部分対応） | 税抜/税込基準不一致。警告は追加したが実際の税額換算は別スコープ | [#discussion_r4001792473](https://github.com/artisanworkshop/cart-bridge-jp/pull/40#discussion_r4001792473) |
| G3-5 | Codex | High | 複数リクエストpushの部分完了契約が無い。E2-3の設計課題として申し送り | [#discussion_r4001792484](https://github.com/artisanworkshop/cart-bridge-jp/pull/40#discussion_r4001792484) |

いずれも `docs/review-backlog.md`（`e2-2-exporter-core/*`）または `docs/03-design-decisions.md`
「E2-3/PR-Bへの申し送り」に記録済み。

## 品質ゲート

- CI: [run 34800300944](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/34800300944) green（JS/TS, PHP quality 8.2/8.3, PHPUnit(wp-env)）
- 品質チェック最終結果: `composer lint` green / `composer analyze`（118ファイル）green /
  `composer test:wpenv`（774件・2096アサーション）green / `npm run lint` green / `npm run build` green

## 完了条件の確認（計画時点の完了条件）

- ✅ product の dry-run（`type=dry_run_export`）が Woo→Canonical変換とプレビュー行記録まで通る
- ✅ `type=export` が `JobManager`→`Exporter`→`push_product()` まで配線され、ColorMe では
  `UnsupportedOperationException` が1件failedとして記録されジョブが落ちない
- ✅ 無料版サンプル上限（Woo側最新受注10件起点）が product に適用される
- ✅ 品質チェックgreen / review-loop APPROVE / CI green / Codex・Copilotゲート（上限到達まで実施）

## 次にできること（人間の判断）

- 保留分の修正: `/dev-cycle fix G2-7 G2-8 G2-9 G3-2 G3-5` または `/fix-copilot-review 40`
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
- PR-B（`CustomerReader`/`OrderReader`/`StockReader`/`CouponReader`）の着手
- E2-3（ColorMe `push_*`実装）着手時に必ず参照すべき申し送り事項（`docs/03-design-decisions.md`
  §10.2「E2-3/PR-Bへの申し送り」）:
  - バリエーションのremote_id永続化経路の設計
  - 複数リクエストpushの部分完了契約の設計（G3-5）
  - 税抜/税込の実際の換算方式の設計（G3-2）
