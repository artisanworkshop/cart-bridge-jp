# dev-cycle 状態: chore/126-measure-api-manager-skill
- タスク: issue #126 API Manager を dev サイトに一時的に入れて実測し、完全に消す手順をスキル（`measure-api-manager`）にする。更新の配信の E2E も含める
- 開始: 2026-10-12
- 引数: issue #126（確認ゲートあり。auto-commit の指定なし）
- PR: 作成後に番号を記入
- 現在のステップ: 2（実装）
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-12 10:05 | 1 | 計画承認（E2E を含める〔作業ツリーの Pro のヘッダーに一時的に `Update URI` を足し、後片付けで戻す。守りつき〕・本番の販売サイトに要求を送るモードは作らない〔AskUserQuestion でユーザー決定〕） |
| 2026-10-12 10:34 | 2 | 実装コミット d969e14（スキル本体・`quality.sh` と CI の dev-tooling への `test-anon.php` の組み込み）。dev サイトで 3.8.1 の setup → measure all → compare（フィクスチャ 13 件すべて一致。`deactivate` のグループは別の instance も有効化する形にそろえた）→ E2E → teardown、3.6.0 で setup → measure → compare（information の節の名前だけ違う）→ teardown。setup の前と teardown の後を丸ごと比べ、WC の属性の索引の予定と注文のメールのログが残ったので直し、直した後は差 0。守り（2 回目の setup・未コミットの変更がある E2E・目印の無い mu-plugin・状態ファイルの無い残り）を実測。`anon.php` の変異 9 種 CAUGHT。`quality.sh` green（無料版 1277・Pro 829・Jest 99） |
