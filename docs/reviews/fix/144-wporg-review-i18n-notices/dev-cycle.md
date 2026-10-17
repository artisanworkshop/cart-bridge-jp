# dev-cycle 状態: fix/144-wporg-review-i18n-notices

- タスク: issue #144 — wordpress.org の初回レビュー（2026-10-16）への対応: `load_plugin_textdomain()` を外す、配布物に翻訳を同梱しない、前提条件の通知を「プラグイン」画面と自画面に絞る（ガイドライン 11）
- 開始: 2026-10-17
- PR: 作成後に番号を記入
- 現在のステップ: 3（review-loop）
- Copilot: 未依頼
- Codex: 未依頼

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-17 09:54 | 1 | 計画承認（`~/.claude/plans/synthetic-enchanting-abelson.md`）。ユーザー決定: 配布 zip から `languages/` を外し、`.mo`・`.l10n.php`・JSON はリポジトリからも消し、POT と `ja.po` は translate.wordpress.org への取り込みの元として残す。前提条件の通知 3 つは「プラグイン」画面と Cart Bridge JP の画面だけに出す |
| 2026-10-17 10:04 | 2 | 実装コミット 2 件（`5d0a331` 翻訳を同梱しない・`a7e7166` 前提条件の通知を 2 画面に）＋ docs。品質チェック green（無料版 PHPUnit 1409 件・Pro 1005 件・Jest 111 件）。ガードの変異 8 件をすべて検出（3 つの通知の画面の判定・sodium の判定・画面の 2 条件・Domain Path・`load_plugin_textdomain()`・Assets のパス）。`bin/plugin-check-zip.sh --build`: ERROR 0、警告は §10.9 の DB の 4 種だけ、zip に `languages/` なし。dev サイトで言語パックを模擬（`ja.po` から作った `.l10n.php`・JSON を `wp-content/languages/plugins/` に一時的に置く）: PHP の `__()` と管理画面の JS の訳が日本語になり、外すと英語に戻ることを確認（置いたファイルは削除済み） |
