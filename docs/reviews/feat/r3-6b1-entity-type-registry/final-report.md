# dev-cycle 最終報告: feat/r3-6b1-entity-type-registry

## 開発内容
- タスク: R3-6b1（R3-6b を backend/frontend に分けた 1 本目）。実体の種類のレジストリを作り、顧客・受注・クーポンを拡張点経由の登録に作り替える（動作は変えない）
- PR: #115 https://github.com/artisanworkshop/cart-bridge-jp/pull/115
- 承認された計画の要約（ユーザー決定 2026-10-10: 2 PR に分ける・全実体を同じレジストリに載せる・`ColorMeApi` を切り出す・画面は REST の宣言から描く）:
  - `CartBridgeJP\Entities\`（`EntityType`・`EntityTypeRegistry`〔`cbjp/entity_types/register`〕・`WooServices`・`LinkSource`・`MappingKind`・`WarningText`・`WarningFlag`）を足し、約 20 箇所の実体ごとの分岐を種類に問い合わせる形にした
  - 無料版の商品系は内部で、顧客・受注・クーポンは `Entities/Commerce/`（R3-6c で Pro へ移す単位）から公開のフィルターで登録する
  - 顧客・受注・クーポンの警告の印とカタログの文言を種類へ移した。外部の種類の例外・壊れた戻り値で一覧・レポート・ページの処理が落ちないようにした
  - `ColorMeAdapter::api()`（`ColorMeApi`）・`is_premium_plan()` を公開、通貨を `Support\Money::PLATFORM_CURRENCY` へ
  - REST に `/connections` の `entities`・`/settings/mappings` の `kinds` を足した（画面が使うのは R3-6b2）
  - docs/03 §10.0 に決め残し 2・3 の残り・8 の決定と「Pro が使ってよい無料版の API」を記録
- コミット一覧:

| sha | メッセージ |
|---|---|
| `cdfdc5f` | test: pin entity dispatch behavior before the entity type registry (R3-6b1) |
| `f99545d` | refactor: move the platform currency to Support\Money (R3-6b1) |
| `0d23af0` | refactor: extract ColorMeApi from ColorMeAdapter (R3-6b1) |
| `ac3eb31` | refactor: route entity dispatch through an entity type registry (R3-6b1) |
| `fd21674` | refactor: let entity types own their warning flags and catalog texts (R3-6b1) |
| `956b493` | feat: expose entity types and mapping kinds to the admin UI (R3-6b1) |
| `3da3c5b` | test: cover external entity types end to end and pin the extension contract (R3-6b1) |
| `97d659c` | docs: record the entity type registry and the R3-6b split (R3-6b1) |
| `2b16dce` | fix: isolate external entity types and keep the free plugin's keys (R3-6b1 review R1) |
| `4390e14` | test: cover the R1 review fixes for entity types (R3-6b1) |
| `3fa3b36` | docs: record the R1 review of the entity type registry (R3-6b1) |
| `7e106b8` | fix: derive the free entity types from the registry and log label failures (R3-6b1 review R2) |
| `afd8ebe` | docs: record the R2 review of the entity type registry (R3-6b1) |
| `875ac42` | docs: record the R1 fixes in the review record (R3-6b1) |
| `598b19d` | fix: harden ColorMeApi, Woo mapping candidates and the verification report against extension output (R3-6b1 G1) |
| `47bc3c4` | docs: record dev-cycle gate round 1 (R3-6b1) |
| `cfd66e7` | fix: bound link source scans and isolate push intent checks from extensions (R3-6b1 G2) |
| `026c63c` | docs: record dev-cycle gate round 2 (R3-6b1) |
| `f671ab0` | fix: drop an extension's amount summary when a currency entry is malformed (R3-6b1 G3) |
| `00b079f` | docs: record dev-cycle gate round 3 (R3-6b1) |

- 設計ドキュメントからの逸脱（PR 本文と同じ）:
  - R3-6b を b1（backend）/b2（frontend）に分けた（ユーザー決定。docs 更新済み）
  - `MappingKind` の ASP 側の候補・未実装の `existing_local_ids()` の画面での扱い・警告定数の移動・`MethodMap` の分割は R3-6c、文言のフィールドは R3-6b2 に回した
  - 振る舞いとして足したのは「マッピングの PUT が登録の無いキーを残す」だけ（今の 4 キーでは結果が同じ）。異常系では外部の種類の例外を握る箇所を足した（無料版の種類の振る舞いは不変）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント opus） | Medium 7・Low 9・対象外 4 | Medium 7・Low 5 | Low 4・対象外 3 |
| R2（検証。ミューテーションで解消を実測） | 新規 Low 5 → APPROVE | Low 4 | Low 1 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（自動レビューが 5 分で届かず review コメントを自動投稿。Didn't find any major issues） |
| G1 | Copilot | 3 | 3 | 0 | 未収束 |
| G2 | Copilot | 1 ＋本文 2 | 3 | 0 | 未収束 |
| G3 | Copilot | 本文 1 | 1 | 0 | 上限（3 回目。修正して push、再依頼なし） |

### 修正した指摘
| ID | bot | 重大度（再判定） | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | `ColorMeApi::transform_rows_flat()` がコールバックの配列でない戻り値でページを落とす → その行の変換失敗として飛ばす | 598b19d | [r4232658460](https://github.com/artisanworkshop/cart-bridge-jp/pull/115#discussion_r4232658460) |
| G1-2 | Copilot | Medium | Woo 側のマッピング候補が保存時と同じ正規化を通らない → ASP 側と同じ正規化 | 598b19d | [r4232658533](https://github.com/artisanworkshop/cart-bridge-jp/pull/115#discussion_r4232658533) |
| G1-3 | Copilot | Medium | 検証レポートが外部の種類の ID を信用（重複・無関係な ID） → 問い合わせた ID の中だけを重複なしで数える | 598b19d | [r4232658607](https://github.com/artisanworkshop/cart-bridge-jp/pull/115#discussion_r4232658607) |
| G2-1 | Copilot | Medium | リンク再構築がありえない件数の走査結果を受け入れる → 上限違反も飛ばす | cfd66e7 | [r4232812744](https://github.com/artisanworkshop/cart-bridge-jp/pull/115#discussion_r4232812744) |
| G2-B1 | Copilot | Medium | `records_push_intent()` の例外がページを落とす → 握って印を残す側に倒す | cfd66e7 | 本文（[review 5473314513](https://github.com/artisanworkshop/cart-bridge-jp/pull/115#pullrequestreview-5473314513)） |
| G2-B2 | Copilot | Medium | push intent の解除で取得したモデルの `remote_id()` が例外の境界の外 → 境界の中で読む | cfd66e7 | 本文（同上） |
| G3-B1 | Copilot | Medium | 検証レポートで不正な通貨の要素を捨てて突合を続ける → 集計ごと捨てる（fail-closed） | f671ab0 | 本文（[review 5473460457](https://github.com/artisanworkshop/cart-bridge-jp/pull/115#pullrequestreview-5473460457)） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（スレッドはすべて修正して Resolve 済み。backlog に送ったのは review-loop の Low・対象外の 8 件〔`docs/review-backlog.md` の `r3-6b1-entity-type-registry/*`〕）

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37968888439 green（HEAD 00b079f）
- 品質チェック（`quality.sh`）: green（無料版 1661 件・Pro 7 件・Jest 58 件・lint・analyze・dev マウントの 403・i18n:check）。既存テストの変更 0 件
- 実機: dev サイトの mock アダプタで main と同じスクリプトを流し、新しい項目を除いて出力が一致（実 API は未確認。React は変更なし）

## マージ前にユーザーが確認すること
- Copilot は 3 回目（G3）でも本文に新規の指摘を 1 件出した（修正済み）。依頼の上限に達したため、修正後の HEAD（00b079f）は bot の再レビューを受けていない。G1〜G3 の指摘はすべて外部の種類（Pro アドオン・第三者）が契約に反した値を返す場合の防御で、無料版自身の種類の振る舞いには影響しない
- 公開の拡張点（`EntityType`・`MappingKind`・`LinkSource`・`WooServices`・`ColorMeApi`）の形と、docs/03 §10.0「Pro が使ってよい無料版の API」は v1.0.0 の公開（R3-4）後に互換を保つ範囲になる

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: R3-6b2（frontend。画面を `entities`・`entityLabels`・`kinds` から組み立てる）→ R3-6c（顧客・受注・クーポンを Pro へ移す。backlog の `r3-6b1-entity-type-registry/R1-X3`〔Pro の ColorMe の実装がアダプタの外に出る〕・`R1-L8`・`R1-L9` を計画で決める）
