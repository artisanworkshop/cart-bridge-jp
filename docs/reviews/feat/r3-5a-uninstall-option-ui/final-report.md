# dev-cycle 最終報告: feat/r3-5a-uninstall-option-ui

## 開発内容
- タスク: R3-5a アンインストール時のデータ削除の設定 UI（R3-5 のうち UI 側。セキュリティ最終監査は R3-5b として R3-8 の後・R3-4 の前）
- PR: #120 https://github.com/artisanworkshop/cart-bridge-jp/pull/120
- 承認された計画の要約: Tools タブの末尾に「Data on uninstall」のカード（プラットフォームの状態に関係なく出す）と `GET/PUT /settings/uninstall`。PUT は `is_bool()` で検証し、`manage_woocommerce` に加えて `delete_plugins` を要求（置き場所・権限とも 2026-10-11 ユーザー決定）。`Core\Uninstaller` は `'yes'` だけを「消す」とみなし、消すときは Action Scheduler の pending のアクションも取り消す。readme の FAQ・i18n・docs/03・docs/10
- コミット一覧:
  - b192b26 feat: add REST settings for deleting data on uninstall (R3-5a)
  - ba8ca9d feat: add the data-on-uninstall card to the Tools tab (R3-5a)
  - 4a64468 docs: record the uninstall setting (R3-5a) in the design docs
  - 8f27438 fix: tighten the uninstall setting's UI, test, and wording (review R1)
  - 2ce3fc6 docs: record review-loop R1 for R3-5a
  - 1636d70 docs: narrow the multisite and pending-action wording (review R2)
  - c511d42 docs: record review-loop R2 for R3-5a
  - 7dafa05 fix: keep the uninstall setting out of autoload when re-saved
  - eb5555d docs: record dev-cycle gate round 1
- 設計ドキュメントからの逸脱（docs/03 は更新済み）:
  1. 削除の条件を「true」から「値が `'yes'`」へ（v0.1.0 に UI・readme の記載は無く、WP-CLI で `1` を入れていたサイトは削除されない＝安全側）
  2. `PUT /settings/uninstall` は `delete_plugins` も要求（§6「特記なき限り `manage_woocommerce`」の例外。ユーザー決定）
  3. アンインストールで Action Scheduler の pending のアクションを取り消す処理を新設（消す設定のときだけ）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立 opus） | Critical/High/Medium 0・Low 6・対象外 2 | Low 6（R1-3 は readme・docs に明記） | 3（R1-3 の画面の出し分け・R1-X1・R1-X2） |
| R2（独立 opus の検証） | R1 の全件解消・新規 Low 2 | Low 2 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1 | 1 | 0 | 未収束 → G2 へ |
| G1 | Codex | 0 | 0 | 0 | 収束（Didn't find any major issues） |
| G2 | Copilot | 0 | 0 | 0 | 収束（🟢 Approval recommended） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium（自己判定 Low 相当） | `update_option()` は値が同じなら autoload を変えないので、autoload ありで作られた行が残る → 保存のたびに `wp_set_option_autoload( …, false )` | 7dafa05 | [r4239717634](https://github.com/artisanworkshop/cart-bridge-jp/pull/120#discussion_r4239717634)（Resolve 済み） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38099141264 green（eb5555d）
- 品質チェック: `quality.sh` green（無料版 PHPUnit 1262・Pro 589・Jest 91）
- ミューテーション: `'yes'` の判定を truthy に・`delete_plugins` を外す・`is_bool()` を緩める・AS の取り消しを外す・`LogCleanup` のグループを変える・`wp_set_option_autoload()` を外す、の 6 種がすべて CAUGHT
- 実機: dev サイトで REST（管理者・shop_manager）と Tools タブのカード、tests サイトで `wp plugin uninstall --skip-delete`（設定なし・`1`・`yes`）。どちらも元の状態に戻した

## マージ前にユーザーが確認すべき点
- 削除の権限を `delete_plugins` にしたため、マルチサイトのサブサイトの管理者・`DISALLOW_FILE_MODS` のサイトでは画面から変更できない（WP-CLI では `wp option update cbjp_delete_data_on_uninstall yes`）。マルチサイトのサブサイトでスーパー管理者がオンにしても効かない点は readme に明記し、画面の出し分けは backlog（`r3-5a-uninstall-option-ui/R1-3`）
- backlog に送った対象外の既存の問題 `r3-5a-uninstall-option-ui/R1-X1`（Medium）: run の途中でプラグインを停止すると、ジョブが `running` のまま残り、そのプラットフォームの新しい run が 409 で止まる（Tools タブでキャンセルすれば回復）。v1.0 の前に扱うかの判断が要る
- スクリーンショット 5（Tools）は新しいカードを含まないまま（キャプションは正しい）。撮り直すなら R3-4 で

## 最終報告後の決定（2026-10-11）
- 権限の副作用（`delete_plugins` を要求したため、マルチサイトのサブサイトでは効かず、`DISALLOW_FILE_MODS` のサイトでは画面から変えられない）: issue #122 に起票。v1.0 の計画には入れない
- 対象外の既存の問題 `r3-5a-uninstall-option-ui/R1-X1`（停止中に孤立するジョブ）: issue #121 に起票し、v1.0 の前に直す（`docs/10-tasks.md` の R3-9。R3-8 の後・R3-5b の前〔同日ユーザー決定〕）

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 両 bot とも「収束」なので後日の再確認は不要
