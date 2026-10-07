# dev-cycle 状態: feat/r3-2-i18n

- タスク: R3-2 — i18n（ソースの i18n 不備の修正、POT 生成、日本語訳〈ja〉、.mo/.l10n.php/make-json、翻訳の鮮度を CI で検査）
- 開始: 2026-10-07
- PR: #109 https://github.com/artisanworkshop/cart-bridge-jp/pull/109
- 現在のステップ: 6〜7（G2: Codex 2 回目）
- Copilot: 依頼 1 回 / 収束（G1 で新規指摘なし）
- Codex: 依頼 1 回（自動レビューが 5 分で始まらず review コメントを自動投稿）/ 未収束（G1 で 1 件修正）

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-07 20:56 | 1 | 計画承認（`~/.claude/plans/indexed-yawning-gray.md`）。ユーザー回答: Order(s) は「注文」、dry run は「書き込みなし」、翻訳の鮮度は CI で止める（PHPUnit で PO の整合＋CI で build → make-pot → コミット済み POT と比較） |
| 2026-10-07 21:03 | 2 | ソースの i18n 不備の修正（`f648756`）: 独立の監査（Explore）で見つかった約 25 件のうち、日本語の表示が崩れる・訳せないものを修正（OAuth 完了の通知の表示名、`joinSentences()`/`joinList()`〈`src/i18n.ts`〉、`_n()`、Logs のレベル・標準の税区分の detail、`displayLocale()`、未保存の資格情報の文言、`8% for`、ボタン名の案内、表記揺れ）。`joinList()` は区切りだけの msgid を `@wordpress/i18n-no-flanking-whitespace` が拒んだため `%1$s, %2$s` の書式に変更 |
| 2026-10-07 21:20 | 2 | 生成と検査（`c9c70c1`: `bin/i18n.sh`・`bin/i18n-check.php`・npm scripts・CI・`quality.sh`・`.distignore`）、日本語訳（`8e18734`: 554 文字列・.mo・.l10n.php・JSON・`TranslationsTest`）、Tools タブの文言（`bfeaf00`）。計画からの変更 1 点: 全角と半角英数字の間の空白は、WordPress 本体の日本語訳（「こんにちは、%s さん」「WooCommerce 設定」）に合わせて**入れる**（計画の「入れない」は記憶違い）。値が日本語になるプレースホルダの前後は入れない。`check` は作り直す POT を作業ツリーに書かず、コンテナの `/tmp` に置いて 1 回の呼び出しで比べる（当初「CI ではコンテナからリポジトリへ書けるとは限らない」と書いたが、wp-env の `run` は起動中のコンテナへホストの uid で `docker compose exec` するので誤り。R1-3 で訂正） |
| 2026-10-07 21:27 | 2 | 実機確認（dev サイト）: サイトの言語を一時的に `ja` にしてフロント・REST・admin-ajax・cron を叩き「too early」通知なし（`update_option( 'WPLANG' )` はインストールされていない言語を拒み、ファイルの無い `en_US` も弾かれたので、DB で元の `en_US` に戻した）。管理者のユーザー言語 `ja` で `Assets::enqueue()` の経路の JS の訳・`cbjpAdmin.locale`・警告カタログの日本語を確認（元に戻した）。ミューテーション 8 種 CAUGHT、`i18n:check` は文字列の追加・翻訳者コメントの食い違いで失敗。ドキュメント更新（`docs/03` §6「翻訳（R3-2）」・`docs/10`・CLAUDE.md・`frontend.md`・backlog・SKILL.md の品質チェックの行）。品質チェック green（PHPUnit 1715・Jest 104・`i18n:check` 554 文字列） |
| 2026-10-07 21:54 | 3 | review-loop R1（自己レビュー＋独立サブエージェント〈opus〉）: **APPROVE**（Critical/High 0）。Medium 3 件（R1-1 設定 > 決済、R1-2 標準価格・基本的な商品、R1-3 `bin/i18n.sh` のコメントの事実誤りと一時ファイルの消し忘れ）と Low 2 件（R1-4 要再接続のときの案内〈画面のエラー表示の例外〉、R1-5 訳の手直し）を修正、Low 3 件・対象外 1 件は backlog（R1-L1 は対応不要）。品質チェック green（PHPUnit 1716・Jest 104・`i18n:check` 555 文字列）。R1-4 の変異は CAUGHT |
| 2026-10-07 22:03 | 3 | review-loop R2（独立サブエージェントで検証。ミューテーション・失敗経路を実測）: **APPROVE**（R1 の 5 件はすべて解消、新規 Critical/High 0）。新規 Low 2 件（R2-1 テストの出典の誤記、R2-2 要再接続の案内に「設定を保存」の手順が無い）は本 PR の文言なので修正（`cf8bbd6`）。品質チェック green |
| 2026-10-07 22:05 | 4〜5 | PR #109 作成（T=2026-10-07T13:04:01Z）→ CI green（run 37625718945。PHPUnit ジョブの新しい `npm run i18n:check` も Linux で通過〈555 strings〉） |
| 2026-10-07 22:10 | 6 | Copilot 依頼 1 回目（timeline で登録を確認）・Codex は自動レビューを待ち、5 分で始まらなかったため `bot-wait.sh` が review コメントを投稿 |
| 2026-10-08 06:11 | 7 | G1: Copilot は 🔵・Findings: None で収束（総評は最終報告へ）。Codex は P2 1 件（前提条件の通知が英語のまま）→ ユーザー承認で修正（`e737522`: 訳のパスの登録を `cbjp_load_textdomain()` に分離）。品質チェック green（PHPUnit 1717） |
