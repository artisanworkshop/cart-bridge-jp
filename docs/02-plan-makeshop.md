# MakeShop アダプタ実装計画

最終更新: 2026-09-05 / 対象: `includes/Adapters/MakeShop/` / リリース: **v3.0**（Phase 6 インポート・Phase 7 エクスポート。D18。v1.0/v2.0 には含めない）

## 1. API基本仕様

| 項目 | 内容 |
|---|---|
| API形式 | **GraphQL**（新API。旧APIは廃止予定のため使用しない） |
| リファレンス | https://developers.makeshop.jp/api/graphql/index.html |
| エンドポイント | ショップ/アプリごとに登録手続き完了メールで通知される（固定URLではない → 設定画面で入力させる） |
| 認証（本プラグインで採用） | **永続トークン**方式: ショップオーナーが「API自社利用登録」を行い発行。https://developers.makeshop.jp/signup/shopowner |
| 認証（将来のアプリストア版） | SSO: OAuth2類似（認可コード→一時トークン5分、リフレッシュトークン12時間、PKCE対応、`https://app-auth.makeshop.jp/oauth2/token`） |
| レート制限 | **要確認**（FAQ/サポートで確認し、確定までRateLimiterは60req/分に設定） |
| Webhook | アプリ公開時のみ必須（install/uninstall等）。自社利用の移行プラグインでは不要 |

> 設計メモ: 無料版はショップオーナー自身が「API自社利用登録」で取得した永続トークン+エンドポイントURLを設定画面に貼り付ける方式。アプリストア公開する場合はSSOフロー+Webhookの実装が別途必要（v3.0 公開後に別途検討）。

## 2. 利用するQuery/Mutation対応表（shop admin API）

| エンティティ | 取得(Query) | 書き込み(Mutation) |
|---|---|---|
| ショップ | `getShop`（接続テスト・決済方法取得） | − |
| 配送設定 | `getShopDeliverySetting` | − |
| 商品 | `searchProduct` | `createProduct` / `updateProduct` /（`deleteProduct` は使用しない） |
| 商品一括 | − | `importProductBulk`（商品CSVアップロード。大量移行時の高速経路として検討） |
| 在庫 | `searchProductQuantity` | `updateProductQuantity` / `updateVariationQuantity` |
| カテゴリ | `getProductCategory`（子カテゴリ一覧含む） | `createProductCategory` / `updateProductCategory` |
| 会員 | `searchMember` / `searchMemberGroup` | `createMember` / `updateMember` /（`updateMemberPassword`, `updateMemberShopPoint` は任意機能）/（`deleteMember` は使用しない） |
| 注文 | `searchOrder` | `createOrder`（決済なしのデータ登録モードを使用）/ `cancelOrder` / `updateOrderAttribute` / `updateOrderDeliveryStatus` |
| 定期 | `searchOrderSubscription` / `searchProductSubscription` | −（Wooサブスク連携は対象外。extrasに保存のみ） |
| クーポン | `searchCoupon` / `searchCouponProduct` | `createCoupon` / `updateCoupon` / `attachCouponProduct` |
| レビュー | `searchReview` | −（インポートのみ検討。Wooのコメントレビューへ） |

方針: `deleteProduct` / `deleteMember` は破壊的操作のため**アダプタから呼ばない**（全体方針「削除は非公開化提案」に従う）。

## 3. 実装クラス

```
Adapters/MakeShop/
├── MakeShopAdapter.php       # PlatformAdapter実装
├── GraphQLClient.php         # POST {endpoint} / Authorization: Bearer {永続トークン}
│                             #   - query/variables のJSON送信、errors配列→例外変換
│                             #   - partial data + errors の複合レスポンス処理に注意
├── Queries.php               # GraphQLクエリ文字列の定義（フィールド選択は必要最小限）
└── Transform/
    ├── ProductTransformer.php
    ├── MemberTransformer.php
    ├── OrderTransformer.php
    └── CategoryTransformer.php
```

### Capabilities宣言（03 §2 の確定版コンストラクタに対応）

```text
canCreateCategory:  true
canCreateOrder:     true      // createOrder（決済なしデータ登録）
canFetchCustomers:  true
canUpdateCustomer:  true
canPushImages:      false     // 暫定。要検証#4（createProductの画像入力形式）で更新
canCreateCoupon:    true
hasCoupons:         true
hasTags:            false
hasReviews:         true      // searchReview（インポートのみ）
hasVariants:        true      // バリエーション在庫あり
rateLimitPerMinute: 60        // 要確認#2の結果で更新
```

## 4. データマッピングの要点

### 商品（MakeShop → Canonical → Woo）

- `システム商品コード` → sku（MakeShop側の一意キー。mappingsのremote_idにも使用）
- 販売価格・定価 → price / regularPrice
- 画像URL（複数）→ images[]（インポート時はsideload）
- バリエーション（縦軸・横軸）→ variants[]（Wooのvariation 2属性）
- カテゴリ（親子階層）→ categoryRefs[]（**エクスポート時は自動作成可能**。カラーミーとの差別化点）
- 定期商品・予約商品 → extras に種別を保存し、Wooでは通常商品として作成+管理画面に警告表示
- 名入れグループ・ノベルティ → extras（Wooに対応概念なし）

### 会員

- 突合キー: email（MakeShop会員IDは extras.member_id に保存）
- 会員グループ → ユーザーメタ + Wooの顧客としては通常扱い
- ショップポイント → extras（Wooコアにポイント無し。ポイント系プラグイン連携はPro検討）
- パスワード: 平文取得不可のため移行不可（`updateMemberPassword` はWoo→MakeShop方向でランダム初期化+ユーザー通知が必要な場合のみ）

### 注文ステータス

| MakeShop | Woo |
|---|---|
| 入金待ち | pending |
| 未配送（入金済） | processing |
| 配送指示/配送準備 | processing（メタで区別） |
| 配送完了 | completed |
| キャンセル/返送 | cancelled / refunded（金額で判定） |

Woo→MakeShop方向は `updateOrderDeliveryStatus`（未配送/配送指示/配送完了/返送）へ逆マッピング。

## 5. GraphQL実装の注意

1. **ページネーション**: searchOrder/searchProduct等のページング仕様（cursor/offset、最大件数）をリファレンスで確認し `Cursor` に実装。取得件数はレート制限を踏まえ1ページ50〜100件
2. **フィールド選択**: GraphQLの利点を活かし、一覧取得では最小フィールド、詳細変換時にフル取得の2段構成にする
3. **エラー処理**: GraphQLは HTTP 200 + `errors[]` の形式がある。`data` が部分的に返るケースは項目単位で警告ログ+スキップ
4. **クエリはコード内定数**: 動的組み立てはしない（インジェクション防止・レビュー容易性）
5. **importProductBulk**: 1,000商品超のエクスポートではCSV一括経路を第2実装として用意（要検証: CSVフォーマット仕様）

## 6. 実装タスク（順番に）

1. [ ] API自社利用登録を実施し、エンドポイントURL・永続トークンを取得（**前提条件・要確認#3: 対象プラン・費用をREADME用に記録**）
2. [ ] GraphQLリファレンスから searchProduct / searchMember / searchOrder / createProduct のスキーマを精査し、レスポンスサンプルを `tests/fixtures/makeshop/` に保存（**要検証#4: 画像入力形式**、**要確認#2: レート制限**）
3. [ ] `GraphQLClient` + ユニットテスト（errors[]変換、リトライ、RateLimiter統合）
4. [ ] 接続設定UI（エンドポイント+トークン入力、`getShop` で接続テスト）
5. [ ] Transformer4種 + フィクスチャテスト
6. [ ] `fetch*` 実装 → 既存Importerに結合し MakeShop→Woo インポート成立（Importer本体を変えずアダプタ追加のみで成立することを確認。v2.0 の BASE で検証した観点の再確認）
7. [ ] 実ショップでインポートE2E
8. [ ] `push*` 実装（Phase 7 / E7-1）: カテゴリ自動作成 → 商品upsert → 会員upsert → 注文作成（決済なしモード）→ 在庫更新
9. [ ] 大量データ時の `importProductBulk` 経路（任意。Phase 7 / E7-2）

## 7. カラーミーとの差分まとめ（アダプタ設計の検証観点）

| 観点 | カラーミー | MakeShop | 吸収方法 |
|---|---|---|---|
| プロトコル | REST | GraphQL | Client層をアダプタ内に閉じ込め |
| 認証 | OAuth2（無期限トークン） | 永続トークン手入力 | 接続ウィザードをアダプタごとに差し替え |
| カテゴリ作成 | 不可 | 可 | Capabilities + マッピングUIの分岐 |
| エンドポイント | 固定 | ショップごと | 接続設定スキーマをアダプタが宣言 |
| 削除API | なし | あり（使わない） | 全体方針で禁止 |

## 参考

- 開発者サイト: https://developers.makeshop.jp/
- API一覧: https://developers.makeshop.jp/api/
- GraphQLリファレンス: https://developers.makeshop.jp/api/graphql/index.html
- 要件・仕様（SSO/Webhook）: https://developers.makeshop.jp/guide/specifications.html
- API自社利用登録: https://developers.makeshop.jp/signup/shopowner
- FAQ: https://manual.makeshop.jp/hc/ja/articles/54692473865881
