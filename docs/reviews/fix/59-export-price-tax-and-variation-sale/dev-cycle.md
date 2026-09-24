# dev-cycle 状態: fix/59-export-price-tax-and-variation-sale
- タスク: issue #59（税抜入力店舗で税抜価格を税込として換算して push する）+ issue #60（バリエーションのセール価格を運べず定価で push する）
- 開始: 2026-09-24
- PR: 未作成
- 現在のステップ: 4（push・PR 作成）
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-09-24 | 0 | issue #59 / #60 を起票。main から `fix/59-export-price-tax-and-variation-sale` を作成、wp-env 起動（8895/8896） |
| 2026-09-24 | 1 | 計画承認（plan file: `curried-crafting-moler.md`） |
| 2026-09-24 | 2 | 実測: 顧客ロケーション経由の `wc_get_price_including_tax(999)` は無変換の 999.0、`WC_Tax::get_base_tax_rates()` は 10% を 1 件返す（→1099）。軽減 8% は 999→1079。基準所在地を US:CA にすると `get_base_tax_rates` は空・税区分には税率 1 件登録済み（フェイルクローズ対象） |
| 2026-09-24 | 2 | 実装コミット 2 件（9863f11 実装+テスト / 708b860 docs）、品質チェック green（PHPUnit 1105 件） |
| 2026-09-24 | 3 | review-loop R1: 独立サブエージェント（opus）Critical/High なし、Medium 2 件（テストのトートロジー R1-1 / セール終了日を運べない R1-2）・Low 5 件・対象外 2 件。Medium 2 件と Low 3 件を修正（07c3931）、Low 2 件と対象外 2 件は backlog |
| 2026-09-24 | 3 | review-loop R2: 独立サブエージェントが R1 指摘の全解消を確認（ミューテーション実測含む）、新規指摘なし。APPROVE。PHPUnit 1109 件 green |
