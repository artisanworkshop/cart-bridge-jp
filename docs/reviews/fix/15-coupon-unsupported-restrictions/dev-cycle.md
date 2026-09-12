# dev-cycle 状態: fix/15-coupon-unsupported-restrictions
- タスク: issue #15 — `CouponWriter` のクーポン制限判定をプラットフォーム非依存化
- 開始: 2026-09-12
- PR: 未作成
- 現在のステップ: 3（review-loop）
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-09-12 | 1 | 計画承認（変換層の除外は維持 / 未宣言は `null`＝不明としてフェイルクローズ） |
| 2026-09-12 | 2 | 実装コミット 2 件（fix / docs）、品質チェック green（PHPUnit 704 tests / 1878 assertions） |
