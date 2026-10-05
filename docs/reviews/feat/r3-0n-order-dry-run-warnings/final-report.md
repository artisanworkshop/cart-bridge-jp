# dev-cycle 最終報告: feat/r3-0n-order-dry-run-warnings

## 開発内容
- タスク: **R3-0n** — 受注 dry-run の残りの警告（数量 0 明細・税合計不完全・未解決参照）の原因確定と扱い（`docs/10-tasks.md`）
- PR: #90 https://github.com/artisanworkshop/cart-bridge-jp/pull/90
- 承認された計画: 実データで原因を確定してから扱いを決める（Phase A: 読取専用の取得スクリプトを dry-run したサイトで実行 → Phase B: 実装）。取得環境は「dry-run したサイトで WP-CLI」（ユーザー決定）

### 原因（実データで確定）と扱い
| 警告 | 原因 | 扱い |
|---|---|---|
| 数量 0＋税の不整合 | 全行が `product_num=0`・`subtotal_price=0`。キャンセル受注か、一部の明細を外した受注 | 数量 0・金額 0 のまま**警告なし**で取り込む（ユーザー決定） |
| 税合計の不完全 | `sale.totals` が 2019-09-09 以前の受注で null | コード変更なし（警告は正しい） |
| 商品の未解決 | 大半は ColorMe で削除済み、残りは受注後にオプションの軸が増えた | 後者を新コード `order_line_variation_unmatched` に分ける |
| 顧客の未解決 | すべて ColorMe で削除済み | CSV の `note` を `reference_unresolved`（未インポート、またはASP側で削除済み）に（ユーザー決定） |
| 管理者アカウント | スタッフのアカウントと同じメール | 対応なし |

ゲートでの追加（G1〜G3）: 数量 0 の判定を変換層で崩さないよう、ColorMe の `product_num`・`subtotal_price` は整数だけを受ける（小数・指数表記・float・欠損は数量 1＋警告、または受注ごと弾く）。

### コミット
| sha | メッセージ |
|---|---|
| `c5c855d` | feat: keep zero-quantity order lines and stop misreporting deleted references (R3-0n) |
| `3af521a` | docs: record R3-0n findings from real ColorMe orders and the new handling |
| `11c8144` | docs: clarify trashed products and the ColorMe-only order update skip |
| `748c851` | docs: record review-loop R2 for R3-0n |
| `e15c424` | fix: keep unreadable ColorMe line amounts out of the zero-quantity path |
| `de88d04` | docs: record dev-cycle gate round 1 for R3-0n |
| `8a42a38` | fix: do not truncate a fractional ColorMe line subtotal to zero |
| `1c55a17` | docs: record dev-cycle gate round 2 for R3-0n |
| `ce3f513` | fix: reject float product_num and subtotal_price from ColorMe |
| `1cd5cbb` | docs: record dev-cycle gate round 3 for R3-0n |

### 設計ドキュメントからの逸脱
- D10 に「数量0の明細」を追加（数量 0・金額 0 は警告なしで取り込む。計画の「情報警告」案からユーザー判断で変更）
- エクスポート方向は変えない（数量 0 は blocking のまま。エクスポートの dry-run では取り込んだ該当受注が `updated` から `skipped` になる）
- 変換層の厳格化（G1〜G3）: 整数でない `product_num` は受注ごと弾く（従来は切り捨てて取込み）。小計の欠損は 0 円ではなく単価×数量で補う
- 見送り（ユーザー決定）: ASP へ実在確認して削除済みを別コードにする案 → backlog `r3-0n/idea-remote-existence-check`
- 新コード名は、計画の例 `order_line_variation_unresolved` がエクスポート方向の既存コードと衝突したため `order_line_variation_unmatched`

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Medium 2・Low 5・対象外 1 | Medium 2・Low 4（Low 1 は対応不要） | 対象外 1 |
| R2（独立サブエージェントで検証） | R1 解消・新規 Low 2 | Low 2 | 0 |

R1-1（テスト・docs に実店舗の顧客 ID などが残っていた）は、push 前に main からコミットを作り直して、実 ID を含むコミットを履歴に残していない。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2 | 2 | 0 | 未収束 |
| G1 | Codex | 1 | 1 | 0 | 未収束（自動レビューが 5 分で来ず、自動で再依頼） |
| G2 | Copilot | 1 | 1 | 0 | 未収束 |
| G2 | Codex | 1（Copilot と同じ） | 1 | 0 | 未収束 |
| G3 | Copilot | 1 | 1 | 0 | 依頼上限（3 回）。修正は push 済み・再依頼なし |
| G3 | Codex | 0 | — | — | **収束** |

### 修正した指摘
| ID | bot | 重大度（自分の判定） | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | 変換層の丸め（`product_num` の切り捨て・小計の `'0'`）で数量 0 の判定より前に不正が失われる | `e15c424` | [r4143778856](https://github.com/artisanworkshop/cart-bridge-jp/pull/90#discussion_r4143778856) |
| G1-2 | Copilot | Low | オプション値のコメント（「最新の商品情報」）と実測の矛盾 | `e15c424` | [r4143778907](https://github.com/artisanworkshop/cart-bridge-jp/pull/90#discussion_r4143778907) |
| G1-3 | Codex | Low〜Medium | `'1e-400'` のアンダーフローで 0 と判定 | `e15c424` | [r4143834773](https://github.com/artisanworkshop/cart-bridge-jp/pull/90#discussion_r4143834773) |
| G2-1 | Copilot | Medium | `money_or_null()` も小計の小数を切り捨てる（G1-1 の取りこぼし） | `8a42a38` | [r4143956952](https://github.com/artisanworkshop/cart-bridge-jp/pull/90#discussion_r4143956952) |
| G2-2 | Codex | Medium | G2-1 と同じ | `8a42a38` | [r4143968642](https://github.com/artisanworkshop/cart-bridge-jp/pull/90#discussion_r4143968642) |
| G3-1 | Copilot | Low | JSON の `1e-400` が `json_decode()` で float(0) になり厳密な整数変換を通る | `ce3f513` | [r4144093962](https://github.com/artisanworkshop/cart-bridge-jp/pull/90#discussion_r4144093962) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（全スレッド Resolve 済み）。

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36722328550 green（最終 HEAD `1cd5cbb`。PHP quality 8.2/8.3・PHPUnit・JS/TS・Dev tooling）
- 品質チェック: green（PHPUnit 1375 件・Jest 55 件・lint・PHPStan・build）
- ミューテーション: 実装 12 種＋ゲート 5 種、すべて CAUGHT
- mock（`mockv`）で実受注 JSON を本物の `OrderTransformer` に通した受注 dry-run → CSV を確認（撤去済み）

## 次にできること（人間の判断）
- **Copilot の G3 の修正（`ce3f513`）は bot に再レビューされていない**（依頼上限）。気になる場合は `/fix-copilot-review 90` で後日確認
- 実店舗で再 dry-run（R3-0m のマッピング設定と合わせて）。期待値: 数量 0・税の不整合が消え、商品・顧客の未解決の `note` が `reference_unresolved`、variation 不一致が `order_line_variation_unmatched` になり、税合計の不完全は残る
- `dist/` の取得スクリプト（`r3-0n-probe.php.txt`）と出力（`r3-0n-probe-*.json`）は gitignore 済みのローカルファイル。不要なら削除
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
