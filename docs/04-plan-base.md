# BASE アダプタ実装計画

最終更新: 2026-09-05 / 対象: `includes/Adapters/Base/` / リリース: **v2.0**（Phase 4 インポート・Phase 5 エクスポート。D18。v1.0 には含めない）

> BASE API は「β版」と明記されており仕様変更の可能性がある。実装時は必ず
> https://docs.thebase.in/api/ で最新仕様を再確認すること。

## 1. API基本仕様

| 項目 | 内容 |
|---|---|
| API形式 | REST + JSON |
| ベースURL | `https://api.thebase.in`（パスは `/1/...`） |
| ドキュメント | https://docs.thebase.in/api/ （β版） |
| 認証 | OAuth2 認可コードフロー。**アクセストークン有効期限1時間**（`expires_in: 3600`） |
| リフレッシュ | **リフレッシュトークン約30日・ローテーション式**（リフレッシュ毎に新しいrefresh_tokenが発行される）。`redirect_uri` はリフレッシュ時も必須 |
| 認可URL | `GET /1/oauth/authorize`（`response_type=code`。認可コード有効期限は約1時間） |
| トークンURL | `POST /1/oauth/token`（`authorization_code` / `refresh_token` グラント） |
| スコープ | `read_users read_users_mail read_items read_orders write_items write_orders`（`read_savings` は移行用途では不要。`read_users` はデフォルト付与） |
| レート制限 | **5,000リクエスト/時・100,000リクエスト/日**（毎時00分リセット）。超過時は **HTTP 400** + エラーコード `hour_api_limit` / `day_api_limit`（**429ではない**）。加えて商品登録（items/add）は **1日1,000件** 上限（`exceed_daily_limit`） |
| Webhook | **なし**（公式ヘルプが明言。参考: 変更検知が必要になる場合は `modified` / `start_ordered` によるポーリングのみ。なお継続同期は販売しない=D14） |

### OAuth接続フロー（リフレッシュ対応が必須）

1. ユーザーが BASE Developers（https://developers.thebase.in）でアプリ登録し、コールバックURL（プラグインが表示するREST URL）を登録
2. 設定画面で client_id / client_secret を入力 → 認可URLへリダイレクト
3. コールバックで code 受領 → トークン交換 → `TokenStore` に **access_token / refresh_token / expires_at をセットで**暗号化保存
4. 以後のAPI呼び出し前に expires_at を確認し、期限切れ（間近）なら refresh_token で更新。
   **ローテーション式のため、更新レスポンスの新しい refresh_token で必ず上書き保存**（更新中の同時実行はロックで排他。Action Scheduler並列時に旧refresh_tokenを使うと失効する）
5. リフレッシュ失敗（30日超の放置等）は例外にせず「再接続が必要」状態を返し、UIで再認可を促す
6. `GET /1/users/me` で接続テスト（shop_name を表示）

## 2. エンドポイント対応表

| エンティティ | 取得 | 作成 | 更新 | 削除 | 備考 |
|---|---|---|---|---|---|
| ショップ | `GET /1/users/me` | × | × | × | 接続テストに使用。mail_addressは`read_users_mail`必要 |
| 商品 | `GET /1/items`, `GET /1/items/search`, `GET /1/items/detail/:item_id` | `POST /1/items/add` | `POST /1/items/edit` | （`/1/items/delete` あり・**使用しない**） | `identifier`=商品コード、価格は税込、`item_tax_type`（1:標準/2:軽減） |
| 商品画像 | 商品レスポンスの `img1_origin`〜`img20_origin` | `POST /1/items/add_image`（**URL指定方式**・image_no 1〜20・jpg/png/gif・4MB以内） | 同左 | （`/1/items/delete_image` あり・使用しない） | エクスポート時はWoo画像の公開URLを渡す |
| バリエーション | 商品レスポンスの `variations[]` | `/1/items/add`・`/1/items/edit` に統合（`variation[]`配列。`variation_id`空で追加） | 同左 | （`/1/items/delete_variation` あり・使用しない） | **1軸のみ**（文字列ラベル+在庫）。在庫合計が商品stockに自動反映 |
| 在庫 | 商品レスポンスの `stock` / `variation_stock` | − | `POST /1/items/edit_stock` | × | |
| カテゴリ | `GET /1/categories` | `POST /1/categories/add` | `POST /1/categories/edit` | （`/1/categories/delete` あり・使用しない） | **最大3階層**（`number`/`parent_number`/`code`） |
| 商品⇔カテゴリ紐付け | `GET /1/item_categories/detail/:item_id` | `POST /1/item_categories/add` | − | `POST /1/item_categories/delete` | 1商品に複数カテゴリ可 |
| 顧客 | **APIなし** | × | × | × | 受注詳細の購入者情報（氏名・メール・電話・住所）から抽出するのみ |
| 受注 | `GET /1/orders`（期間・limit/offset）, `GET /1/orders/detail/:unique_key` | **×（作成API なし）** | `POST /1/orders/edit_status`（**明細単位**の発送ステータス・追跡番号のみ。キャンセルは代引きのみ） | × | 一覧は要約のみ→詳細APIで補完 |
| 配送業者 | `GET /1/delivery_companies` | × | × | × | edit_statusの`delivery_company_id`用 |
| クーポン | **APIなし** | × | × | × | |
| タグ / グループ / レビュー | **APIなし** | × | × | × | |

ページネーション: `limit`（デフォルト20・**最大100**）/ `offset` 方式。

APIで編集不可の商品タイプ: デジタルコンテンツ、ClubT、SpCase、定期便、予約、抽選、テイクアウト、コミュニティ限定商品（インポート時に警告してスキップ）。
`title` / `detail` に絵文字（4byte文字）不可、`identifier` にスペース不可（エクスポート時にサニタイズ）。

## 3. 実装クラス

```
Adapters/Base/
├── BaseAdapter.php           # PlatformAdapter実装
├── BaseClient.php            # RESTクライアント（RateLimiter/リトライ/トークン自動リフレッシュ統合）
├── BaseOAuth.php             # 認可URL生成・コード交換・リフレッシュ（ローテーション対応・排他ロック）
└── Transform/
    ├── ProductTransformer.php    # items ⇔ CanonicalProduct
    ├── OrderTransformer.php      # orders/detail → CanonicalOrder
    ├── CustomerExtractor.php     # 受注購入者 → CanonicalCustomer（email名寄せ）
    └── CategoryTransformer.php
```

### Capabilities宣言

```text
canCreateCategory:  true      // 3階層まで
canCreateOrder:     false     // 注文作成APIなし（受注はインポート専用）
canFetchCustomers:  false     // 顧客一覧APIなし（受注から抽出）
canUpdateCustomer:  false
canPushImages:      true      // add_image がURL指定方式のため可（要実測で最終確定）
canCreateCoupon:    false
hasCoupons:         false
hasTags:            false
hasReviews:         false
hasVariants:        true      // ただし1軸のみ
rateLimitPerMinute: 80        // 5,000/時 ≒ 83/分 を安全側に丸め
```

## 4. データマッピング（Canonical変換の要点）

### 商品（BASE → Canonical → Woo）

| BASE | Canonical | Woo |
|---|---|---|
| `title` | name | 商品名 |
| `identifier` | sku | SKU（空なら `base-{item_id}`） |
| `price`（税込） | price | 価格 |
| `proper_price` | regularPrice | 定価（セール表現） |
| `item_tax_type` | extras.tax_type | 軽減税率(2)は税クラスマッピング候補としてメタ保存 |
| `detail` | description | 本文（wp_kses_postで浄化） |
| `img1_origin`〜`img20_origin` | images[]（URL） | `media_sideload_image` で取込 |
| `variations[]`（1軸） | variants[]（単一属性） | Variable product（属性1軸: 名称は "Variation" 固定 + i18n） |
| `variation_identifier` / `barcode` | variants[].sku / extras | バリエーションSKU / メタ |
| `/1/item_categories/detail` の紐付け | categoryRefs[]（複数可） | 商品カテゴリー |
| `visible` | status | publish / private |
| `stock` / `variation_stock` | stock | 在庫数・在庫管理ON |

### 受注（BASE → Canonical → Woo）

- 一覧（`/1/orders`）は要約のみのため、**1件ずつ `/1/orders/detail/:unique_key` を呼ぶ**（レート制限5,000/時内で十分だがページサイズは50に抑える）
- `unique_key` → 注文番号メタ（`_cbjp_remote_order_number`）
- 購入者（氏名・メール・電話・住所）→ billing。配送先 → shipping
- `payment`（creditcard, cod, cvs, base_bt, atobarai, carrier_01〜03, paypal, coin, amazon_pay, bnpl, paypay）→ 決済マッピングUIで対応付け
- `shipping_fee` / 代引き手数料 / `order_discount` → 送料・手数料・クーポン行（ASP側の値をそのまま設定）
- 明細 `order_items[]`（order_item_id, item_id, variation_id, title, price, amount, 消費税率, options[]）→ line items（SKU/mappings突合、未解決はカスタム行=D10）

### 受注ステータス

BASEは**明細単位**の `status` と注文単位の `dispatch_status` を持つ。注文全体のWooステータスは `dispatch_status` を基本に決定:

| BASE dispatch_status | Woo status |
|---|---|
| unpaid | pending |
| ordered（入金済・未発送） | processing |
| shipping（発送処理中） | processing（メタで区別） |
| dispatched | completed |
| cancelled | cancelled |
| unshippable | on-hold（メタに理由保存） |

明細単位で発送状態が分かれるケース（一部発送済み等）の集約規則は**要実測**（要検証#10）。

### 顧客（受注からの抽出）

- BASEに顧客一覧APIが存在しないため、**受注インポート時に購入者情報からemail名寄せで顧客を生成**（オプション。デフォルトON）
- 同一emailの複数受注は最新の住所・氏名で上書きせず**初回作成のみ**（受注には注文時スナップショットが残るため）
- 単独の「顧客インポート」エンティティとしてはUI選択肢に出さない（capability `canFetchCustomers: false` でUIが除外）

## 5. Woo → BASE エクスポートの制約と実装

1. **商品**: `items/add` / `items/edit` でupsert（突合キー: `identifier`=SKU）。**1日1,000件の登録上限**があるため、超過時はジョブを翌日再開（`paused` → Action Schedulerで翌日再エンキュー）。dry-runで件数超過を事前警告
2. **画像**: `add_image` はURL指定方式のため、Wooのメディア公開URLを渡す（サイトがローカル/Basic認証下の場合は失敗する旨をdry-run警告）。20枚超・4MB超はスキップ+警告
3. **バリエーション**: Wooの複数属性は1軸文字列に連結（例: 「赤 / M」）。**ロッシー変換**のためdry-runで明示警告
4. **カテゴリ**: 3階層まで自動作成可。4階層以上のWooカテゴリは3階層目に平坦化+警告
5. **受注・顧客**: エクスポート不可（capabilitiesで無効化。UIに表示しない）
6. **削除**: `items/delete` 等は呼ばない（全体方針）。Woo側で削除された商品は `visible=0`（非公開）への更新を提案
7. **絵文字・記号**: `title`/`detail` の4byte文字は除去または置換、`identifier` のスペースは変換（警告付き）

## 6. 実装タスク（順番に）

1. [ ] テストショップにサンプルデータ（バリエーション商品・複数カテゴリ商品・各決済の受注・一部発送の受注）を登録し、全エンドポイントの実レスポンスJSONを `tests/fixtures/base/` に保存（**要検証#9〜#12をここで確定**）
2. [ ] `BaseOAuth`（認可URL・コード交換・**リフレッシュ+ローテーション+排他ロック**）+ TokenStore統合 + ユニットテスト
3. [ ] `BaseClient`（GET/POST、エラー→例外変換、**HTTP 400のレート制限エラーコード判別**、RateLimiter統合、期限切れ時の自動リフレッシュ）+ ユニットテスト
4. [ ] 接続ウィザードUI（client_id/secret入力 → 認可 → callback → `/1/users/me` 接続テスト）
5. [ ] Transformer（Product / Order / CustomerExtractor / Category）+ フィクスチャベースのユニットテスト
6. [ ] `BaseAdapter.fetch*` 実装（カーソル=offset、受注は一覧→詳細の2段取得）→ Importer結合でBASE→Wooインポート成立（Importer本体を変えずアダプタ追加のみで成立することを確認するアーキテクチャ検証マイルストーン=D18）
7. [ ] テストショップで実データインポートE2E
8. [ ] `push*` 実装（Phase 5 / E5-1）: カテゴリ自動作成 → 商品upsert → 画像add_image → 在庫edit_stock（1日1,000件制御）

## 7. カラーミー/MakeShopとの差分まとめ（アダプタ設計の検証観点）

| 観点 | カラーミー | MakeShop | BASE | 吸収方法 |
|---|---|---|---|---|
| トークン有効期限 | 無期限 | 永続 | **1時間+リフレッシュ30日ローテーション** | TokenStoreを有効期限+リフレッシュ対応の構造に（03 §4） |
| 顧客API | あり | あり | **なし（受注から抽出）** | Capabilities `canFetchCustomers` + CustomerExtractor |
| 受注作成 | 可 | 可 | **不可** | Capabilities `canCreateOrder: false` |
| レート制限エラー | 429 | 要確認 | **HTTP 400 + エラーコード** | HttpClientのリトライ判定をアダプタ拡張可能に |
| バリエーション | 2軸 | 2軸（縦横） | **1軸** | Canonical variants[] は軸数可変。エクスポート時に警告 |
| Webhook | なし | あり | なし | Pro同期はポーリング共通路線 |

## 参考

- APIドキュメント: https://docs.thebase.in/api/
- 開発者登録: https://developers.thebase.in
- OAuth: https://docs.thebase.in/api/oauth/authorize/ / access_token / refresh_token
- Webhookなしの公式回答: https://help.thebase.in/hc/ja/articles/9811124935705
- 開発者登録FAQ（即時利用可）: https://help.thebase.in/hc/ja/articles/9811103840409
