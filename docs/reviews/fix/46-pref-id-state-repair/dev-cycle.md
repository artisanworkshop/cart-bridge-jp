# dev-cycle 状態: fix/46-pref-id-state-repair
- タスク: issue #46 — 県コード修正（PR #44）前にインポート済みの顧客・受注の都道府県を是正する（Tools タブの「県コード修復」ツール）
- 開始: 2026-09-20
- PR: #48 https://github.com/artisanworkshop/cart-bridge-jp/pull/48
- 現在のステップ: 7（G2 確認ゲート通過済み・push 後に CI → G3）
- Copilot: 依頼 2 回（G1・G2 応答済み。G2 は G1-2 と同一指摘）
- Codex: 依頼 2 回（G1・G2 応答済み。G2 は cleanup で出自を失う採用アカウントの指摘→文書化+backlog）

## ログ
| 日時(JST) | ステップ | 内容 |
|---|---|---|
| 2026-09-20 | 1 | 計画承認。Plan エージェントの敵対的検証で C1（`sales.json?ids=` は `after` 未指定だと直近7日に絞られる）・H2（所有権/保護ロール）・H4（ASP 側住所変更との混在）等を反映し、バッチ取得を不採用（単一ID取得）・GET/POST 分割・postcode/address_1 一致ガードへ設計変更 |
| 2026-09-20 | 2 | 実装。バックエンド（`Woo\Tools\PrefStateRepair`・`RepairInterruptedException`、`PlatformAdapter::fetch_order_by_remote_id()`、`RestController` の `/tools/repair-states`）、フロント（Tools タブ3枚目のカード。既存の `platformRef` を世代カウンタへ置換）、ドキュメント。PHPUnit 1053 tests green。変異テスト（ガードを1つずつ壊す6種）で対応するテストが落ちることを確認 |
| 2026-09-20 | 2 | 実測（wp-env・WC 11.1.1・HPOS 有効）: `WC_Order::save()` は `date_modified` を更新し、`set_date_modified()` で戻しても保持できない → 更新を許容（計画の「実測して決める」項目）。HPOS 無効は開発サイトの切替制約で未実測（backlog） |
| 2026-09-20 | 2 | 実機確認（mock アダプタ mu-plugin + `rest_do_request`）: Scan は書かない → Repair で顧客（JP04→JP05, JP19→JP22）・受注（請求 JP05→JP04, 配送 JP16→JP18）が補正され監査メタが残る → 再 Scan・2回目 Repair は変更0。東京（固定点）は照会なし、手修正は unverified、既存の実 `colorme` 管理者顧客は skipped で無傷。管理画面の目視確認は、ログインを済ませてもらったうえで後続の行（ステップ3）で実施した（パスワードの入力は行っていない） |
| 2026-09-20 | 3 | review-loop R1（独立サブエージェント + 自己レビュー）: Critical/High 0、Medium 3（status 0 の分類・完了通知が警告にならない・**`WC_Abstract_Order::save()` が例外を握りつぶすため保存失敗を成功と数える**）を修正、Low のうち新規コードの誤報告に直結する5件も同 PR で修正、残りは backlog。R2（検証モード）**APPROVE**（新規 Low 3: うち2件を修正、1件 backlog）。管理画面（Chrome）で Scan → Repair → 再 Scan を実機確認。PHPUnit 1064 tests green |
| 2026-09-20 | 5 | PR #48 作成。CI green（4 ジョブ） |
| 2026-09-20 | 6-7 | G1: Copilot 1 件（所有判定を不変マーカーへ→不採用。UI 文言は修正）、Codex 3 件（P1 抽象メソッド追加→設計判断でユーザー確認・現状維持／P2 国の突き合わせ→修正／P2 UI 文言→修正）。Codex は自動レビュー未発火のため `@codex review` で再依頼。確認ゲート通過（ユーザーが commit・push・返信を承認） |
| 2026-09-20 | 5-7 | CI green → G2（Copilot 2 回目・Codex 2 回目）: Copilot 1 件（`PlatformAdapter` 抽象メソッド追加。G1-2 と同一・現状維持）、Codex 1 件（P2: Sample data cleanup を先に実行した採用アカウントは出自を失い修復できない→事実と確認、根本対応は SampleCleanup の契約変更で範囲外のため UI/docs/backlog に文書化）。確認ゲート通過 |
