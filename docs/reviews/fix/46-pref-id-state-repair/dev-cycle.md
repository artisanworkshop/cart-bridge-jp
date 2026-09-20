# dev-cycle 状態: fix/46-pref-id-state-repair
- タスク: issue #46 — 県コード修正（PR #44）前にインポート済みの顧客・受注の都道府県を是正する（Tools タブの「県コード修復」ツール）
- 開始: 2026-09-20
- PR: 未作成
- 現在のステップ: 2 完了 → 3（review-loop R1）へ
- Copilot: 未依頼
- Codex: 未依頼

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-09-20 | 1 | 計画承認。Plan エージェントの敵対的検証で C1（`sales.json?ids=` は `after` 未指定だと直近7日に絞られる）・H2（所有権/保護ロール）・H4（ASP 側住所変更との混在）等を反映し、バッチ取得を不採用（単一ID取得）・GET/POST 分割・postcode/address_1 一致ガードへ設計変更 |
| 2026-09-20 | 2 | 実装。バックエンド（`Woo\Tools\PrefStateRepair`・`RepairInterruptedException`、`PlatformAdapter::fetch_order_by_remote_id()`、`RestController` の `/tools/repair-states`）、フロント（Tools タブ3枚目のカード。既存の `platformRef` を世代カウンタへ置換）、ドキュメント。PHPUnit 1053 tests green。変異テスト（ガードを1つずつ壊す6種）で対応するテストが落ちることを確認 |
| 2026-09-20 | 2 | 実測（wp-env・WC 11.1.1・HPOS 有効）: `WC_Order::save()` は `date_modified` を更新し、`set_date_modified()` で戻しても保持できない → 更新を許容（計画の「実測して決める」項目）。HPOS 無効は開発サイトの切替制約で未実測（backlog） |
| 2026-09-20 | 2 | 実機確認（mock アダプタ mu-plugin + `rest_do_request`）: Scan は書かない → Repair で顧客（JP04→JP05, JP19→JP22）・受注（請求 JP05→JP04, 配送 JP16→JP18）が補正され監査メタが残る → 再 Scan・2回目 Repair は変更0。東京（固定点）は照会なし、手修正は unverified、既存の実 `colorme` 管理者顧客は skipped で無傷。管理画面の目視確認はログインが必要なためユーザー待ち（パスワード入力は行わない） |
