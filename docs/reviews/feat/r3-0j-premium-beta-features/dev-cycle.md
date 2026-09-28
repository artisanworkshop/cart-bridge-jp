# dev-cycle 状態: feat/r3-0j-premium-beta-features

- タスク: R3-0j（D24、issue #75）— プレミアムプラン限定の受注エクスポートと商品画像アップロードをベータ表示にし、既定オフにする
- 開始: 2026-09-28
- PR: 作成後に番号を記入
- 現在のステップ: 4〜5（PR 作成・CI 待ち）
- Copilot: 未依頼
- Codex: 未依頼（PR 作成時の自動レビューを待つ）

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-09-28 | 1 | 計画承認（`~/.claude/plans/swirling-questing-pelican.md`。`Capabilities::$beta_features`・`cbjp_export_options_{platform}`・REST `settings/export-options`・Exporter のチェックサムの印・Export タブの順。逸脱 2 点〔`can_push_images` の意味の分離・チェックサムの印〕も承認） |
| 2026-09-29 | 2 | 実装（backend＋テスト／frontend／docs・rules・mock テンプレート）。ミューテーション 15 件すべて CAUGHT。mock アダプタ（`mockv`）で REST・JobManager 経由の checksum の再送・実 `ColorMeAdapter` のモック HTTP、管理画面（ブラウザ。WordPress Studio との IPv6 ポート競合はユーザーが Studio 側を停止して解消）で Beta 表示・既定オフ・保存の保持・非プレミアム相当の非表示を確認、撤去済み |
| 2026-09-29 | 3 | review-loop R1（自己レビュー＋独立サブエージェント）: Medium 2 件（外部アダプタ向け契約の明文化・画像の上書き警告）と画面・文言の Low 4 件を修正、Low 1 件と対象外 1 件は backlog → R2（独立サブエージェントで検証）: **APPROVE**（新規 Low 3 件は文書・コメントの整合で修正済み） |
