# dev-cycle 状態: feat/r3-0n-order-dry-run-warnings

- タスク: R3-0n — 受注 dry-run の残りの警告（数量 0 明細・税合計不完全・未解決参照）の原因確定と扱い
- 開始: 2026-09-30
- PR: #90 https://github.com/artisanworkshop/cart-bridge-jp/pull/90
- 現在のステップ: 7（G3 修正済み・最終報告の前）
- Copilot: 依頼 3 回（上限） / 未収束（G1 で 2 件・G2 で 1 件・G3 で 1 件。すべて修正）
- Codex: 依頼 3 回 / 収束（G3 で新規指摘なし）

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-09-30 16:49 | 1 | 計画承認（`~/.claude/plans/wiggly-greeting-muffin.md`）。実データの取得は「dry-run したサイトで WP-CLI」（ユーザー決定）。Phase A（原因確定）の結果を確認してから Phase B の実装に入る |
| 2026-09-30 17:41 | 2 | Phase A 完了: 実店舗のサイトで取得スクリプト（`dist/`、未コミット。GET のみ・個人情報は取得時に伏せ字）を SSH＋WP-CLI で実行し、受注 67・商品 6・顧客 3 件を取得。原因を確定（数量 0＝`product_num=0`・`subtotal_price=0`、うち 18/27 受注はキャンセル受注／`totals` は 2019-09-09 以前の受注で null／未解決の商品 16 行・顧客 2 名は ColorMe で削除済み〈404〉、3 行は variable 商品の軸が後から増えてバリエーションを特定できない／要検証#18: `ids=` は直近 7 日の制限を上書きしない）。**ユーザー決定**: 数量 0・金額 0 の明細は警告なしで数量 0 のまま取り込む／受注の 2 コードの `note` を `reference_unresolved` に変え、バリエーション不一致は新コード `order_line_variation_unresolved` で区別（note なし）／税合計はコード変更なし |
| 2026-09-30 19:21 | 2 | 実装コミット（backend＋テスト＋匿名化した実受注フィクスチャ 2 件／dry-run のテスト追加／docs）。品質チェック green（PHPUnit 1352 件・Jest 55 件）。ミューテーション 11 種すべて CAUGHT。mock（`mockv`、設置したコピーだけ実受注 JSON を `OrderTransformer` に通す）で受注の dry-run → CSV を確認し撤去済み。新コード名は既存のエクスポート方向 `order_line_variation_unresolved` と衝突するため `order_line_variation_unmatched` にした |
| 2026-09-30 19:37 | 3 | review-loop R1（自己レビュー＋独立サブエージェント）: Medium 2 件（テスト・docs に実店舗の顧客 ID 等が残っていた〔独立レビューは High。店舗名・ドメインは main に既出のため Medium〕／`maps_to_variable_product()` の否定側のテスト欠落）を修正、Low 4 件を修正（件数 24→21 の訂正・コメント・D10 の記述）、Low 1 件は対応不要（`memo` キーは元の応答に無い）、対象外 1 件は backlog。実 ID を含むコミットを push しないよう、main からコミットを作り直した（push 前）。quality green（PHPUnit 1353・Jest 55）、ミューテーション 12 種 CAUGHT |
| 2026-09-30 19:43 | 3 | review-loop R2（独立サブエージェントで検証）: **APPROVE**（R1-1・R1-2 解消。R1-2 はミューテーションで実測）。新規 Low 2 件（ゴミ箱の商品の扱いの文言・`OrderReader` のコメントの ColorMe 限定）は文言だけなので修正 |
| 2026-09-30 19:45 | 4〜6 | 初回 push（T=10:45:20Z）→ PR #90 作成 → CI green → Copilot 依頼 1 回目（19:49、timeline で登録確認）。Codex は自動レビューが 5 分で来ず、`--codex-nudge` が review コメントを自動投稿（依頼 1 回目） |
| 2026-09-30 20:08 | 7 | G1: Copilot 2 件（変換層の丸めで数量0の判定より前に不正が失われる〔Copilot は High。Medium と判定〕・オプション値のコメントの矛盾）と Codex 1 件（`'1e-400'` のアンダーフロー）をすべて修正（`e15c424`）。確認ゲート承認後に commit |
| 2026-09-30 20:25 | 7 | G2: CI green → 両 bot へ再依頼（各 2 回目）→ Copilot・Codex が同じ 1 件（G1 で小計に使った `money_or_null()` も小数を切り捨てる）を指摘 → `Cast::exact_money_or_null()` で修正（`8a42a38`）。確認ゲート承認後に commit |
| 2026-09-30 22:31 | 7 | G3（両 bot とも 3 回目）: Codex は新規指摘なし（収束）。Copilot 1 件（JSON の `1e-400` が `json_decode()` で float(0) になり厳密な整数変換を通る）を修正（`ce3f513`）。確認ゲート承認後に commit。4 回目は依頼しない |
