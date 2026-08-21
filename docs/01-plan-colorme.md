# カラーミーショップ アダプタ実装計画

最終更新: 2026-07-08 / 対象: `includes/Adapters/ColorMe/`

## 1. API基本仕様

| 項目 | 内容 |
|---|---|
| ベースURL | `https://api.shop-pro.jp/v1/`（レスポンスはJSON、パスは `.json` 付き） |
| OpenAPI定義 | `https://api.shop-pro.jp/v1/swagger.json`（クライアント実装の一次情報源にする） |
| ドキュメント | https://developer.shop-pro.jp/docs/colorme-api |
| 認証 | OAuth2 認可コードフロー。**アクセストークン無期限**（リフレッシュ処理不要） |
| 認可URL | `https://api.shop-pro.jp/oauth/authorize`（`response_type=code`） |
| トークンURL | `https://api.shop-pro.jp/oauth/token`（認可コードの有効期限10分、交換は1回のみ） |
| スコープ | `read_products write_products read_sales write_sales read_shop_coupons` |
| レート制限 | **120リクエスト/分/トークン**（目安。RateLimiterで100/分に抑える） |
| エラー形式 | `{ "errors": [ { "code": 404100, "message": "...", "status": 404 } ] }` |

### OAuth接続フロー（プラグイン側の実装）

1. ユーザーがカラーミーデベロッパー登録し、アプリ登録（リダイレクトURLにはプラグインが表示する `admin-ajax`/REST のコールバックURLを登録してもらう）
2. 設定画面で client_id / client_secret を入力 → 認可URLへリダイレクト
3. コールバックで code 受領 → トークン交換 → `TokenStore` に暗号化保存
4. `GET /v1/shop.json` で接続テスト（ショップ名を表示）

## 2. エンドポイント対応表

| エンティティ | 取得 | 作成 | 更新 | 削除 | 備考 |
|---|---|---|---|---|---|
| ショップ | `GET /shop.json` | × | × | × | 接続テストに使用 |
| 商品 | `GET /products.json`, `GET /products/{id}.json` | `POST /products.json` | `PUT /products/{id}.json` | × | バリエーション(variants)・オプション含む |
| 在庫 | `GET /products.json`（在庫取込元。下記注参照） | − | 商品PUT経由 | × | scope: write_products |
| カテゴリ | `GET /categories.json` | **×** | × | × | 大カテゴリ/小カテゴリの2階層 |
| グループ | `GET /groups.json` | × | × | × | Wooのタグに対応付け |
| 顧客 | `GET /customers.json`, `GET /customers/{id}.json` | `POST /customers.json` | `PUT /customers/{id}.json`（2025/07追加） | × | furigana, hojin, busho, fax, birthday, receive_mail_magazine, answer_free_form1-3, other 等 |
| 受注 | `GET /sales.json`, `GET /sales/{id}.json`, `GET /sales/stat.json` | `POST /sales.json`（受注データ作成） | `PUT /sales/{id}.json`, `PUT /sales/{id}/cancel.json` | × | 明細に注文時商品名・オプション名あり |
| 受注メール | − | `POST /sales/{id}/mails.json` | − | − | 移行時は**送信しない**こと |
| 決済設定 | `GET /payments.json` | × | × | × | マッピング元 |
| 配送設定 | `GET /deliveries.json`, `GET /deliveries/date.json` | × | × | × | マッピング元 |
| ギフト設定 | `GET /gift.json` | × | × | × | 参考取得のみ |
| クーポン | 読み取り可（read_shop_coupons） | × | × | × | Woo側に再作成（インポートのみ） |

ページネーション: `limit` / `offset` 方式。ページサイズは50で確定（F1-5実装値。`products.json`/`stocks.json` のAPI上限に合わせ、`customers.json`/`sales.json`（上限100）も含め全エンドポイント共通にした）。`categories.json`/`groups.json`/`shop_coupons.json` はページングパラメータが無く常に1回で全件取得する。

**在庫の取得元（F1-5確定）**: `GET /stocks.json` はバリエーションIDを返さない（`option1_value`/`variant_model_number`のみ）ため、同一商品の複数バリエーションで `CanonicalStock::remote_id()`（`variant_ref ?? product_ref`）が衝突する。`GET /products.json` の `variants[].id` を使うことで `VariationWriter` の `'variant'` マッピングと正確に突合できるため、`fetch_stocks()` は `products.json` を走査し `StockTransformer` で導出する（無料版のサンプル在庫取込 `run_sample_stock_page()` も同じロジックを共有）。

**受注の全量走査（`fetch_orders`）**: `GET /sales.json` は `after`/`before` 省略時に直近7日間しか検索しない（§9 #14）ため、全量走査時は `after=2000-01-01`（サービス開始より確実に前の日付）を明示する。

## 3. 実装クラス

```
Adapters/ColorMe/
├── ColorMeAdapter.php        # PlatformAdapter実装
├── ColorMeClient.php         # RESTクライアント（RateLimiter/リトライ内蔵）
├── ColorMeOAuth.php          # 認可URL生成・コード交換
├── Transform/
│   ├── ProductTransformer.php    # products.json ⇔ CanonicalProduct
│   ├── CustomerTransformer.php
│   ├── OrderTransformer.php
│   └── CategoryTransformer.php
└── fixtures収集用のメモは tests/fixtures/colorme/ に置く
```

### Capabilities宣言（03 §2 の確定版コンストラクタに対応）

```text
canCreateCategory:  false
canCreateOrder:     true
canFetchCustomers:  true
canUpdateCustomer:  true
canPushImages:      false     // 要検証#1は確定済み。レギュラープラン等の既定値。POST /v1/products/{product_id}/images は
                              // 存在するがプレミアムプラン限定のため、固定値ではなく shop.json の contract_plan を見て
                              // 接続時に true/false を動的判定する（03 §9 #1 参照。ここでの表記は代表値）
canCreateCoupon:    false
hasCoupons:         true      // 読取のみ（Woo側に再作成）
hasTags:            true      // groups をタグとして扱う
hasReviews:         false
hasVariants:        true
rateLimitPerMinute: 100
```

## 4. データマッピング（Canonical変換の要点）

### 商品（ColorMe → Canonical → Woo）

| ColorMe | Canonical | Woo |
|---|---|---|
| `name` | name | 商品名 |
| `model_number` | sku | SKU（空なら `colorme-{product_id}`） |
| `sales_price` / `price` | price / regularPrice | 価格（税込。Woo側税設定に注意） |
| `members_price` | extras.members_price | メタ保存（会員価格はWooコアに無い） |
| `simple_expl` / `expl` | shortDescription / description | 抜粋 / 本文（HTMLはwp_kses_postで浄化） |
| `image_url`, `another_image_url*` | images[]（URL） | `media_sideload_image` でメディア取込 |
| `variants[]`（type1/type2軸） | variants[] | Variable product + variation（属性2軸まで） |
| `options[]` | options[] | 属性（非variation）またはメタ |
| `category_id_big/small` | categoryRefs[] | 商品カテゴリー（親子） |
| `group_ids` | tagRefs[] | 商品タグ |
| `display_state` | status | publish / private |
| `stocks` | stock | 在庫数・在庫管理ON |

### 受注ステータス

| ColorMe状態 | Woo status |
|---|---|
| 未入金 | pending |
| 入金済（paid=true）・未発送 | processing |
| 発送済（delivered=true） | completed |
| キャンセル（canceled=true） | cancelled |

決済方法・配送方法はIDと名称を取得し、マッピングUI（例: 「銀行振込 → bacs」）で対応付け。未マッピングは注文メタに元名称を保存。

### 顧客

- 突合キー: email。既存WPユーザーがいれば注文を紐付け、いなければ `customer` ロールで作成（パスワードはランダム生成、**通知メールは送らない**。D10により移行時の副作用抑止は必須でありオプション化はしない）
- furigana/hojin/busho等はユーザーメタ `_cbjp_*`（汎用キー）に保存。`cbjp_colorme_*`のようなプラットフォーム固有接頭辞は使わない
  （`Woo\WooRepository`はアーキテクチャ原則1によりプラットフォーム固有コードを持たないため。出自は`_cbjp_platform`メタが別途保持する。F1-4実装時に確定）

## 5. Woo → ColorMe エクスポートの制約と実装

1. **カテゴリ作成不可**: 事前に `GET /categories.json` で取得し、Wooカテゴリとの対応をユーザーがUIで選択。未対応カテゴリの商品は警告付きでカテゴリ未設定として登録
2. **画像**（要検証#1確定=プラン依存）: `POST /v1/products/{product_id}/images` はプレミアムプラン契約ショップのみ利用可。canPushImages=falseと判定された場合（レギュラープラン等）は、エクスポート結果に「画像URL一覧CSV」を出力し、カラーミー管理画面での一括登録手順を案内
3. **受注エクスポート**: `POST /sales.json` の必須項目（customer_id, 決済ID, 配送ID等）をテストショップで実測してから実装。顧客が未存在なら先に顧客POST → customer_id取得 → 受注POST の順
4. **削除不可**: Woo側で削除された商品は `display_state` 非公開への更新を提案（自動では行わない）

## 6. 実装タスク（順番に）

1. [ ] swagger.json を取得し `tests/fixtures/colorme/swagger.json` に保存、商品/受注/顧客スキーマを精査（**要検証#1: 画像フィールドの書き込み可否をここで確定**）
2. [ ] `ColorMeClient`（GET/POST/PUT、エラー→例外変換、429/5xxリトライ、RateLimiter統合）+ ユニットテスト
3. [ ] `ColorMeOAuth` + 接続ウィザードUI + TokenStore統合
4. [ ] Transformer4種 + フィクスチャベースのユニットテスト
5. [ ] `ColorMeAdapter.fetch*` 実装（カーソル=offset）→ Importer結合でカラーミー→Wooインポート成立
6. [ ] テストショップで実データインポートE2E（商品100件・受注50件規模）
7. [ ] `push*` 実装（Phase 4）: 商品upsert → 顧客upsert → 受注作成 → 在庫更新
8. [ ] カテゴリマッピングUI・画像代替フロー

## 7. テストショップ

- デベロッパー登録: https://developer.shop-pro.jp/getting-started/ （無料、テストショップ提供あり）
- テストショップにサンプルデータ（バリエーション商品・オプション商品・法人顧客・各決済の受注）を登録してからフィクスチャを収集する

## 参考

- APIドキュメント: https://developer.shop-pro.jp/docs/colorme-api
- 呼び出し制限: https://shop-pro.jp/?mode=api_call_limit
- 参考実装（公式WPプラグイン）: https://github.com/pepabo/colormeshop-wp-plugin
