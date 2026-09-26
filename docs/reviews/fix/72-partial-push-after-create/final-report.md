# dev-cycle 最終報告: fix/72-partial-push-after-create

## 開発内容
- タスク: R3-0a（issue #72）作成後の中断による重複作成を防ぐ（設計判断 D21-A: `PartialPushException`）
- PR: #77 https://github.com/artisanworkshop/cart-bridge-jp/pull/77（Closes #72）
- 承認された計画の要約: 新設 `Adapters\PartialPushException`（remote_id 付き）で、作成が確定した後に止まった商品の remote_id を `Sync\Exporter` まで運び、checksum=null で mapping を書く（次回は PUT）。`ColorMeAdapter::push_product()` は作成経路だけ、商品本体成功後の処理全体を1つの `try/catch (Throwable)` で包む。`Exporter` は `PartialPushException` を `created|updated`＋`PUSH_INTERRUPTED_AFTER_CREATE` の結果に読み替えて通常の書込み経路へ流し、原因がレート制限なら mapping を書いた後に再スローする。`PlatformAdapter` のシグネチャは変えない（D20 の `BASELINE` 不変）
- コミット:
  - `4037a25` fix: carry the remote id when an export is interrupted after a create (D21-A)
  - `017c232` docs: record the R3-0a implementation and its deviations from D21-A
  - `68c7dc1` docs: record the R1 review of the partial-push fix (R3-0a)
  - 以降: G1 の記録と最終報告の `docs:` コミット
- 設計ドキュメントからの逸脱（`docs/03` §10.2 D21-A「実装（R3-0a、issue #72）」に記録。いずれも承認済みの計画どおり）:
  1. 更新経路（既存 remote_id への PUT）は包まない。mapping が既にあり重複しないため。外部アダプタが更新経路で `PartialPushException` を投げた場合は `updated`＋`warned` に数える
  2. remote_id が空の `PartialPushException` は、D21 の「印を残す」ではなく本来の原因を投げ直す（R3-0a 時点では印が無いため）。**R3-0b では「その他の例外」行（印を残す）として扱うこと**
  3. 結合テストは `ColorMeAdapterTest` に置いた（HTTP モック helper の再利用のため）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Critical/High/Medium 0 件 | 0 | 5（対象外 Medium 1・Low 4） |

R1 は APPROVE（コード修正なし）。自己レビュー＋独立サブエージェント（opus）。記録は `R1.md`。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（「major な指摘なし」。対象 68c7dc1。PR 作成時の自動レビューが発火せず、依頼 1 回として review コメントを自動投稿して応答が届いた） |
| G1 | Copilot | 0 | 0 | 0 | 収束（`Findings: None`・インライン 0 件。判定は「🔵 Needs a closer look」＝最終的な人間の確認が要る、という定型の総評） |

### 修正した指摘
なし

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（未解決のボットスレッドは 0 件）

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36245821705 green（PHP quality 8.2/8.3・PHPUnit〔wp-env〕・JS/TS・Dev tooling。対象 HEAD 68c7dc1）
- 品質チェック: green（`composer lint`・`composer analyze`・`composer test:wpenv` 1128 件・`npm run lint`・`npm run build`・開発補助スクリプトの回帰テスト）
- ミューテーション 7 種（包む処理・`Exporter` の catch・`indicates_unresolved_reference()` への登録・再スローの位置〔mapping 書込みの前／再スロー無し〕・空 remote_id の分岐・更新経路を包む）を1つずつ入れて、対応するテストが落ちることを確認

## 次にできること（人間の判断）
- **マージ前に確認してほしい点**
  - Copilot の「🔵 Needs a closer look」は、自動承認できないという総評で指摘ではない。データ書込みの重複防止に関わる変更なので、`Sync\Exporter::process_items()` の2重 try/catch と `ColorMeAdapter::push_product()` の包む範囲は人間の目でも確認してほしい
  - **R1-X1（対象外・Medium・既存挙動）**: hidden 安全策（価格を換算できない・`tax_class` が未知のとき、作成だけ `display_state=hidden` に倒す）が更新経路には効かないため、安全策付きで作成された商品は次回の更新 PUT で価格未設定／税区分が誤ったまま公開されうる。本 PR の「作成後に中断→再開」でも同じ経路を通るが、中断が無くても次回 export で同じ（従来は重複作成に隠れていた）。GitHub issue にするか、v1.0 に含めるかの判断が要る（`docs/review-backlog.md` の `fix-72-partial-push/R1-X1`）
- 実 API では意図的に起こせないため実機確認はしていない（R3-1 のモック確認に含める）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: R3-0b（#73、D21-B: push intent）。上の逸脱 2（空 remote_id）と、backlog `fix-72-partial-push/R1-L4`（`push_customer`/`push_order`/`push_coupon` の docblock から契約を参照）を、その PR で合わせて扱う
