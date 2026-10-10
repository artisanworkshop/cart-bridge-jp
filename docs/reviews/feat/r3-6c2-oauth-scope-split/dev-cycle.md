# dev-cycle 状態: feat/r3-6c2-oauth-scope-split
- タスク: R3-6c2 OAuth スコープの分割（決め残し 9）。無料版は `read_products write_products` だけを要求し、Pro が有効なときに `read_sales write_sales read_shop_coupons` を足す。付与されたスコープを記録し、足りない接続には再接続を促す
- 開始: 2026-10-10
- 引数: `auto-commit`（ゲートラウンドの確認ゲートを飛ばす）
- PR: 作成後に番号を記入
- 現在のステップ: 3（review-loop）
- Copilot: 未依頼
- Codex: 未依頼

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-10 21:33 | 1 | 計画承認（記録の無い既存の接続は旧版の 5 つのスコープを持つとみなす・記録するのは付与されたスコープ） |
| 2026-10-10 21:45 | 2 | 実装コミット（7e26b6f backend・3e5f2ce frontend/i18n）。`quality.sh` green（無料版 1230・Pro 581・Jest 91）。ガード 14 か所を mutate-check で確認（全て CAUGHT） |
| 2026-10-10 21:49 | 2 | dev サイトで Pro 有効／無効 × トークンの記録 4 通りの REST、画面（接続カード・Import／Export の案内）を確認。トークンは退避して戻した（同一）。docs 更新 |
| 2026-10-10 22:10 | 3 | review-loop R1（自己＋独立 opus）: Critical/High/Medium 0・Low 6・対象外 1 → Low 5 件と対象外 1 件を修正（e0e6e81。push intent の案内・Mappings タブの案内・ログ・docs・rehearse の事前チェック）、1 件を backlog |
