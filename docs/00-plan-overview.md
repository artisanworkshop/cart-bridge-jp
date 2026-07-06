# Cart Bridge JP 全体開発計画

最終更新: 2026-07-06

## 1. プロダクト概要

日本のASPカート（カラーミーショップ・MakeShop、将来他ASP）とWooCommerce間の**双方向データ移行**（無料版）と**継続同期**（Pro版・将来）を提供するWordPressプラグイン。

- 配布: wordpress.org（無料版）+ 有料アドオン（別プラグイン、将来）
- 無料版スコープ: 一括インポート/エクスポート（商品・カテゴリ・顧客・受注・在庫）
- Pro版スコープ（設計のみ考慮）: 定期実行の差分同期、在庫双方向同期、優先サポート

## 2. 対応プラットフォームとAPI概要比較

| 項目 | カラーミーショップ | MakeShop |
|---|---|---|
| API形式 | REST + JSON（OpenAPI定義あり） | GraphQL |
| 認証 | OAuth2認可コード（トークン無期限） | 永続トークン（API自社利用登録）/ SSO一時トークン |
| レート制限 | 120req/分/トークン（目安） | 要確認 |
| 商品 | GET/POST/PUT（DELETE不可） | Query/Mutation（create/update/delete、CSV一括登録も可） |
| カテゴリ | GETのみ（**作成不可**） | 取得/作成/更新可 |
| 顧客 | GET/POST/PUT（DELETE不可） | 取得/登録/更新/削除/パスワード更新/ポイント更新 |
| 受注 | GET/POST/PUT+キャンセル（DELETE不可） | 取得/登録/キャンセル/属性変更/配送ステータス変更 |
| クーポン | GETのみ | CRUD可 |
| 在庫 | GET（更新は商品PUT経由） | 取得/更新（バリエーション在庫含む） |
| Webhook | なし | あり（アプリ向け: install/uninstall/決済） |

詳細は `01-plan-colorme.md` / `02-plan-makeshop.md` を参照。

## 3. アーキテクチャ

### 3.1 レイヤー構成

```
Admin UI (React/TS) ──REST──> Admin\RestController
                                    │
                              Sync\JobManager ──> Action Scheduler
                                    │
                    ┌─── Sync\Importer / Sync\Exporter ───┐
                    │        （正規化モデルを介した変換）        │
        Adapters\PlatformAdapter                  Woo\WooRepository
        （ColorMe / MakeShop 実装）               （WC CRUD APIのラッパー）
                    │
        Support\{HttpClient, RateLimiter, TokenStore, Logger}
```

### 3.2 PlatformAdapter インターフェース

```php
namespace CartBridgeJP\Adapters;

interface PlatformAdapter {
    public function id(): string;                     // 'colorme' | 'makeshop'
    public function label(): string;
    public function capabilities(): Capabilities;     // 下記参照
    public function testConnection(): ConnectionResult;

    // 取得（すべてカーソルベースで再開可能に）
    public function fetchProducts( Cursor $cursor ): Page;   // Page<CanonicalProduct>
    public function fetchCategories(): array;                // CanonicalCategory[]
    public function fetchCustomers( Cursor $cursor ): Page;  // Page<CanonicalCustomer>
    public function fetchOrders( Cursor $cursor ): Page;     // Page<CanonicalOrder>
    public function fetchStocks( Cursor $cursor ): Page;     // Page<CanonicalStock>

    // 書き込み（capabilityで不可のものは UnsupportedOperationException）
    public function pushProduct( CanonicalProduct $p, ?string $remoteId ): PushResult;
    public function pushCategory( CanonicalCategory $c ): PushResult;
    public function pushCustomer( CanonicalCustomer $c, ?string $remoteId ): PushResult;
    public function pushOrder( CanonicalOrder $o ): PushResult;
    public function pushStock( CanonicalStock $s ): PushResult;
}
```

### 3.3 Capabilities（プラットフォーム差異の宣言）

```php
final class Capabilities {
    public bool $canCreateCategory;   // colorme: false / makeshop: true
    public bool $canCreateOrder;
    public bool $canUpdateCustomer;
    public bool $canPushImages;       // 要検証項目。falseならUIで代替フロー案内
    public bool $canCreateCoupon;     // colorme: false / makeshop: true
    public bool $hasVariants;
    public int  $rateLimitPerMinute;
}
```

### 3.4 正規化モデル（Canonical）

ASP固有のフィールド差異を吸収する中間表現。変換は必ず
`ASPデータ → Canonical → Woo` / `Woo → Canonical → ASPデータ` の2段で行う。

- `CanonicalProduct`（name, sku, price, salePrice, description, images[], variants[], options[], categoryRefs[], stock, status）
- `CanonicalCategory`（id, name, parentId, slug）
- `CanonicalCustomer`（email, name, kana, company, department, address, phone, birthday, mailmagOptIn, note）※パスワードは移行不可（両ASPともハッシュ取得不可）
- `CanonicalOrder`（number, status, customerRef, lineItems[], shipping, payment, totals, placedAt, note）
- ASP固有フィールドは `extras: array<string,mixed>` に退避し、メタとして保存（往復移行でのデータ欠損防止）

### 3.5 DBテーブル

```sql
cbjp_jobs      (id, type, platform, direction, status, cursor, totals_json, error_json, created_at, updated_at)
cbjp_mappings  (id, platform, entity_type, remote_id, local_id, checksum, synced_at)  -- 冪等性・差分検出の要
cbjp_logs      (id, job_id, level, message, context_json, created_at)
```

- `cbjp_mappings` により再実行時は重複作成せず更新（upsert）。checksumで変更検出
- 顧客の突合キーは email、商品は SKU（なければ remote_id）

## 4. 開発フェーズ

### Phase 0: 基盤（最初のマイルストーン）

1. プラグインスケルトン（メインファイル、Composer/PSR-4、activation hookでテーブル作成、HPOS互換宣言）
2. `wp-env` セットアップ（`.wp-env.json`: WooCommerce同梱、PHP 8.1）
3. CI用に PHPCS / PHPStan / PHPUnit の設定
4. `Support\` 層: HttpClient（リトライ+指数バックオフ）、RateLimiter（トークンバケット）、TokenStore（暗号化: `sodium_crypto_secretbox` + AUTH_KEYベース）、Logger
5. `PlatformAdapter` インターフェース・Canonicalモデル・Capabilities
6. 管理画面の骨格（設定ページ、接続管理タブ、React環境）

### Phase 1: カラーミー → Woo インポート（MVP）

`01-plan-colorme.md` 参照。OAuth接続 → カテゴリ → 商品（画像sideload）→ 顧客 → 受注 → 在庫。dry-run必須。

### Phase 2: MakeShop → Woo インポート

`02-plan-makeshop.md` 参照。GraphQLクライアント + 永続トークン接続。Phase 1のImporterを再利用しアダプタ追加のみで動くことを確認（アーキテクチャ検証を兼ねる）。

### Phase 3: Woo → カラーミー / Woo → MakeShop エクスポート

- カテゴリマッピングUI（カラーミーは作成不可のため既存カテゴリ選択、MakeShopは自動作成可）
- 商品・顧客・受注のエクスポート。SKU/email突合でupsert
- 画像: 各ASPのcanPushImages検証結果に応じ、不可の場合はCSV+画像一括登録の代替フロー案内

### Phase 4: 仕上げ・公開

- 全件E2E（テストショップで実データ移行リハーサル）
- readme.txt、スクリーンショット、wordpress.org申請（スラッグ `cart-bridge-jp`）
- WooCommerce is a trademark of Automattic の表記、カラーミー/MakeShopは本文中で「対応プラットフォーム」として言及（名称・スラッグに商標を含めない）

### Phase 5（Pro・将来）: 継続同期

- Action Schedulerによる定期差分同期（updated_atベース + checksum）
- 在庫双方向同期、競合解決ポリシー（マスター指定）
- 無料版には `cbjp/sync/*` フックのみ用意しPro側から接続

## 5. 管理画面UI構成

1. **接続管理**: プラットフォーム追加（カラーミー: OAuthウィザード / MakeShop: トークン+エンドポイント入力）、接続テスト
2. **インポート**: エンティティ選択 → dry-runプレビュー（件数・警告一覧）→ 実行 → 進捗バー（RESTポーリング）→ 結果レポート
3. **エクスポート**: 同上 + マッピング設定（カテゴリ/決済方法/配送方法/注文ステータス）
4. **ログ**: ジョブ履歴・エラー詳細・再実行ボタン

## 6. セキュリティ・運用要件

- トークンは暗号化保存、画面には末尾4桁のみ表示
- REST APIは `manage_woocommerce` + nonce
- 個人情報（顧客・受注）のログ出力禁止（context_jsonにはIDのみ）
- アンインストール時: 設定・マッピング・ログの削除可否をオプションで選択（デフォルト保持）

## 7. リスクと要検証事項（着手前にフィクスチャ収集）

| # | 項目 | 影響 | 対応 |
|---|---|---|---|
| 1 | カラーミー: 商品POST/PUTで画像登録可否 | Phase 3のUX | swagger.json精査+テストショップ実測 |
| 2 | MakeShop: レート制限値 | JobRunner設計 | FAQ/問い合わせで確認 |
| 3 | MakeShop: API自社利用登録の条件（プラン・費用） | 利用者の前提条件 | 公式に確認しREADMEに明記 |
| 4 | MakeShop: createProductの画像入力形式 | Phase 3 | GraphQLスキーマ精査+実測 |
| 5 | カラーミー: 受注POSTの必須項目と決済/配送ID参照 | 受注移行 | テストショップ実測 |
| 6 | 大規模ショップ（商品1万点超）のジョブ実行時間 | 全Phase | カーソル分割+Action Schedulerの並列度調整 |

## 8. ディレクトリ構成

```
cart-bridge-jp/
├── cart-bridge-jp.php          # メインファイル（ヘッダー、autoload、起動）
├── CLAUDE.md
├── docs/
│   ├── 00-plan-overview.md
│   ├── 01-plan-colorme.md
│   └── 02-plan-makeshop.md
├── includes/
│   ├── Core/                   # Plugin, Activator, Uninstaller, Container
│   ├── Adapters/
│   │   ├── PlatformAdapter.php, Capabilities.php, Cursor.php, Page.php
│   │   ├── ColorMe/            # ColorMeAdapter, ColorMeClient, OAuth, 変換クラス
│   │   └── MakeShop/           # MakeShopAdapter, GraphQLClient, 変換クラス
│   ├── Canonical/              # CanonicalProduct 等
│   ├── Sync/                   # JobManager, Importer, Exporter, MappingRepository
│   ├── Woo/                    # WooRepository（WC CRUDラッパー、画像sideload）
│   ├── Admin/                  # Menu, RestController, Assets
│   └── Support/                # HttpClient, RateLimiter, TokenStore, Logger
├── src/                        # React/TS 管理画面アプリ
├── languages/
├── tests/
│   ├── unit/
│   ├── fixtures/               # 実APIレスポンスのサンプルJSON
│   └── integration/            # 実API結合（環境変数でトークン注入時のみ）
├── .wp-env.json
├── composer.json / package.json / phpcs.xml.dist / phpstan.neon.dist
└── readme.txt                  # wordpress.org用
```
