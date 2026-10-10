# dev-cycle 状態: feat/r3-6c2-oauth-scope-split
- タスク: R3-6c2 OAuth スコープの分割（決め残し 9）。無料版は `read_products write_products` だけを要求し、Pro が有効なときに `read_sales write_sales read_shop_coupons` を足す。付与されたスコープを記録し、足りない接続には再接続を促す
- 開始: 2026-10-10
- 引数: `auto-commit`（ゲートラウンドの確認ゲートを飛ばす）
- PR: #118 https://github.com/artisanworkshop/cart-bridge-jp/pull/118
- 現在のステップ: 7（G1 の修正を push。G2 を依頼する）
- Copilot: 依頼 1 回 / 未収束（G1 で 2 件）
- Codex: 依頼 1 回 / 未収束（G1 で 1 件）

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-10 21:33 | 1 | 計画承認（記録の無い既存の接続は旧版の 5 つのスコープを持つとみなす・記録するのは付与されたスコープ） |
| 2026-10-10 21:45 | 2 | 実装コミット（7e26b6f backend・3e5f2ce frontend/i18n）。`quality.sh` green（無料版 1230・Pro 581・Jest 91）。ガード 14 か所を mutate-check で確認（全て CAUGHT） |
| 2026-10-10 21:49 | 2 | dev サイトで Pro 有効／無効 × トークンの記録 4 通りの REST、画面（接続カード・Import／Export の案内）を確認。トークンは退避して戻した（同一）。docs 更新 |
| 2026-10-10 22:10 | 3 | review-loop R1（自己＋独立 opus）: Critical/High/Medium 0・Low 6・対象外 1 → Low 5 件と対象外 1 件を修正（e0e6e81。push intent の案内・Mappings タブの案内・ログ・docs・rehearse の事前チェック）、1 件を backlog |
| 2026-10-10 22:23 | 3 | review-loop R2（独立 opus の検証）: R1 の全件解消（ミューテーションで実測）・新規 Critical/High 0 → APPROVE。新規 Low 2 件（ログのガードのテスト・409 の文言）を修正（fa72d36） |
| 2026-10-10 22:24 | 4 | push（dd7bd92。T=2026-10-10T13:24:02Z）→ PR #118 作成 |
| 2026-10-10 22:27 | 5〜6 | CI 全ジョブ green → Copilot へ依頼（timeline で登録を確認）。Codex は自動レビューが 5 分で応答せず review コメントを自動投稿 |
| 2026-10-10 22:37 | 7 | G1: Copilot 2 件（交換時の要求スコープ・OAuth でない接続先）・Codex 1 件（後から登録された拡張が Pro のスコープを消す。R1-3 の再指摘）→ 3 件とも修正（32678ba・ce60c2d。auto-commit） |
