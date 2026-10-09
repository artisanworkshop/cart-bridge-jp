# dev-cycle 状態: chore/wporg-screenshots-skill

- タスク: wordpress.org 用スクリーンショットを撮り直すプロジェクトスキル `/wporg-screenshots` の追加（R3-3 の撮影手順のスキル化。R3-6・R3-4 で使う）
- 開始: 2026-10-09
- PR: 作成後に番号を記入
- 現在のステップ: 2（実装・品質チェック）
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-09 15:29 | 1 | 計画承認（`~/.claude/plans/velvety-skipping-sunrise.md`）。ブランチは開発補助の前例（PR #50）に合わせて `chore/`。自動テストは足さない（wp-env・Docker・Chrome が要り CI で動かない。既存の `mock-adapter.sh`・`rehearse.sh` と同じ）。`.wordpress-org/` の画像は変えない |
| 2026-10-09 15:31 | 2 | 実装コミット `1ceff76`（スキル一式・CLAUDE.md の 1 行）＋ docs（`docs/10` の R3-6 から参照）。品質チェック green（PHPUnit 1767・Jest 104・i18n）。実機: `capture.sh shoot --out <スクラッチパッド>` を 3 回通し、5 枚中 4 枚が R3-3 のコミット済み画像とバイト単位で一致（Import は WooCommerce メニューの `Payments` バッジの差だけ）。`setup.php` は dev サイトと mu-plugin なしの tests サイトで拒否。ブラウザの起動失敗と未定義変数の異常終了（macOS bash 3.2）で終了コード 1・mu-plugin 削除。`composer lint`・`shellcheck` green |
