# dev-cycle 状態: fix/121-orphaned-job-recovery

- タスク: R3-9（issue #121）— 停止・致命的エラーで Action Scheduler のアクションを失ったジョブが進行中のまま残り、そのプラットフォームの新しい run が 409 で止まり続ける問題の回復
- 開始: 2026-10-13
- 指定: `next auto-commit`（ゲートラウンドの確認ゲートを飛ばす）
- PR: 作成後に番号を記入
- 現在のステップ: 3（review-loop）
- Copilot: 依頼 0 回
- Codex: 依頼 0 回

## ログ

| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-10-13 03:51 | 1 | 計画承認（`~/.claude/plans/snazzy-booping-lemur.md`）。独立の Plan エージェントの検証で、長いページ（AS が 5 分で in-progress を失敗扱いにする）を誤って回復する High が見つかり、ハートビート・CAS・Retry での残りのアクションの取消し・フェイルクローズの穴を計画に入れた。再有効化のときの即時の回復と停止時のフックは採らない |
| 2026-10-13 04:20 | 2 | 実装コミット 2 件（`a8895ec` backend・`eeb910c` 画面と翻訳）＋ docs。品質チェック green（無料版 PHPUnit 1309 件・Pro 841 件・Jest 104 件）。ガードのミューテーション 29 件を確認（NullAction の判定は hook の判定と重複していたので外した）。dev サイト（mock `mockv`）で停止 → キューの実行 → 再有効化 → 回復 → Retry で完了を確認。本物の致命的エラーでは、WordPress の致命的エラーハンドラーが `wp_die()` で終わるため AS の `FatalErrorMonitor` と `action_scheduler_unexpected_shutdown` のフックが走らないと判明（計画では即時の回復としていた）。その場合は AS の失敗扱いの後に土台が `action_lost` で拾う（実測）。ハンドラーを無効にした設定ではフックが即時に `fatal_error` にした。`action_lost` の説明に「処理の途中で PHP が止まった場合」を足した。管理画面の目視は未実施（dev サイトが未ログイン） |
