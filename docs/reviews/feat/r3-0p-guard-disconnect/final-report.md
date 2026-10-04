# dev-cycle 最終報告: feat/r3-0p-guard-disconnect

## 開発内容

- タスク: R3-0p — run の実行中は接続の解除を拒否する（backlog `r3-0i-platform-lock/plan-X1` の B 案。2026-10-04 ユーザー決定）
- PR: #97 https://github.com/artisanworkshop/cart-bridge-jp/pull/97

承認された計画:
- `RestController::delete_connection()` の削除を `run_exclusively()` で囲み、run・ツールの実行中は 409 `cbjp_run_in_progress` を返す。
  - 判定は `JobRepository::is_platform_busy()`、ロックは `PlatformLock` の `TTL_SHORT`。
  - 一覧に run があるときだけ切断用の文言にする。
- 再接続側（C 案）と接続先ショップの照合（D 案）は対象外。
- フロントエンドは変更しない。

コミット:

| sha | メッセージ |
|---|---|
| `a6f9051` | fix: refuse to disconnect a platform while a run or tool is using it (R3-0p) |
| `3e3a8a5` | docs: record R3-0p (guard disconnect during a run) in the design, task ledger and backlog |
| `158e388` | fix: point the disconnect refusal to the Tools tab, which lists unconnected platforms（review-loop R1） |
| `18eaf79` | docs: record review-loop R1/R2 for R3-0p and qualify the background wording |
| `e233c13` | docs: align the plan-X1 backlog entry with the shipped disconnect wording（G1-1） |
| `2fe0b59` | docs: record dev-cycle gate round 1 |

設計ドキュメントからの逸脱: なし（仕様の追加のみ）。
- docs/03 §6 の `DELETE /connections/{platform}` は、run・ツールの実行中は 409 になる。
- §5 に、囲む区間・理由・残る制限を追記した。
- 計画からの変更は 1 点（review-loop R1-1）。切断の文言の案内先として、Import／Export タブより先に Tools タブを挙げた。
  - Import／Export タブは接続済みのプラットフォームしか並べない。
  - 要再接続（トークンを復号できない）の状態では、切断が唯一の復旧手段になる。

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 1・Low 5・対象外 1 | Medium 1・Low 5 | 1（`r3-0p-guard-disconnect/R1-X1`: 要再接続では `PUT /connections` が必ず 409） |
| R2 | 新規 0（R1 の全指摘が解消。テストの追加で直した 3 件はミューテーションで実測） | docs の記述を 1 か所揃えた | 0 |

判定は R2 で APPROVE。R1 は自己レビューと独立サブエージェントを併用し、Medium は両者がそれぞれ独立に見つけた。

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex（1 回目。自動レビューが 5 分来ず、review コメントを自動投稿） | 0 | 0 | 0 | 収束 |
| G1 | Copilot（1 回目。🟢 Approval recommended） | 1（Low） | 1 | 0 | 未収束 |
| G2 | Copilot（2 回目。🟢 Approval recommended・Findings: None） | 0 | 0 | 0 | 収束 |

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Low | backlog の plan-X1 行の対応済み欄が、R1 で変える前の文言（Import／Export タブで先にキャンセル）のままだった | `e233c13` | [r4177658392](https://github.com/artisanworkshop/cart-bridge-jp/pull/97#discussion_r4177658392)（Resolve 済み） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

なし

## 品質ゲート

- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37205112079 green（`2fe0b59`）
- 品質チェック: green（PHPUnit 1493 件・Jest 87 件）
- ミューテーション 5 種がすべて CAUGHT
  - 判定より前に削除する
  - 切断用の文言を渡さない
  - 一覧が空でも切断用の文言にする
  - ロックを取れなかった側で文言を渡さない
  - 未接続なら判定を飛ばす
- 実機確認: wp-env の dev サイト（mock `mockv`）で、`rest_do_request()` を使って確認した。確認後に撤去し、dev サイトは確認前の状態に戻した。
  - run の開始後の切断は 409 になり、資格情報が残る
  - キャンセルの後は 200 になり、資格情報が消える
  - この確認は R1 で文言を変える前に行った。新しい文言は PHPUnit で確かめている

## 次にできること（人間の判断）

- マージ（GitHub 上で人間が行う）。マージ後は `/post-merge`
- 両 bot とも「収束」なので、後日の再確認は不要
- 残した backlog（要判断）
  - `r3-0p-guard-disconnect/plan-X1-rest`: 再接続のガード（C 案）と接続先ショップの照合（D 案）。v1.0.x 以降に判断
  - `r3-0p-guard-disconnect/R1-X1`: 要再接続の状態では `PUT /connections` が必ず 409 `cbjp_save_conflict` になる（既存の問題）
