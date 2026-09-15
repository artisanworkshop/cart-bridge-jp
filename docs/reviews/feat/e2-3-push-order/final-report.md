# dev-cycle 最終報告: feat/e2-3-push-order

## 開発内容

- タスク: E2-3 `push_order()` 実装（`docs/10-tasks.md` Phase 2、`push_product`/`push_customer`に続く3つ目のpush系メソッド）
- PR: [#45](https://github.com/artisanworkshop/cart-bridge-jp/pull/45)
- 承認された計画の要約: `POST /v1/sales`のペイロード組み立て、D19申し送り（`payment_map`/`shipping_map`逆引きの曖昧性）の解決、`PlatformAdapter::push_order()`のシグネチャ統一（既存remote_id受け取り→重複pushの防止）、共有ロジック（住所変換・電話番号正規化）の`CustomerTransformer`からの抽出
- コミット一覧（新しい順）:
  - `9443e75` docs: finalize G3.md HEAD reference
  - `aa90723` docs: address Copilot G3 findings, record Codex usage-limit stop
  - `fbc3276` docs: finalize G2.md HEAD reference
  - `132e6af` fix: address Codex/Copilot G2 review findings on PR #45
  - `d410c9f` docs: finalize G1.md HEAD reference
  - `8e2c928` fix: address Codex review findings on PR #45
  - `fd8b25f` docs: record gate round G1 (Copilot)
  - `6f9ee6b` fix: address Copilot review findings on PR #45
  - `04fe6b0`/`bfd8a95`/`677b22c` docs: R2確定・記録
  - `6456dc6` fix: address R2 verification findings for push_order()
  - `a78c309` docs: record E2-3 PR-C R1 review round
  - `be533d3` fix: address R1 review findings in push_order()
  - `b22f37f` docs: record E2-3 PR-C push_order design decisions
  - `3448ea3` feat: add ColorMe push_order() (E2-3 PR-C)
  - `7adcc5d` refactor: extract shared ColorMe address/tel payload helpers

### 設計ドキュメントからの逸脱（計画時点で想定済み）

- `PlatformAdapter::push_order()`のシグネチャを`(CanonicalOrder, ?string $remote_id)`へ変更（`push_product`/`push_customer`/`push_coupon`と統一）。確定版インターフェース（`03 §2`）への変更だが、D19と同じ「外部アドオンによるカスタムアダプタが現時点で存在しないためリスクは低い」という判断を踏襲
- `CustomerTransformer`の住所ペイロード変換・電話番号正規化ロジックを`Woo\Support\AddressMapper`/`Adapters\ColorMe\Transform\Cast`へ抽出（ロジック変更なし。D19 PR-Bの「対称の変換を複製すると2箇所が食い違うリスクを負う」方針を踏襲）

### review-loop中に判明し撤回・強化した設計判断（要判断として残したものを含む）

- 明細単価を復元できない場合（tax_type不明・数量で割り切れない）の扱いを、計画時点では「`price`を省略してカラーミーのカタログ価格へフォールバック」としていたが、review-loop（Codex G2）でこの設計自体が恒久的な金額の食い違いを生むと判明し、**受注全体をブロックする**方針へ撤回・変更した
- **要判断として残したもの**（`docs/review-backlog.md`参照）:
  - `e2-3-push-order/R1-H2`（Medium）: 顧客が未exportのまま受注が先にゲスト扱いでpushされると、後から顧客がexportされても会員紐付けを恒久的に復元できない
  - `e2-3-push-order/G2-wildcard-variation-option-values`（High・要検証）: Wooの「Any &lt;属性&gt;」ワイルドカードバリエーションで選択値を特定できない可能性（`OrderReader.php`が本PRの差分範囲外のため未検証のまま記録）
  - `e2-3-push-order/G1-duplicate-on-retry`（High・対象外）・`G1-stale-mapping-on-404`（Medium・対象外）: `push_product`/`push_customer`と同根の既存の限界

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | High 2・Medium 3（自己レビュー+独立サブエージェント敵対的レビュー） | 5件すべて修正 | Medium 1件（R1-H2） |
| R2（検証ラウンド） | Medium 2・Low 1（独立サブエージェントによるミューテーションテスト含む検証） | 3件すべて修正 | ― |

判定: **APPROVE**（`docs/reviews/feat/e2-3-push-order/R1.md`, `R2.md`）

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2（High 1・Medium 1） | 2 | 0 | 次ラウンドへ |
| G1 | Codex | 6（P1×5・P2×2の一部重複あり） | 3 | 3 | 次ラウンドへ |
| G2 | Copilot | 2（High 1・Medium 1） | 2 | 0 | 次ラウンドへ |
| G2 | Codex | 3（P1×3） | 2 | 1 | 次ラウンドへ |
| G3（最終） | Copilot | 2（Low 2） | 1 | 1 | **収束**（新規Critical/Highゼロ） |
| G3（最終） | Codex | ― | ― | ― | **未確認**（アカウント利用上限で応答不能。依頼回数は3回に到達済み） |

### 修正した指摘（抜粋。全件は各`G<n>.md`参照）

| ID | bot | 重大度 | 内容 | コミット |
|---|---|---|---|---|
| G1-1 | Copilot | High | 明細単価の端数丸めで合計がずれる（¥1000÷3個→333×3=999） | `6f9ee6b` |
| G1-4/G1-5 | Codex | P1 | 決済手数料・送料・受注日時を運ぶAPIフィールドが無く、情報提供の警告が欠落 | `8e2c928` |
| G2-1 | Codex | P1 | 「単価省略→カタログ価格フォールバック」という直前の修正自体が恒久的な金額食い違いを生む。受注全体をブロックする方針へ撤回 | `132e6af` |
| G2-3/G2-Copilot | Codex・Copilot（独立に同一指摘） | P1 | 非課税等の税区分がexport blockingに含まれていない | `132e6af` |
| G2-5 | Copilot | High | 空白のみの住所が請求先へフォールバックせず配送先として誤採用される | `132e6af` |

### 修正しなかった指摘（PR上で未解決のまま残してある。理由は各`G<n>.md`/`docs/review-backlog.md`参照）

| ID | bot | 重大度 | 理由 |
|---|---|---|---|
| G1-3 | Codex | P1 | dry-runはアダプタを呼ばない既存の設計制約（`push_customer`と同根の既知の限界） |
| G1-6 | Codex | P1（対象外） | POST成功後の応答喪失で重複受注の可能性。`push_product`/`push_customer`と同根、契約拡張が必要で本PR範囲外 |
| G1-7 | Codex | P2（対象外） | 削除済みリモート受注への404を検知できない。対応方針が異なるため個別記録 |
| G1-8 | Codex | P2（保留） | create-sale非対応の決済種別のフィルタリング未実装。要追加調査 |
| G2-2 | Codex | P1（対象外・要検証） | ワイルドカードバリエーションの選択値喪失の可能性。`OrderReader.php`が本PRの差分範囲外、実機未検証 |
| G3-2 | Copilot | Low（保留） | 非数値`customer_ref`の理論上の境界値ケース。現状到達経路なし |

## 品質ゲート

- CI: [run 34957824800](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/34957824800) green（1回、PHPUnitジョブのDocker build中のHTTP 504タイムアウトによる失敗をrerunで解消。コード起因ではない）
- 品質チェック: `composer lint && composer analyze && composer test:wpenv` green（957テスト、G3はドキュメントのみの変更のためテスト数は変化なし）

## 次にできること（人間の判断）

- 状態が「未確認」のCodexについて: アカウントのレビュー利用上限に達したため3回目は内容のあるレビューが得られていない。利用枠が回復した時点で`/fix-copilot-review 45`を実行し、遅れて届く指摘が無いか確認することを推奨
- 保留分（上記「修正しなかった指摘」）のうち、対応が必要と判断したものがあれば `/dev-cycle fix <ID>` または `/fix-copilot-review 45` で個別に対応可能
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
