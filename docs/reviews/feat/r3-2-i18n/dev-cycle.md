# dev-cycle 状態: feat/r3-2-i18n

- タスク: R3-2 — i18n（ソースの i18n 不備の修正、POT 生成、日本語訳〈ja〉、.mo/.l10n.php/make-json、翻訳の鮮度を CI で検査）
- 開始: 2026-10-07
- PR: 作成後に番号を記入
- 現在のステップ: 3（review-loop）
- Copilot: 未依頼
- Codex: 未依頼

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-07 20:56 | 1 | 計画承認（`~/.claude/plans/indexed-yawning-gray.md`）。ユーザー回答: Order(s) は「注文」、dry run は「書き込みなし」、翻訳の鮮度は CI で止める（PHPUnit で PO の整合＋CI で build → make-pot → コミット済み POT と比較） |
| 2026-10-07 21:03 | 2 | ソースの i18n 不備の修正（`f648756`）: 独立の監査（Explore）で見つかった約 25 件のうち、日本語の表示が崩れる・訳せないものを修正（OAuth 完了の通知の表示名、`joinSentences()`/`joinList()`〈`src/i18n.ts`〉、`_n()`、Logs のレベル・標準の税区分の detail、`displayLocale()`、未保存の資格情報の文言、`8% for`、ボタン名の案内、表記揺れ）。`joinList()` は区切りだけの msgid を `@wordpress/i18n-no-flanking-whitespace` が拒んだため `%1$s, %2$s` の書式に変更 |
| 2026-10-07 21:20 | 2 | 生成と検査（`c9c70c1`: `bin/i18n.sh`・`bin/i18n-check.php`・npm scripts・CI・`quality.sh`・`.distignore`）、日本語訳（`8e18734`: 554 文字列・.mo・.l10n.php・JSON・`TranslationsTest`）、Tools タブの文言（`bfeaf00`）。計画からの変更 1 点: 全角と半角英数字の間の空白は、WordPress 本体の日本語訳（「こんにちは、%s さん」「WooCommerce 設定」）に合わせて**入れる**（計画の「入れない」は記憶違い）。値が日本語になるプレースホルダの前後は入れない。`check` は作り直す POT を作業ツリーに書かず、コンテナの `/tmp` に置いて 1 回の呼び出しで比べる（当初「CI ではコンテナからリポジトリへ書けるとは限らない」と書いたが、wp-env の `run` は起動中のコンテナへホストの uid で `docker compose exec` するので誤り。R1-3 で訂正） |
| 2026-10-07 21:27 | 2 | 実機確認（dev サイト）: サイトの言語を一時的に `ja` にしてフロント・REST・admin-ajax・cron を叩き「too early」通知なし（`update_option( 'WPLANG' )` はインストールされていない言語を拒み、ファイルの無い `en_US` も弾かれたので、DB で元の `en_US` に戻した）。管理者のユーザー言語 `ja` で `Assets::enqueue()` の経路の JS の訳・`cbjpAdmin.locale`・警告カタログの日本語を確認（元に戻した）。ミューテーション 8 種 CAUGHT、`i18n:check` は文字列の追加・翻訳者コメントの食い違いで失敗。ドキュメント更新（`docs/03` §6「翻訳（R3-2）」・`docs/10`・CLAUDE.md・`frontend.md`・backlog・SKILL.md の品質チェックの行）。品質チェック green（PHPUnit 1715・Jest 104・`i18n:check` 554 文字列） |
| 2026-10-07 21:54 | 3 | review-loop R1（自己レビュー＋独立サブエージェント〈opus〉）: **APPROVE**（Critical/High 0）。Medium 3 件（R1-1 設定 > 決済、R1-2 標準価格・基本的な商品、R1-3 `bin/i18n.sh` のコメントの事実誤りと一時ファイルの消し忘れ）と Low 2 件（R1-4 要再接続のときの案内〈画面のエラー表示の例外〉、R1-5 訳の手直し）を修正、Low 3 件・対象外 1 件は backlog（R1-L1 は対応不要）。品質チェック green（PHPUnit 1716・Jest 104・`i18n:check` 555 文字列）。R1-4 の変異は CAUGHT |
