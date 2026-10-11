# dev-cycle 状態: feat/r3-8a-license-client
- タスク: R3-8a ライセンスのクライアント（WooCommerce API Manager の有効化・無効化・状態確認）と Pro の設定画面（PHP）。キーは暗号化・有効化の状態は平文。API は手元の API Manager 3.6.0 を dev サイトに一時的に入れて実測する
- 開始: 2026-10-11
- 引数: `auto-commit`（ゲートラウンドの確認ゲートを飛ばす）
- PR: 作成後に番号を記入
- 現在のステップ: 2（実装）
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-11 15:43 | 1 | 計画承認（実測は手元の API Manager 3.6.0 を dev サイトに入れて行う・キーは暗号化し状態は平文〔AskUserQuestion でユーザー決定〕） |
| 2026-10-11 16:07 | 2 | 実測（dev サイトに API Manager 3.6.0 を一時的に入れ、有効化・無効化・状態確認・期限切れを生の要求で測った。期限後の状態確認でサーバーが有効化を消し無効化が必ず失敗する → キーの交換を足す〔AskUserQuestion でユーザー決定〕）→ 実装コミット f7ce850（Pro の License・LicensePage・テスト 126 件・フィクスチャ）。ガードの変異 13 種がすべて CAUGHT（autoload は最初 NOT CAUGHT → テストを直して CAUGHT）。dev サイトで画面からの E2E（curl。期限切れ・猶予期間中のキーの交換・復号できないキーを含む）→ API Manager と作ったデータを撤去。`quality.sh` green（無料版 1262・Pro 715・Jest 91） |
