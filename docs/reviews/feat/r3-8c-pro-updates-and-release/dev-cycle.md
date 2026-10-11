# dev-cycle 状態: feat/r3-8c-pro-updates-and-release
- タスク: R3-8c Pro の更新の配信（API Manager の update・information、`update_plugins_{host}`・`plugins_api`）・Pro の zip（`bin/build-zip.sh --plugin`）・Pro 用のタグのリリース。実測は手元の API Manager 3.8.1 で、R3-8a の流れも流し直す
- 開始: 2026-10-12
- 引数: `next auto-commit`（ゲートラウンドの確認ゲートは飛ばす）
- PR: 作成後に番号を記入
- 現在のステップ: 2（実装）
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-12 06:46 | 1 | 計画承認（実測は 3.8.1 で更新 API と R3-8a の流れ・`Update URI` は販売サイトの URL と同時〔1.0.0 でテストが止める〕・未有効化は問い合わせない・DB の複製は記録だけ〔AskUserQuestion でユーザー決定〕） |
| 2026-10-12 06:50 | 2 | 実測（dev サイトに API Manager 3.8.1 を一時的に入れ、`update`・`information`・R3-8a の流れを生の要求で測った。3.6.0 との差は期限内のキーの交換が通ることだけで、R3-8a の処理は変えない）→ フィクスチャ 4 件 |
| 2026-10-12 07:08 | 2 | 実装コミット 5a0dd00（Pro の `UpdateChecker`・`LicenseClient::update()`/`information()`・設定画面・テスト）、8fd17ca（`build-zip.sh --plugin`・版の照合・CI・`release-pro.yml`）。dev サイトの E2E（一時的な `Update URI`・未有効化で要求なし・有効化で更新と詳細・期限後に package なしと説明）→ API Manager と作ったデータを撤去。`quality.sh` green（無料版 1277・Pro 808・Jest 99） |
