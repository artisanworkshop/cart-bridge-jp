# dev-cycle 状態: feat/r3-5a-uninstall-option-ui
- タスク: R3-5a アンインストール時のデータ削除の設定 UI（Tools タブのカード・`GET/PUT /settings/uninstall`・readme の FAQ。R3-5 のうち UI 側だけ。監査は R3-5b として R3-8 の後）
- 開始: 2026-10-11
- 引数: `auto-commit`（ゲートラウンドの確認ゲートを飛ばす）
- PR: 作成後に番号を記入
- 現在のステップ: 3（review-loop）
- Copilot: 未依頼
- Codex: 未依頼

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-11 08:46 | 1 | 計画承認（置き場所は Tools タブのカード、PUT は `delete_plugins` も要求〔AskUserQuestion でユーザー決定〕。値は `'yes'` だけ、削除時に AS のグループのアクションを取り消す） |
| 2026-10-11 08:54 | 2 | 実装コミット（b192b26 REST・Uninstaller・テスト、ba8ca9d Tools タブのカード・i18n・readme の FAQ、docs）。`quality.sh` green（無料版 1260・Pro 589・Jest 91）。ガードの変異 4 種（`'yes'` を truthy に・`delete_plugins` の確認を外す・`is_bool()` を緩める・AS の取り消しを外す）がすべて CAUGHT。dev サイトで REST（管理者・shop_manager）と Tools タブの切替・警告を実機確認、tests サイトで `wp plugin uninstall --skip-delete` を設定オフ・`1`・`yes` の 3 通りで確認（`yes` だけテーブル・option が消え AS のアクションが canceled）。どちらも元の状態に戻した |
| 2026-10-11 09:12 | 3 | review-loop R1（自己＋独立 opus）: Critical/High/Medium 0・Low 6・対象外 2 → Low 6 件を修正（8f27438 と docs。R1-3 は readme・docs に明記し画面の出し分けは backlog）、対象外 2 件を backlog。`quality.sh` green |
