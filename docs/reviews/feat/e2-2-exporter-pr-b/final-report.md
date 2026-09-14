# dev-cycle 最終報告: feat/e2-2-exporter-pr-b

## 開発内容
- タスク: E2-2「Exporter パイプライン PR-B」— Woo→ASP（ColorMe）エクスポート方向の
  `Woo\Reader\CustomerReader`/`OrderReader`/`StockReader`/`CouponReader`を新規実装し、
  `Sync\JobManager`/`Woo\Export\AdapterPlatformWriter`/`Woo\WarningCode`を拡張して
  PR-A（#40、product エンティティのみ）を4エンティティ全て対応へ拡張した。
- PR: #41 https://github.com/artisanworkshop/cart-bridge-jp/pull/41
- 承認された計画の要約（`/Users/shoheitanaka/.claude/plans/federated-soaring-salamander.md`）:
  - A. Customer/Orderのアドレスは Woo ネイティブキーのまま運ぶ（ColorMe変換は E2-3 の責務）
  - B. 受注明細の`remote_product_id`は`cbjp_mappings`で解決（payment/shipping method IDとは別扱い）
  - C. StockReaderは商品を「販売単位」に展開（simple=1件、variable=公開バリエーション1件ずつ）
  - D. CouponReaderの`fixed_product`型は`has_unsupported_restrictions=true`で保存見送り
  - E. `JobManager`のサンプリング判定バグ修正（stockが`'product'`の上限を基準に判定するよう統一）
  - F. couponはサンプルID方式を使わず独立の`LimitPolicy`カーソル走査のみ
  - G. `filter_and_order_export_entities()`にcapabilityゲートを復活
- コミット一覧（chronological）:
  | sha | メッセージ |
  |---|---|
  | 8e68249 | feat: add Customer/Order/Stock/Coupon export readers (E2-2 PR-B) |
  | 62ee45d | docs: record E2-2 PR-B export reader design decisions |
  | 400ceeb | fix: address R1 review-loop findings in export readers |
  | 27bfa4c | docs: record R1 review-loop findings for E2-2 PR-B |
  | 1076d9e | docs: record dev-cycle R1 review round for feat/e2-2-exporter-pr-b |
  | f7651fb | fix: address R2 verification findings for E2-2 PR-B |
  | 035ad1f | docs: record dev-cycle R2 review round for feat/e2-2-exporter-pr-b |
  | 70bb373 | docs: record PR #41 in dev-cycle state for feat/e2-2-exporter-pr-b |
  | b2fb4d9 | fix: address Copilot review findings on PR #41 (G1) |
  | 0ed13ea | docs: record G1 (Copilot) gate round for PR #41 |
  | 741269b | fix: address Codex review findings on PR #41 (G1) |
  | 84068c0 | docs: record deferred Codex findings for E2-2 PR-B in review-backlog |
  | b55b3a3 | docs: record Codex portion of dev-cycle gate round 1 for PR #41 |
  | 8a20e29 | docs: record dev-cycle round 2 bot request for PR #41 |
  | edae324 | fix: address Copilot round-2 review findings on PR #41 (G2) |
  | b52c17a | docs: record dev-cycle gate round 2 for PR #41 |
  | 8092037 | docs: record dev-cycle round 3 (final) bot request for PR #41 |
  | 1c2c0ad | fix: address Copilot round-3 review findings on PR #41 (G3) |
  | dee1b8e | docs: record dev-cycle gate round 3 (final) for PR #41 |

### 設計ドキュメントからの逸脱（あれば）
なし。上記A〜Gの計画は`docs/03-design-decisions.md` §10.2「エクスポート方向の実装（E2-2
PR-B）」へそのまま反映済み。ゲートラウンドで追加した`option1_value_current`/
`option2_value_current`（Codex #6対応。バリエーション明細の親商品ID解決とセットで導入）は
計画Bの自然な拡張であり、既存方針との矛盾はない。

## review-loop（PR前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | 13件（自己レビュー2 + 独立サブエージェント11。High/Medium） | 13件 | Low 6件 |
| R2（検証） | 新規2件（Medium。R1指摘は全件解消確認） | 2件 | 0件 |
| 判定 | APPROVE（R2で新規Critical/High 0件） | | |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留/誤検知 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 11（inline 7 + 本文4） | 9 | 2（実測で誤り/過大） | 収束（新規0件は未検証。3回目未実施） |
| G1 | Codex | 15（commit_id確認: 1回目はstale review、2回目が最新） | 10 | 誤検知2・backlog3 | 収束（新規0件は未検証。利用上限で以降レビュー不可） |
| G2 | Copilot | 5（inline 2 + 本文3） | 4 | 1（実測で誤り） | 未収束（新規4件） |
| G2 | Codex | - | - | - | **未確認**（利用上限で未レビュー。TIMEOUTではなく外部サービス制約） |
| G3 | Copilot | 4（inline 2 + 本文2） | 2 | 2（実測で誤り） | 依頼上限（3回）到達。新規0件は未検証のまま終了 |

状態の補足: 「収束」は新規指摘が実際に0件だったことを指すが、本PRでは3ラウンドとも
Copilot・Codexいずれも何らかの新規指摘を出し続けた（G3まで新規0件のラウンドが一度もない）。
Copilotは依頼上限（3回）に達したため運用ルール上ここで終了する。Codexは2回目の依頼が
利用上限（Codex usage limits for code reviews）によりレビューされず、3回目は依頼していない
（依頼しても同じ結果になる可能性が高いと判断）。**いずれも「指摘を出し尽くした」ことを意味
しないため、後日の`fix-copilot-review`による再確認を推奨する**（下記「次にできること」参照）。

### 技術的発見（複数ラウンドにまたがる重要な知見）
- `WC_Order_Item_Product::set_product_id()`/`set_variation_id()`は投稿タイプ検証を持ち、
  参照先が削除済みだと`WC_Data_Exception`を投げる。データストアの`read()`が呼ぶ
  `WC_Data::set_props()`はこれをプロパティ毎にcatchするため、`get_product_id()`/
  `get_variation_id()`自体が既定値`0`を返してしまい、削除済み参照と「一度も持たない」参照が
  CRUD層では区別できなくなる（`WC_Coupon::set_amount()`と同じパターン）。生のorder-item-meta
  を`get_metadata()`で直接読んで区別する実装が必要だった（Codex #11・Copilot G2で発見）。
- WooCommerce本体は既定で`add_filter('woocommerce_stock_amount', 'intval')`を登録し、
  数量を常にintへ丸める。小数量拡張（量り売り等）はこの既定フィルターを外すため、
  `strict_types=1`下でfloat数量が`int`引数の関数へ渡されるとTypeErrorになりうる（Codex #10）。
  テストでもこの既定フィルターを一時的に外して再現する必要があった。
- WooCommerceの在庫切れは`stock_status`が明示的に`outofstock`でも`stock_quantity`が正のまま
  ということがあり、`woocommerce_notify_no_stock_amount`（在庫切れ通知のしきい値）設定で
  `manage_stock=true`かつ`save()`の度に`WC_Product::validate_props()`がこの状態を作りうる
  ことを実測確認（Codex #3）。
- `WC_Coupon::set_date_expires()`/`set_minimum_amount()`は`set_amount()`と異なる検証経路を
  持つため、「set_props()がプロパティ毎に例外を握りつぶす」パターンが必ずしも同じ安全な結果に
  ならない。前者は解釈不能な値をUNIXエポック（既に期限切れ）へ安全側に解決する一方
  （Codex #15、誤検知と判明）、後者は検証自体を持たず壊れた値をそのまま返す（Copilot G3、
  真の指摘）。「似たプロパティだから同じ挙動のはず」という推測が両方向に外れうることが分かった。

### 修正した指摘（PR上でResolve済み、または本文記録）
主要なもののみ抜粋（全件は G1.md/G2.md/G3.md 参照）:
| ID | bot | 内容 | コミット |
|---|---|---|---|
| G1-C1/Codex#1 | Codex | 通貨不一致のexport blocking | 741269b |
| G1-C6/Codex#6 | Codex | バリエーション明細の親商品ID解決+option値 | 741269b |
| G1-C11/Codex#11 | Codex | 削除済み商品参照のblocking（get_product_id()の0リセット発見） | 741269b |
| G1-C12/Codex#12 | Codex | 返金済み注文のblocking | 741269b |
| G2-1/G2-2 | Copilot | バリエーション削除済み参照・軸警告伝播のblocking | edae324 |
| G2-3 | Copilot | クーポンstale IDレースのスキップ | edae324 |
| G2-5 | Copilot | 商品リンクなし行のblocking | edae324 |
| G3-3/G3-4 | Copilot | クーポン負の最低購入金額・手数料/送料clampの警告化 | 1c2c0ad |

### 修正しなかった指摘（PR上で未解決のまま残してある）
| ID | bot | 理由 | スレッド |
|---|---|---|---|
| G1codex-#7 | Codex | sale_deliveries保持。アーキテクチャ原則1に照らした設計判断が必要、E2-3未実装で消費経路なし | discussion_r4002704275 |
| G1codex-#8 | Codex | 顧客extras保持。理由は#7と同じ | discussion_r4002704280 |
| G1codex-#9 | Codex | クーポンカーソル安定性。既存backlog（G2-M-pro-tier-pagination）と同基準で保留 | discussion_r4002704286 |

いずれも`docs/review-backlog.md`（e2-2-exporter-pr-b/G1codex-M-*）に理由を記録済み。

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/pull/41 （最終run: 4ジョブ全green）
- 品質チェック: `composer lint` / `composer analyze` green、`composer test:wpenv` 855 tests /
  2325 assertions green、`npm run lint` green
- CIでPHPStanのメモリ上限クラッシュ（`--memory-limit=1G`超過、並列ワーカー内）による一過性red
  が2回発生（PHP 8.3で1回・PHP 8.2で1回）。いずれもローカルで`composer analyze`を複数回green
  確認しコード起因でないと判断のうえ`gh run rerun --failed`で再実行し解消。再発する場合は
  ワークフロー側のPHPStanメモリ上限・並列度設定の見直しを検討する価値がある（本PRのスコープ外）

## 次にできること（人間の判断）
- **マージ判断**: GitHub上で人間が行う（`gh pr merge`は実行していません）
- **保留分の修正**: 上記3件（sale_deliveries/顧客extras/クーポンカーソル安定性）は
  `/dev-cycle fix G1codex-7 G1codex-8 G1codex-9`のように指定するか、`/fix-copilot-review 41`で
  対話的に見直せます
- **Codexの再確認を強く推奨**: Codexは2回目のレビューを利用上限（Codex usage limits for code
  reviews）により実施できていません。これはTIMEOUTとは異なり「指摘を出し尽くした」ことを
  意味しないため、Codexのアカウント側の利用上限（クレジット追加等）が解消され次第、
  `/fix-copilot-review 41`または`@codex review`コメントで改めてレビューを依頼し、G1修正
  （特にb2fb4d9→741269b以降の大きな変更）に対する指摘漏れが無いか確認することを推奨します
- **Copilotの再確認も検討可**: Copilotは3回（上限）に達しましたが、G3まで新規0件のラウンドが
  一度もなかったため、後日`/fix-copilot-review 41`で改めて依頼し新規0件を確認する価値があります
- マージ後は `/post-merge`