# dev-cycle 最終報告: feat/r3-6b2-entity-driven-ui

## 開発内容
- タスク: R3-6b2（R3-6b の 2 本目。画面を REST の宣言から組み立て、受注などの文言のフィールドをサーバーへ足す）
- PR: #116 https://github.com/artisanworkshop/cart-bridge-jp/pull/116
- 計画の要約: サーバーに `MappingKind` の文言（`label()` は抽象）・`kinds` の `entity`・`EntityType::export_description()`・`entities.import[].mapping_notice`・
  push intent の `summary`・リンク再構築の `skipped` を足し、画面から `ENTITY_ORDER`・`ENTITY_LABELS`・能力の switch・受注の文言を外す。選べる種類・既定の選択は変えない
- コミット:
  - `3e32f4a` feat: describe entity options, mapping kinds and push intents for the screen (R3-6b2)
  - `ee43ba0` feat: build the admin screens from the entity type declarations (R3-6b2)
  - `c8d2fca` docs: record R3-6b2 in the task ledger, design doc and backlog
  - `93dc339` fix: align mapping notices with the kinds list and cover the new helpers (R3-6b2 R1)
  - `95231f9` docs: record review-loop rounds R1 and R2 for R3-6b2
  - `71b946c` docs: update the R3-6b2 dev-cycle state
  - `4d81a5b` fix: keep mapping ids and count keys that collide with Object.prototype (R3-6b2 G1)
  - `0592688` docs: record dev-cycle gate round 1
  - `1f19401` fix: keep skipped link sources when resuming a rebuild (R3-6b2 G2)
  - `2b5b8be` docs: record dev-cycle gate round 2
- 設計ドキュメントからの逸脱:
  - `MappingKind::label()` を抽象で足した（D20 の「既定実装つき」は v1.0.0 公開後の規則。継承は無料版の 4 種類だけ）
  - `describe_local()` の戻り値に `summary` を足した。受注の日時はブラウザのタイムゾーンからサイトのタイムゾーン（WooCommerce の書式）へ
  - 表示の変化: Mappings の節はカテゴリが先頭、Tools の件数はクーポンが顧客・受注より前、ベータでない受注にも説明文が出る（R3-6d でスクリーンショットを撮り直す）
  - 受注・決済・配送を名指ししていた文言を汎用にした（Mappings・Export の冒頭、Import の確認と案内、検証レポート）
  - `POST /tools/rebuild-mappings` の応答に `skipped`

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High/Medium 0・Low 7・対象外 2 | Low 6・対象外 1（横展開） | Low 1・対象外 1 |
| R2（検証） | R1 全解消・新規 Low 3 | 2（記録・docs の文） | 1 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2 | 2 | 0 | 未収束 |
| G1 | Codex | 1（G1-1 と同じ） | 1 | 0 | 未収束 |
| G2 | Codex | 0 | — | — | **収束** |
| G2 | Copilot | 本文 1（Previously missed） | 1 | 0 | 未収束 |
| G3 | Copilot | 0（🟢 Approval recommended） | — | — | **収束** |

### 修正した指摘
| ID | bot | 重大度（再判定） | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | `savedMap()` が `__proto__` の ID を代入で落とし、次の保存で消える（main からの退行）。横展開で行の表示・編集も直し、backlog `e2-1-mapping-ui/G3-3` を解消 | 4d81a5b | [r4235391644](https://github.com/artisanworkshop/cart-bridge-jp/pull/116#discussion_r4235391644) |
| G1-2 | Copilot | Low | `mergeCounts()` が `constructor` のキーで継承プロパティを件数と読む | 4d81a5b | [r4235391678](https://github.com/artisanworkshop/cart-bridge-jp/pull/116#discussion_r4235391678) |
| G1-3 | Codex | Medium | G1-1 と同じ | 4d81a5b | [r4235414513](https://github.com/artisanworkshop/cart-bridge-jp/pull/116#discussion_r4235414513) |
| G2-B1 | Copilot | Low | リンク再構築を続きから再開すると、前のバッチで飛ばした種類の警告が消える | 1f19401 | なし（[review 5476428246](https://github.com/artisanworkshop/cart-bridge-jp/pull/116#pullrequestreview-5476428246)） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（ゲートの指摘はすべて修正。review-loop の Low は `docs/review-backlog.md` の `r3-6b2-entity-driven-ui/R1-L7`・`R1-X2`・`R2-3`）

## 品質ゲート
- CI: run 38006492921（HEAD 2b5b8be）green
- 品質チェック: `quality.sh` green（PHPUnit 無料版 1666 件・Pro 7 件、PHPCS、PHPStan、ESLint・tsc、Jest 83 件〈G2 の修正後〉、i18n:check）
- 実機: dev サイトで mock アダプタの REST と Mappings・Import・Export・Tools タブを目視（撤去後に検証前と一致）

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: R3-6c（顧客・受注・クーポンを Pro へ移す）。backlog `r3-6b1-entity-type-registry/R1-L8`・`R1-L9`・`R1-X3` は R3-6c の計画で決める
- マージ前の確認: 画面の文言・表示の変化（上の「設計ドキュメントからの逸脱」）
