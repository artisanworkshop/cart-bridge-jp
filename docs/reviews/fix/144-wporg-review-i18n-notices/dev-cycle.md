# dev-cycle 状態: fix/144-wporg-review-i18n-notices

- タスク: issue #144 — wordpress.org の初回レビュー（2026-10-16）への対応: `load_plugin_textdomain()` を外す、配布物に翻訳を同梱しない、前提条件の通知を「プラグイン」画面と自画面に絞る（ガイドライン 11）
- 開始: 2026-10-17
- PR: #145 https://github.com/artisanworkshop/cart-bridge-jp/pull/145
- 現在のステップ: 7〜8（G3 の修正の CI 待ち → 最終報告）
- Copilot: 依頼 3 回 / 上限（G3 で本文の新規 2 件。修正 2。4 回目は依頼しない）
- Codex: 依頼 1 回 / 収束（G1 で Didn't find any major issues）

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-17 09:54 | 1 | 計画承認（`~/.claude/plans/synthetic-enchanting-abelson.md`）。ユーザー決定: 配布 zip から `languages/` を外し、`.mo`・`.l10n.php`・JSON はリポジトリからも消し、POT と `ja.po` は translate.wordpress.org への取り込みの元として残す。前提条件の通知 3 つは「プラグイン」画面と Cart Bridge JP の画面だけに出す |
| 2026-10-17 10:04 | 2 | 実装コミット 2 件（`5d0a331` 翻訳を同梱しない・`a7e7166` 前提条件の通知を 2 画面に）＋ docs。品質チェック green（無料版 PHPUnit 1409 件・Pro 1005 件・Jest 111 件）。ガードの変異 8 件をすべて検出（3 つの通知の画面の判定・sodium の判定・画面の 2 条件・Domain Path・`load_plugin_textdomain()`・Assets のパス）。`bin/plugin-check-zip.sh --build`: ERROR 0、警告は §10.9 の DB の 4 種だけ、zip に `languages/` なし。dev サイトで言語パックを模擬（`ja.po` から作った `.l10n.php`・JSON を `wp-content/languages/plugins/` に一時的に置く）: PHP の `__()` と管理画面の JS の訳が日本語になり、外すと英語に戻ることを確認（置いたファイルは削除済み） |
| 2026-10-17 10:21 | 3 | review-loop R1（自己レビュー＋独立サブエージェント）: Critical/High/Medium 0 件で APPROVE。Low 6 件のうち 5 件を修正（`1dd7a2f` Pro の画面を出さない画面に足す・sodium の入口のテスト、`39a8f8e` docs/03 §7 の補足・docs/10 R5-1/R7-1 の `make-json`・Pro の docblock）、R1-L3 と対象外 2 件は backlog。quality green（無料版 PHPUnit 1410 件・Pro 1005 件・Jest 111 件） |
| 2026-10-17 10:24 | 3 | review-loop R2（独立サブエージェントの検証。R1-L1・L2 は変異を `--only` で確認）: **APPROVE**（R1 の修正済み 5 件が解消・新規 0 件）。backlog の文言の細部を直した |
| 2026-10-17 10:25 | 4〜5 | 初回 push（T=2026-10-17T01:24:58Z）→ PR #145 → CI green → Copilot 依頼（timeline で登録確認。PR 作成時にも自動で登録されていた）。Codex は自動レビューが 5 分で来ず `--codex-nudge` が依頼 |
| 2026-10-17 11:16 | 7 | G1: Codex は Didn't find any major issues（収束）。Copilot は 🔵 の本文 2 件（インライン 0）: G1-B1（テストの網羅 = R1-L3）を修正（`2b5f616`。`Assets::set_script_translations()` に切り出し、言語パックの JSON を探すことをテストで固定。変異 3 件を検出）、G1-B2（ネットワーク管理画面の `plugins-network`。`admin_notices` が発火しない・main から同じ）は保留して backlog。確認ゲートで承認。quality green（無料版 PHPUnit 1411 件） |
| 2026-10-17 11:58 | 7 | G2（Copilot 2 回目、T=2026-10-17T02:22:18Z）: 🔵 の本文の一文に 3 件。G2-B1（生成物の片付け。`.mo` は削除済みで Copilot がバイナリを読めない誤検知）は対応不要、G2-B2（PHP の言語パックのテスト）を修正（`2eed0c2`。本体のフィルターで言語パックを模擬し `WP_LANG_DIR` に書かない）、G2-B3（通知の画面。G1-B2 の再掲）は保留のまま。確認ゲートで承認。quality green（無料版 PHPUnit 1412 件） |
| 2026-10-17 12:38 | 7 | G3（Copilot 3 回目・最後、T=2026-10-17T03:03:40Z）: 🔵 の本文の一文に 2 件（`enqueue()` のテスト・JS の訳の出力のテスト）を修正（`4dc6bdb`。`Assets` のコンストラクタが資産の一覧のパスを受け、一時ファイルで `enqueue()` を通す。JSON を一時ファイルへ差し替えて出力まで確かめる。変異 4 件を検出、dev サイトで既定の読み込みを確認）。確認ゲートで承認。quality green（無料版 PHPUnit 1413 件）。再依頼はしない |
