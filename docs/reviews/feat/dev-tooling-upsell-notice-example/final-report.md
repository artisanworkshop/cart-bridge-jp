# dev-cycle 最終報告: feat/dev-tooling-upsell-notice-example

## 開発内容
- タスク: PR #87 の /post-merge で提案したコマンド化の 2 件目。`verify-with-mock-adapter` に Pro 案内の検証 example（`examples/upsell-notice/`）を追加し、テンプレートに seed の `limits`/`pro_url` を追加
- PR: #88 https://github.com/artisanworkshop/cart-bridge-jp/pull/88
- 1 件目（グローバルの review-loop `mutate-check.sh` の Jest 誤判定）は元本 `~/Dev/claude-skills` の main に直接コミット済み（`9d3ed14`）で、この PR には含まない

### コミット一覧
| sha | メッセージ |
|---|---|
| 30e131f | feat: add an upsell-notice example to verify-with-mock-adapter |
| cc37525 | fix: harden the upsell-notice example against leftovers and masked results（G1-1〜G1-3） |
| 0e2fb5d | docs: record dev-cycle gate round 1 for #88 |
| c5e4a75 | fix: pin the upsell-notice export sample to the seeded products（G2-1・G2-2） |
| 78a3e4e | docs: record dev-cycle gate round 2 for #88 |
| 571571a | fix: delete job-dependent rows before their jobs in the mock cleanups（G3-1） |

### 設計ドキュメントからの逸脱
なし（開発補助の example とテンプレートのみ。プラグイン本体の変更なし）

## review-loop（PR 前）
実施していない（開発補助の example のため、ユーザーの指示で実機確認のうえ PR とボットゲートへ）

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2（Medium） | 2 | 0 | 未収束 |
| G1 | Codex | 1（P2。前提は誤りだが弱点は本物） | 1 | 0 | 未収束 |
| G2 | Copilot | 1（Medium） | 1 | 0 | 未収束 |
| G2 | Codex | 1（P2） | 1 | 0 | 未収束 |
| G3 | Copilot | 0 | 0 | 0 | 収束 |
| G3 | Codex | 1（P2。15 分で TIMEOUT → 待ち直しで到着） | 1 | 0 | 依頼上限（修正後の再依頼なし） |

### 修正した指摘
| ID | bot | 内容 | コミット |
|---|---|---|---|
| G1-1 | Copilot | 開始前の残り検査を cleanup の範囲（seed の `push`/`limits`/`pro_url`、mockv のサンプル・レート制限のオプション）に揃えた | cc37525 |
| G1-2 | Copilot | 集計ではなく作った商品ごとの dry-run 明細で判定（`NOPRICE` に価格を付ける変異で CAUGHT） | cc37525 |
| G1-3 | Codex | 「`sku` は完全一致」は誤り（実ソースは `LIKE`）。ただし記録前に止まった商品を消せない弱点を修正（接頭辞で見つかる商品も消す） | cc37525 |
| G2-1 | Codex | export のサンプルを作った 4 件に固定（受注 10 件以上の dev サイトでは作った商品が export されなかった。固定を外す変異で CAUGHT） | c5e4a75 |
| G2-2 | Copilot | cleanup の `left` にサンプル・レート制限のオプションを追加 | c5e4a75 |
| G3-1 | Codex | cleanup がジョブを先に消して依存行が取り残されうる順序を修正（既存の push-intent-resolution も横展開） | 571571a |

### 修正しなかった指摘
なし

## 品質ゲート
- wp-env: upsell-notice・push-intent-resolution とも ALL PASS、各 cleanup の `left` はすべて 0、`uninstall` 後の `inspect` が検証前と一致
- 管理画面で通知の両モード（`pro_url` なし／あり）を目視（コンソールエラーなし）
- `composer lint`・`php -l` green。CI は最終 push で確認する

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- Codex の G3 の修正（571571a）は依頼上限のため bot の再レビューを受けていない。必要なら `/fix-copilot-review 88` で後日確認できる
