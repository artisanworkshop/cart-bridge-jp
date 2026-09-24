# dev-cycle 最終報告: fix/59-export-price-tax-and-variation-sale

## 開発内容
- タスク: issue #59（税抜入力店舗で税抜価格を税込として換算して push する）+ issue #60（バリエーションのセール価格を運べず定価で push する）
- PR: #61 https://github.com/artisanworkshop/cart-bridge-jp/pull/61（`Closes #59` / `Closes #60`）
- 承認された計画（plan file `curried-crafting-moler.md`）の要約:
  - **#59**: 新設 `Woo\Support\TaxInclusivePrice` が店舗の基準所在地の税率（`WC_Tax::get_base_tax_rates()`）で税込へ換算する。`wc_get_price_including_tax()` は顧客ロケーションが空の文脈で無変換のまま返す（実測: 基準所在地 JP・税率 10% 登録済みでも `999.0`）ため使わない。換算するのは「税計算 ON・税抜入力・課税商品」だけ。税率は登録済みだが基準所在地に合致しない場合はフェイルクローズ（`PRICE_TAX_BASIS_UNRESOLVED`、blocking）。
  - **#60**: セール中のバリエーションにだけ `variants[].sale_price` を載せ（セール外はキー無し＝checksum 不変）、`push_variant_details()` が `option_price`＝実売価格・`option_market_price`＝定価で送る。
- コミット一覧:
  | sha | メッセージ |
  |---|---|
  | 9863f11 | fix: normalize exported prices to tax-inclusive and carry variation sale prices |
  | 708b860 | docs: record the export price tax normalization and variation sale price fix |
  | 07c3931 | fix: address review R1 findings for the export price fix |
  | da30d9e | docs: record review-loop rounds R1 and R2 for the export price fix |
  | e9f56a7 | docs: record dev-cycle gate round G1 (all Copilot findings held) |
  | 9547397 | docs: record dev-cycle gate round G2 (finding held, rationale documented) |
  | 2b4428c | fix: validate the variation sale price at the ColorMe adapter boundary |
  | 7f22a86 | docs: record dev-cycle gate round G3 |
- 設計ドキュメントからの逸脱（PR 本文に記載。**マージ前にご判断ください**）:
  1. エクスポート方向のみ税換算を行う（docs/03 §5「税の扱い」は取込み方向の「警告のみ」）。インポート方向の鏡像（`ProductWriter` が税込額を税抜入力店舗へ書くと二重課税になりうる）は対象外で、警告のみのまま backlog に残した。
  2. `PRICES_INCLUDE_TAX_DISABLED` を Reader では出さなくなった（税計算 OFF の既定環境では無変換が正しく誤検知だったため）。
  3. 基準所在地に合致しない税率のみの店舗は blocking（フェイルクローズ）。
  4. issue #59 の「税率が解決できない場合はフェイルクローズ」を、Woo の実挙動（税率ゼロ＝課税なし）に合わせて「税率は登録済みだが基準所在地に合致しない場合のみ」に絞った。
  5. **未確認（要検証）**: `option_market_price` の税基準が `option_price` と同じという仮定（実店舗未確認。`docs/03` §10.2 に記載、`docs/10-tasks.md` の R3-1 に実機確認項目を追記）。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（独立サブエージェント併用） | Medium 2 / Low 5 / 対象外 2（Critical・High なし） | Medium 2 + Low 3 | Low 2 + 対象外 2 |
| R2（修正差分の検証・ミューテーション実測含む） | R1 指摘の全解消を確認、新規 0 | — | — |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（`da30d9ef55` に「no major issues」） |
| G1 | Copilot | 3（Low） | 0 | 3 | 未収束 |
| G2 | Copilot | 1（本文指摘・Low）＋G1 の再掲 3 | 0 | 1 | 未収束 |
| G3 | Copilot | 1（インライン） | 1 | 0 | 依頼 3 回に達したため打ち止め（4 回目は依頼しない） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G3-1 | Copilot | Medium→Low | 外部境界の `sale_price`（0・負値・定価超え）を `option_price` にそのまま送りうる → 販売価格が 0 超・定価以下（どちらも換算可能）のときだけ送り、それ以外は両方省く | 2b4428c | [r4090024208](https://github.com/artisanworkshop/cart-bridge-jp/pull/61#discussion_r4090024208)（Resolve 済み） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Copilot | Low | バリエーションの `option_market_price` の税基準が未確認。表示用の定価で課金額ではなく、実売価格は換算済みで正しい。R3-1 の実機確認項目に追記 | [r4089899143](https://github.com/artisanworkshop/cart-bridge-jp/pull/61#discussion_r4089899143) |
| G1-2 | Copilot | Low | 親と異なる税区分のバリエーションの換算。指摘の前提が実測と異なる（バリエーション個別の税区分で換算する方が支払額が正しい: 1080 円。親に揃えると 1100 円で誤る）。ColorMe に表現できない税区分の情報警告は backlog | [r4089899181](https://github.com/artisanworkshop/cart-bridge-jp/pull/61#discussion_r4089899181) |
| G1-3 | Copilot | Low | 価格小数桁が 0 超の店舗で換算結果が切り捨てられる。R1-L2 と重複。丸め方針は `to_push_amount()` 全体に効く別判断 | [r4089899212](https://github.com/artisanworkshop/cart-bridge-jp/pull/61#discussion_r4089899212) |
| G2-1 | Copilot | Low | 実売価格換算不能時に `null` で既存価格を消すべき → 実フィクスチャで `option_price: null` が商品レベル価格へフォールバックすると確認。`null` は高い側のバリエーションの売価を誤るため、省略（既存値を保持）が安全側。本文指摘のためスレッド無し（`G2.md` が処理記録） | — |

## 品質ゲート
- CI: 最終コード HEAD `7f22a86` で PHP quality (8.2/8.3)・PHPUnit (wp-env)・JS/TS すべて green。https://github.com/artisanworkshop/cart-bridge-jp/pull/61/checks
- 品質チェック（ローカル）: `composer lint` / `composer analyze`（PHPStan）/ `composer test:wpenv`（**1113 件**）/ `npm run lint` / `npm run build` すべて green
- 結合テスト `ColorMeExportPricesTest`（Woo 実データ → `ProductReader` → `ColorMeAdapter::push_product()`）は、換算を無効化するミューテーションで失敗することを確認済み

## マージ前に確認してほしい点
- 上記の設計ドキュメントからの逸脱 1〜5（特に 3: 基準所在地不一致の店舗を blocking にする判断）。
- Copilot は依頼 3 回で打ち止めのため「収束」ではなく「Low の保留 4 件あり」の状態。未解決スレッド 3 件はそのまま残してある（判断済みで、理由は各スレッドに返信済み）。
- **実店舗・実 API では未検証**（wp-env と単体・結合テストのみ）。`option_market_price` の税基準は R3-1 の実機確認項目。
- 影響: 税抜入力店舗の商品（価格が変わる）とセール中バリエーションを持つ商品は、リリース後の初回 export で canonical が変わり 1 回だけ再 push される（ColorMe 側は既存 remote_id への PUT で重複しない。意図どおり）。
- 既存テスト `ProductWriterTest::test_category_ref_pointing_to_deleted_term_is_treated_as_unresolved` がランダム順（seed 987）で失敗する既存の順序依存を見つけた（デフォルト順の CI は通る）。backlog `fix-59-export-price-tax/R1-X1` に記録。

## 次にできること（人間の判断）
- 保留分の修正: `/dev-cycle fix G1-2 G1-3 G2-1` または `/fix-copilot-review 61`
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`（CLAUDE.md / `.claude/rules/` への学びの蒸留はそこで行う。この PR で `.claude/rules/woocommerce-api.md` に `wc_get_price_including_tax()` のロケーション依存を追記済み）
- 次の候補: #38（受注のサンプル選定を ID 指定取得へ）、#55（`LimitsUpsellNotice` の誤表示）、R3-5（アンインストールオプション UI）
