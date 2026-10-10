# dev-cycle 状態: feat/r3-6c1-move-commerce-to-pro
- タスク: R3-6c1 顧客・受注・クーポンのコード（実体の種類・Writer/Reader・Canonical・ColorMe の変換器と API 呼び出し）を Pro アドオンへ移し、`PlatformAdapter`・`Capabilities` から外す（R3-6c の 1 本目。OAuth スコープの分割は c2）
- 開始: 2026-10-10
- 引数: `auto-commit`（ゲートラウンドの確認ゲートを飛ばす）
- PR: 作成後に番号を記入
- 現在のステップ: 2（実装）
- Copilot: 未依頼
- Codex: 未依頼

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-10 10:21 | 1 | 計画承認（ユーザー決定: R3-6c を c1〈移動〉/c2〈OAuth スコープ〉に分ける・Pro に専用のアダプタ層〈`CommerceAdapter`〉を作る）。計画の独立レビュー（Plan エージェント）の細部の指摘を実装で反映 |
