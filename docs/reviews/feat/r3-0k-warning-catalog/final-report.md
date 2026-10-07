# dev-cycle 最終報告: feat/r3-0k-warning-catalog

## 開発内容

- タスク: **R3-0k — 警告カタログと dry-run CSV の説明列**（`docs/10-tasks.md`）
- PR: #108 https://github.com/artisanworkshop/cart-bridge-jp/pull/108
- 承認された計画の要約: 新設 `Woo\WarningCatalog` が `WarningCode` の全 98 個を、重大度（`blocking`／`action_required`／`info`、カタログに無いコードは `unknown`）・原因・対処で説明し、dry-run の CSV の末尾に `severity`・`message`・`action` の 3 列を足す。同じコードでも取込みとエクスポートで意味が違うので「コード × 向き」で引き、向きは run の種別から決める。CSV はユーザーの言語で書く。文言は英語の `__()`（日本語は R3-2）
- 計画時のユーザー回答: Q1 CSV に `severity` も足す、Q2 98 個すべてに個別の原因を書く（対処は店舗が何かできるものだけ）
- 各コードの実際の挙動（書くか・代替値・detail の中身・dry-run に出るか）は、発生元のグループごとに 4 つのサブエージェントで調べてから文言を書いた

### コミット

- `b633856` feat: describe warning codes in the dry-run CSV (R3-0k)
- `8e98350` test: pin that the CSV report describes warnings in the run's direction
- `bbf39b7` docs: record the warning catalog (R3-0k) in the design docs and task ledger
- `ab094ba` fix: correct warning catalog texts and pin the locale switch (R1)
- `590de0a` docs: record review-loop R1 for R3-0k
- `a8e787f` fix: restore the self-fetch hint for retryable image failures (R2)
- `75c65fa` docs: record review-loop R2 for R3-0k
- `b72a173` fix: widen three catalog causes to every path that emits them (G1)
- `0a5dde6` docs: record dev-cycle gate round 1
- `2f46fca` fix: say that an imported coupon keeps working without new restrictions (G2)
- `bf4fd7c` docs: record dev-cycle gate round 2
- （この最終報告の記録コミット）

### 設計ドキュメントからの逸脱

1. CSV に `severity` 列も足した（台帳は `message`・`action` の 2 列。Q1）
2. 98 個すべてに個別の原因を書いた（台帳は「約 25 種＋残りは汎用文言」。台帳を書いた時点の 52 個から増えていた。Q2）
3. `docs/03` D22 の小節の項目 4「案内文言は `WarningCode` の docblock に書く。CSV にコードの説明文は無い」をカタログ前提に改めた
4. カタログを「コード × 向き」で引く（同じコードが取込みとエクスポートで意味が違うため）
5. CSV をユーザーの言語で書く（従来は翻訳する文字列が無かった）
6. 不明なコードの重大度は `unknown`（原則 9）
7. `VARIATION_STOCK_MANAGEMENT_MIXED` は v1.0 の同梱アダプタ（ColorMe）で止まるので `blocking`（バリエーション単位で在庫管理できるアダプタを足すときに見直す。backlog `R1-L3`）

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント。98 個を発生元と照合） | Medium 11（独立の Low のうちテスト欠落 1 件と成果物の文言の事実誤りを Medium に変更）／Low 3／対象外 2 | 11 | 5（R1-L1〜L3・R1-X1・R1-X2） |
| R2（独立サブエージェントで検証。ミューテーション・ランダム順込み） | R1 の 11 件すべて解消。新規 Medium 1／Low 3 | 4（Low 3 件も数行で修正） | 0 |

実装中の調査で見つかった既存の挙動は backlog `r3-0k-warning-catalog/plan-X1〜X3・plan-L1` に記録した（設定次第の警告の checksum、顧客を含めない受注のエクスポート、商品価格の更新・非公開カテゴリーの参照、detail・行の重複）。

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（PR 作成時の自動レビューが 5 分応答せず `bot-wait.sh` が再依頼を投稿 →「Didn't find any major issues」） |
| G1 | Copilot | 3（Medium） | 3 | 0 | 未収束 |
| G2 | Copilot | 1（本文の Previously missed。Medium） | 1 | 0 | 未収束 |
| G3 | Copilot | 0 | 0 | 0 | 収束（依頼の上限 3 回） |

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | `tax_rates_not_configured` を軽減税率専用と決めつけていた（外部アダプタの任意の税区分でも出る）→「その税区分に正しい JP の税率を」 | `b72a173` | [r4201734124](https://github.com/artisanworkshop/cart-bridge-jp/pull/108#discussion_r4201734124) |
| G1-2 | Copilot | Medium | 取込みの数量の警告を「0 以下」と決めつけていた（欠損・整数でない場合も 1 になる） | `b72a173` | [r4201734181](https://github.com/artisanworkshop/cart-bridge-jp/pull/108#discussion_r4201734181) |
| G1-3 | Copilot | Medium | `order_status_unknown` は取込みに使えない `checkout-draft` でも出る →「未登録、または取込みに使えない」 | `b72a173` | [r4201734220](https://github.com/artisanworkshop/cart-bridge-jp/pull/108#discussion_r4201734220) |
| G2-B1 | Copilot | Medium | 取込み済みのクーポンは制限が付いても無効化されず使えるまま残ることを、制限の警告 2 つに明記 | `2f46fca` | なし（[review 5436754905](https://github.com/artisanworkshop/cart-bridge-jp/pull/108#pullrequestreview-5436754905)） |

### 修正しなかった指摘

なし（PR 上の未解決スレッドは 0 件）

## 品質ゲート

- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37562118292 green（HEAD `bf4fd7c`。PHPUnit〔wp-env〕・PHP quality 8.2／8.3・JS/TS・Dev tooling）
- 品質チェック: green（PHPUnit 1703 件〔main から +20〕・Jest 87 件・lint・PHPStan・build）
- `mutate-check.sh`: 実装 10 種＋R1 3 種がすべて CAUGHT
- 実機: wp-env の dev サイトで mock（`mockv`）の取込み・エクスポートの dry-run → CSV を実 HTTP で取得（列・向き・detail・BOM・ユーザーの言語）→ 撤去して検証前の状態に戻したことを確認

## マージ前に確認してほしいこと

- **Copilot の総評（G2・G3）**: 「税・価格・クーポン制限を含む 98 警告の対処案内は、業務仕様を把握した担当者による最終確認が必要」。文言は発生元のコードを読んで書き、独立レビュー・ゲートで事実誤りを直したが、店舗向けの言い回しとして適切かは人の目で確かめてほしい（特に `blocking` の警告の対処、税・価格・クーポンの案内）。`includes/Woo/WarningCatalog.php` に全文がある
- 文言は英語のみ。日本語訳は次の R3-2（i18n）で `languages/` に入れる
- backlog に送った既存の挙動のうち、要判断のもの: `plan-X1`（設定次第の警告で checksum を保存する）、`plan-X2`（顧客を含めずに受注をエクスポートするとゲスト受注のまま結べない）、`R1-X1`（再試行対象の警告を持つ受注は取込みのたびに作り直され、手修正が消える）

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- その後は台帳の順序どおり R3-2（i18n）。カタログの文言（約 200 文字列）も翻訳の対象になる
