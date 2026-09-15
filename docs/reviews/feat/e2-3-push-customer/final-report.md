# dev-cycle 最終報告: feat/e2-3-push-customer

## 開発内容

- タスク: E2-3 PR-B — `ColorMeAdapter::push_customer()` の実装（Phase 2「Woo → カラーミー
  エクスポート」）
- PR: [#44](https://github.com/artisanworkshop/cart-bridge-jp/pull/44)
- 承認された計画の要約: `POST/PUT /v1/customers`による顧客の作成・更新を実装。新規作成必須の
  `pref_id`/`postal`/`address1`/`tel`をWoo顧客の請求先情報から解決できない場合はAPIを呼ばず
  スキップするフェイルクローズ設計（`push_product()`の`requires_hidden_safeguard()`と同じ思想）。
  ColorMe固有の住所・電話番号スキーム変換は`CustomerTransformer`に集約し、`Woo\Support\
  AddressMapper`にインポート方向`state_code()`の対称な逆関数`pref_id_from_state()`を追加。
- 実装コミット: `1d693db`（本体+テスト）、`462cd52`（docs）
- review-loop修正コミット: `6c0ca5e`（R1）、`1d58e76`（R2）
- ゲートラウンド修正コミット: `8044fbd`（G1）、`1a1e0de`（G2）、`a37a0f4`（G3）
- 設計ドキュメントからの逸脱: なし（計画時に提示した住所スキーム変換の置き場所は`AddressMapper`
  拡張案どおり実施）

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | High2・Medium1・Low2（自己+独立サブエージェント） | High2・Medium1・Low1を修正 | Low1（add_member通知メール、要検証#17へ） |
| R2 | 新規Medium1・Low1（R1修正自体が混入） | 両方修正、新規Critical/Highゼロ | なし |

判定: **APPROVE**（R2）

## Codex / Copilotゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 5件（3 inline+2 suppressed） | 1件（High） | 4件 | 未収束 |
| G1 | Codex | 4件（2件はCopilotと重複） | 2件（High1+Medium1） | 2件 | 未収束 |
| G2 | Copilot | 2件 | 1件（High、G1修正の組合せで新規混入） | 1件（**後にG3で訂正・修正**） | 未収束 |
| G2 | Codex | 2件 | 0件（裏取りの結果1件を根拠不十分として棄却） | 1件 | 未収束 |
| G3（最終） | Copilot | 0件新規（G2-1の裏付けのみ） | — | — | 依頼上限到達 |
| G3（最終） | Codex | 2件 | 1件（**Critical/High、G2-1の訂正**） | 1件 | 依頼上限到達 |

いずれも「収束」（新規指摘ゼロ）には至らず、依頼回数3回の上限に達したため終了。
**bot側は時間差で応答している可能性が高く、これ以上の指摘が無いと確定したわけではない。**

### ⚠️ 重要な訂正の経緯

G2で「WooCommerce coreとColorMeで都道府県4/5番の対応が入れ替わっている」という指摘を、
根拠不十分（swaggerフィクスチャ内の実例1件のみで裏取りし、JIS標準と一致すると誤って
一般化）として**却下**しました。G3で両bot（Codex/Copilot）が具体的根拠
（swaggerの`info.description`に埋め込まれた「都道府県コード一覧」表。構造化JSONスキーマ
としては提供されておらず見落としていた）を提示し、再検証の結果、**この指摘は正しく、
却下は誤りだった**ことが判明しました。ColorMeのpref_id採番はJIS X 0401標準・WooCommerceの
採番と47都道府県中約20件で一致せず、修正しました（詳細下記）。

## 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| R1-1 | 自己 | High | 住所`city`欠落（ネイティブWoo顧客の市区町村が丸ごと消える） | 6c0ca5e | - |
| R1-2 | 自己 | High | `tel`パターン未検証（装飾文字含む値がそのまま送られる） | 6c0ca5e | - |
| R1-3 | 自己 | Medium | `to_update_payload()`の住所部分更新による内部矛盾リスク | 6c0ca5e | - |
| R1-4 | 自己 | Low | PUT応答id欠損時の診断ログ欠如 | 6c0ca5e | - |
| R2-1 | 自己 | Medium | 海外住所の`city`+`address_1`連結に区切りが無い | 1d58e76 | - |
| R2-2 | 自己 | Low | `tel`正規表現の`$`が末尾改行にマッチ | 1d58e76 | - |
| G1-3 | Copilot | High | `pref_id_from_state()`の`$`が末尾改行にマッチ | 8044fbd | [#discussion_r4011402643](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011402643) |
| G1-4 | Codex | High(P1) | 海外住所で`state`/`country`がどこにも残らない | 8044fbd | [#discussion_r4011477752](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011477752) |
| G1-5 | Codex | Medium(P2) | `name`のAPI文字数制限(50)未検証 | 8044fbd | [#discussion_r4011477762](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011477762) |
| G1-6 | Codex | Medium(P2) | `state`/`country`の矛盾（stale state）未検知 | 8044fbd | [#discussion_r4011477771](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011477771) |
| G2-2 | Copilot | High | G1-4+G1-6の組合せで陳腐化した`JPxx`が海外住所に混入 | 1a1e0de | [#discussion_r4011591134](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011591134) |
| **G3-1**（=訂正されたG2-1） | Codex+Copilot | **Critical/High** | **ColorMeのpref_id採番がJIS標準/Wooと約20件不一致**（Phase 1既存コード含む） | a37a0f4 | [元](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011591096) / [新](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011680631) |

## 修正しなかった指摘（PR上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Copilot | High（対象外・要判断） | POST 2xxで`customer.id`欠如時の重複作成リスク。`e2-3-push-product/G1-duplicate-on-retry`と同根、`PlatformAdapter`契約拡張が必要で差分範囲外 | [#discussion_r4011402600](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011402600) |
| G1-2 | Copilot | Low（保留・設計判断） | `mail`/`name`非空性・`furigana`等の書式制約未検証。`push_product()`と同じ設計方針 | [#discussion_r4011402624](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011402624) |
| G1-7 | Codex+Copilot（重複） | Medium（対象外・要判断） | 削除済みColorMe顧客への更新PUTが404でもmapping未削除。`e2-3-push-product/G1-stale-mapping-on-404`と同根 | [#discussion_r4011477781](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011477781) |
| G2-3 | Codex | 棄却（根拠不十分） | mbstring拡張未宣言。WordPress core自身が`mb_strlen()`ポリフィルを提供するため誤り | [#discussion_r4011595386](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011595386) |
| G2-4 | Codex | Medium（保留・設計判断） | 更新時のnull省略が「値不明」と「意図的クリア」を区別できない。`CanonicalModel`契約拡張が必要 | [#discussion_r4011595410](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011595410) |
| G3-2 | Codex | Medium（保留・既知の限界） | dry-runがアダプタ未呼出のため必須項目欠落を検出できない。E2-4のスコープ | [#discussion_r4011680617](https://github.com/artisanworkshop/cart-bridge-jp/pull/44#discussion_r4011680617) |

## 品質ゲート

- CI: [run](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/34926132983) green
  （JS/TS, PHP quality 8.2/8.3, PHPUnit wp-env）
- 品質チェック: green（`composer lint && composer analyze && composer test:wpenv`、PHPUnit 916件）
- wp-env実機確認: 実クラス（`Woo\Reader\CustomerReader`→`ColorMeAdapter::push_customer()`）を
  HTTPモックで実行し、ネイティブWoo顧客（`city`/`address_1`別メタキー、装飾記号付き電話番号）
  からの正しいペイロード組み立て、必須項目欠落時のAPIコールなしスキップ、都道府県対応表修正
  後の非自明な変換を確認済み

## ⚠️ 重大な発見: 本番データへの影響（要対応）

G3のレビューで、ColorMeのpref_id採番（都道府県コード）がJIS X 0401標準・WooCommerceの
採番と**47都道府県中約20件で一致しない**ことが判明しました。これは`Woo\Support\
AddressMapper::state_code()`（**インポート方向。Phase 1から本番稼働中の既存コード**）にも
存在するバグで、本PRで初めて発覚しました。

**影響**: 該当する約20都道府県（秋田/宮城、富山/福井、山梨/岐阜/静岡/愛知、和歌山/滋賀/奈良/
京都/大阪/兵庫、鳥取/岡山/広島/島根、香川/徳島、大分/熊本 等の入れ替わり。詳細は
`docs/03-design-decisions.md`「E2-3 PR-B」冒頭参照）の顧客・受注住所が、**本PRマージ以前に
インポート済みの実店舗データで既に誤った都道府県として保存されている可能性が高い**です。
F1-8で実施した実店舗2件（岡虎様・三つ猫様）のデータもこの影響を受けている可能性があります。

**次にできること**として、影響を受けた既存データの是正方法（再インポートによる上書き / 
対象都道府県の顧客・受注を特定して`billing_state`/`shipping_state`を一括修正するスクリプト等）
を別issueとして起票し、優先度高く対応することを強く推奨します。

## 次にできること（人間の判断）

- **最優先**: 上記「本番データへの影響」の是正issue起票・対応方針の決定
- 保留分の修正: `/dev-cycle fix G1-1 G1-2 G1-7 G2-4 G3-2` または `/fix-copilot-review 44`
- 両bot（Copilot/Codex）とも依頼上限（3回）に達したため「未確認」状態: 数時間後（別セッションでも
  よい）に `/fix-copilot-review 44` を実行し、遅れて届いた指摘が無いか確認することを推奨
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
