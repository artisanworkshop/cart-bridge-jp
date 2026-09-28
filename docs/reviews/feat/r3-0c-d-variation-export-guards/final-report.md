# dev-cycle 最終報告: feat/r3-0c-d-variation-export-guards

## 開発内容
- タスク: R3-0c（D22、issue #52）＋ R3-0d（D23、issue #74）— 在庫管理が混在する variable 商品・「Any（すべての）」バリエーションを含む商品と受注のエクスポートを止める
- PR: #83 https://github.com/artisanworkshop/cart-bridge-jp/pull/83（`Closes #52` `Closes #74`）
- 承認された計画の要約: 実測（Any の保存形式・修正前の挙動）→ Reader/Support（判定の共有）→ `Capabilities`/`Exporter`（capability で止める）→ テスト → docs。
  「Any」の定義（`VariationAxisResolver::has_any_attribute()`）と在庫の混在判定（`StockDerivation::has_mixed_variation_management()`）を 1 箇所にして商品側・受注側・在庫側で共有する。
  `VARIATION_ANY_ATTRIBUTE_UNSUPPORTED` は `indicates_export_blocking()` に登録（プラットフォーム非依存）、`VARIATION_STOCK_MANAGEMENT_MIXED` は登録せず `Exporter` が
  `Capabilities::$supports_per_variant_stock_management`（末尾・既定 `false`）で止める。
- コミット:
  - `36f1315` feat: block export of variable products with mixed stock management and "Any" variations (D22, D23)
  - `997f507` docs: record D22/D23 implementation, measurements and resolved backlog items (R3-0c, R3-0d)
  - `07515e7` fix: correct D22/D23 warning guidance and resolve the capability lookup once per page (R1 review)
  - `75b5263` docs: record review-loop R1 for R3-0c/d
  - （本記録）docs: record dev-cycle gate round 1 and final report
- 設計ドキュメントからの逸脱: なし（設計の変更は無い）。実装上の解釈 4 点（判定の母集団を「公開バリエーションすべて」にする／`StockReader` の mapping 未解決行にも混在警告を積む／案内文言は
  `WarningCode` 定数の docblock／Any の警告はバリエーション ID の detail 付き）は `docs/03` D22/D23「実装」と PR 本文に記載

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High/Medium 0、Low 3 | Low 3（docblock の列挙漏れ・案内文言の不正確・capability 解決を 1 ページ 1 回に） | 0 |
| R2 | 不要（R1 が APPROVE。修正差分は対象テスト・lint・PHPStan・ミューテーションで再確認） | — | — |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot（1 回目） | 0（インライン 0・本文 Findings: None） | 0 | 0 | 収束 |
| G1 | Codex（1 回目。自動レビューが来なかったため review コメントを自動投稿） | 0（「Didn't find any major issues」） | 0 | 0 | 収束 |

### 修正した指摘
なし。

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし。

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36424132177 green（PHP quality 8.2/8.3・PHPUnit〈wp-env〉・JS/TS・Dev tooling）
- 品質チェック（`quality.sh`）: green（PHPUnit 1243 件。lint・PHPStan・`npm run lint`・`npm run build` 含む）
- ミューテーション 13 件すべて CAUGHT（判定の反転・警告の欠落・母集団の取り違え・capability 条件・blocking への誤登録／登録漏れ・Any の軸走査・`Capabilities` の既定値）
- 実機確認（wp-env・mock アダプタ `mockv`・`JobManager` 経由）: PASS 9 件。検証データ・mu-plugin は撤去済み

## マージ前にユーザーが確認すべき点
- **実 ColorMe API・実店舗では未確認**（HTTP スタブと mock アダプタまで）。Copilot の総評も同じ指摘。R3-1 のリハーサルで、混在商品・Any 商品が「止まる」ことと、揃える／具体値に分けると通ることを実店舗で確認する
- 混在判定は「管理外の在庫切れ（0）と在庫あり（null）」も混在として止める（D22 の決定どおり）。在庫状況を店舗側で揃える必要があるため、店舗の運用に当たる場合は案内が要る（警告の docblock・docs/03 に記載）

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`（CLAUDE.md・rules への蒸留。今回 `woocommerce-api.md`・`sync-export-tools.md` に追記済み）
- 最終報告の後で Copilot が依頼していないレビューを返すことがある。`gate-threads.sh status` で新しい本文・スレッドが無いか確認する
