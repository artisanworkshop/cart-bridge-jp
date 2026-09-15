# dev-cycle 最終報告: feat/e2-3-push-product

## 開発内容
- タスク: E2-3 PR-A（PushResult契約拡張 + ColorMe `push_product()`実装。`docs/10-tasks.md` Phase 2）
- PR: [#43](https://github.com/artisanworkshop/cart-bridge-jp/pull/43)
- 承認された計画の要約: E2-3（商品→顧客→受注→在庫のColorMe push実装）はE2-2同様に分割が必要な
  規模のため、本セッションは**PushResult契約拡張 + `push_product()`（商品のみ）**に絞った。
  `push_customer`/`push_order`/`push_stock`は次PR以降。
- コミット一覧（実装3件 + review-loop修正2件 + gate修正3件、状態記録は`docs:`コミット省略）:
  - `3bfe45c` feat: add PushResult variant contract and ColorMe push_product() (E2-3 PR-A)
  - `5bc1626` fix: correct ColorMe option payload schema and add failure classification (R1)
  - `d6d5c6b` fix: scope hidden-forcing to create and fail closed on axis-name collisions (R2)
  - `046e226` fix: harden export-blocking, tax rounding direction, and failure classification (G1)
  - `4dba073` fix: fail closed on partial axes, empty responses, and unsupported tax classes (G2)
  - `3afeb60` fix: avoid mutating ColorMe before axis collision check, guard update tax_reduced (G3)

### 設計ドキュメントからの逸脱
- `docs/01-plan-colorme.md`の想定より、カラーミーの商品書込APIは制約が多いと判明した
  （swagger実測。作成POSTは13項目のみ、バリエーションは専用作成APIが無くオプション追加に
  よる自動生成方式）。`push_product()`は単一リクエストではなく多段階のリクエスト列になった。
- 複数リクエストから成るpushの部分完了は、`PushResult`に新規フィールドを追加せず、既存の
  `WarningCode::indicates_unresolved_reference()`機構（再試行対象）の拡張で表現した
  （計画時点では新規フィールドを想定していたが、実装時に既存機構の再利用で十分と判断）。
- 画像URL一覧CSV出力フロー（`docs/01-plan-colorme.md` §5-2）は本PRに含めず、
  `WarningCode::PRODUCT_IMAGES_NOT_PUSHED`警告として結果に残すのみとした（集約UIはE2-4スコープ）。

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | High 3・Medium 4・Low 6・対象外1 | High 3・Medium 4 | Low 6・対象外1 |
| R2（検証・APPROVE） | 新規Medium 2（R1修正の副作用） | Medium 2 | Low 3 |

自己レビュー＋独立サブエージェント（general-purpose/opus）による敵対的レビューを実施。R1では
「`POST /options`の`values`がオブジェクト配列必須（swagger実測）」という、修正しなければ
variable商品のバリエーションが1件も作れない致命的バグを検出した。詳細は`R1.md`/`R2.md`参照。

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 10スレッド+8本文 | 8+2 | 2+6 | 未収束（保留分は返信済み・backlog記録） |
| G1 | Codex | 9スレッド | 3 | 6 | 未収束 |
| G2 | Copilot | 2スレッド+4本文 | 2+2 | 0+2 | 未収束 |
| G2 | Codex | 4スレッド | 1 | 3 | 未収束・**依頼上限（3回）到達** |
| G3 | Copilot | 1スレッド+4本文 | 1+3 | 0+1 | ほぼ収束（保留1件は既存backlog項目の再指摘）・**依頼上限（3回）到達** |

両ボットとも依頼上限に到達したためG3でbot gateを終了した（「収束」ではなく「依頼上限到達」。
Codexの初回自動レビューが15分TIMEOUTしたためユーザー確認のうえ`@codex review`で再依頼した実績あり）。

### 修正した指摘（主なもの。全リストは`G1.md`〜`G3.md`参照）
| ID | bot | 重大度 | 内容 | コミット |
|---|---|---|---|---|
| G1-2/G1-6 | Copilot | High | `PRODUCT_PRICE_INVALID`（0円価格の恒久公開）をexport blocking化 | 046e226 |
| G1-4 | Copilot | High | `VARIATION_AXIS_LIMIT_EXCEEDED`（3軸以上のバリエーション衝突）をexport blocking化 | 046e226 |
| G1-8 | Copilot | High | `TAX_STATUS_NOT_TAXABLE`（非課税・送料のみ課税商品）をexport blocking化 | 046e226 |
| G1-13 | Codex | P2 | 税丸め方向の逆転（`php -r`で6000通り検証、round_down/round_up修正） | 046e226 |
| G1-3/G1-18 | Copilot/Codex | High/P1 | 追いPUTがhidden安全策を即座に上書きしていた不具合 | 046e226 |
| G1-10 | Copilot | High | variant数不一致時も親商品のchecksumがキャッシュされる不具合 | 046e226 |
| G2-5 | Copilot | High | Wooの「Any属性」ワイルドカードでバリエーション取り違えが起きうる不具合 | 4dba073 |
| G3本文 | Copilot | - | 軸名衝突の検出をColorMe側へのリクエスト送信前に移動（余剰データ作成の回避） | 3afeb60 |
| G3本文 | Copilot | - | 更新時の`tax_reduced`上書き問題（既存税区分を誤って標準税率へ書き換え） | 3afeb60 |

### 修正しなかった指摘（PR上で未解決のまま残してある。`docs/review-backlog.md`参照）
| ID | bot | 重大度 | 理由 |
|---|---|---|---|
| G1-1/G1-11 | Copilot/Codex | High/P1 | remote_id確定後の失敗（レート制限等）による重複商品作成リスク。`RateLimitExhaustedException`等の契約拡張が必要で本PR差分範囲を超える（backlog `G1-duplicate-on-retry`） |
| G1-9/G1-12 | Copilot/Codex | High/P1 | `PRICES_INCLUDE_TAX_DISABLED`未考慮の税換算誤り。**一度export blocking化を試みたが、多くの実店舗の既定設定で発火するため取り消した**（backlog `G1-out-of-scope-prices-include-tax`） |
| G1-15/16/17/19 | Codex | P2 | 更新時のnullフィールド省略問題・プラン変更後のchecksum無効化・商品名文字数検証・404削除時のstale mapping。いずれも実機検証または設計判断が必要（backlog参照） |
| G2-4 | Codex | P1 | バリエーションのセール価格が運ばれない。`ProductReader`（本PR差分範囲外）の変更が必要（backlog `G2-variation-sale-price`） |
| G3本文B4 | Copilot | - | count一致だが個別variant_remote_idが空文字列＋無警告のケース（backlog `G1-L2`と同根） |

## 品質ゲート
- CI: [run](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/34910066638) green
  （PHP quality 8.2/8.3・JS/TS・PHPUnit wp-env、以降の各ラウンドでも都度green確認）
- 品質チェック: green（`composer lint`/`composer analyze`/`composer test:wpenv` 888件・
  `npm run lint`/`npm run build`）
- 実機確認: **未実施**。計画では「実機pushまで行う」方針だったが、wp-envのColorMe接続確立
  （デベロッパーコンソールでのリダイレクトURI更新等、ユーザー操作が必要）に至る前にreview-loop・
  bot gateの対応に時間を要したため、本セッションでは完了できなかった。次のステップで実施を推奨。

## ユーザーに判断・確認してほしい事項

1. **PRのマージ判断**: 上記「修正しなかった指摘」のうち、特に以下2件は金銭的リスク・データ
   品質に関わるため、マージ前に目を通すことを推奨する（いずれも本PRの差分範囲外の既存コード
   `ProductReader`/`RateLimitExhaustedException`の変更が必要と判断したため見送った）:
   - remote_id確定後の失敗による商品重複作成リスク（`G1-duplicate-on-retry`）
   - バリエーションのセール価格が運ばれない（`G2-variation-sale-price`）
2. **実機確認**: wp-envのColorMe接続（テストショップ`cdtlda7cuk`）を再確立し、実際にテスト
   ショップへ商品をpushして動作確認することを推奨する。デベロッパーコンソール
   （developer.shop-pro.jp）でのリダイレクトURI更新（現行ポート8895向け）にユーザー操作が必要。
3. **`PRICES_INCLUDE_TAX_DISABLED`の扱い**: G1で一度export blocking化を試みたが、多くの実店舗
   の既定設定（税抜で価格入力）で発火し無料版の挙動確認自体ができなくなるため取り消した。
   正しい修正は`Woo\Reader\ProductReader`側での税込基準への正規化だが、本PR差分範囲外。
   対応の優先度を判断してほしい。

## 次にできること（人間の判断）
- 保留分の修正: 個別に対応する場合は該当ファイルを直接編集するか、`/fix-copilot-review 43`で
  未解決スレッドを1件ずつ確認しながら対応できる
- マージ（GitHub上で人間が行う）→ マージ後は`/post-merge`
- マージ後、`push_customer`/`push_order`/`push_stock`（E2-3の残り）に着手する場合は
  `/cbj-dev-cycle`を再度起動する
