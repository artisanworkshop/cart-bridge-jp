# Cart Bridge JP 全体開発計画

最終更新: 2026-09-05

## 1. プロダクト概要

日本のASPカート（カラーミーショップ・MakeShop・BASE、将来他ASP）とWooCommerce間の**双方向データ移行**を提供するWordPressプラグイン。無料版は挙動確認用、Pro版で無制限移行を解除する（ビジネスモデルの詳細は `03-design-decisions.md` §10）。

- **リリース計画（D18・2026-09-05改訂）**: **v1.0=カラーミーショップのみ**（インポート＋エクスポート）→ **v2.0=BASE追加** → **v3.0=MakeShop追加**。1プラットフォームずつ往復移行を揃えて公開する。フェーズ構成は §4

- 配布: wordpress.org（無料版）+ Pro版アドオン（別プラグイン、自社サイト直販）
- 無料版スコープ: 一括インポート/エクスポート（商品・カテゴリ・顧客・受注・在庫）。**dry-runは全量無料**、実行は最新受注10件起点のサンプルのみ（D15）
- Pro版スコープ: 上限解除（無制限移行）+ 301リダイレクトCSV。買切り「移行プロジェクトライセンス」（サイト数無制限・初回アクティベーションから3ヶ月のアップデート/サポート、D14）
- **継続同期（定期差分同期・在庫双方向同期）は販売しない**（D14で確定。旧Phase 6構想は廃止）

## 2. 対応プラットフォームとAPI概要比較

| 項目 | カラーミーショップ | MakeShop | BASE |
|---|---|---|---|
| **対応バージョン（D18）** | **v1.0** | v3.0 | v2.0 |
| API形式 | REST + JSON（OpenAPI定義あり） | GraphQL | REST + JSON（β版） |
| 認証 | OAuth2認可コード（トークン無期限） | 永続トークン（API自社利用登録）/ SSO一時トークン | OAuth2認可コード（**アクセストークン1時間+リフレッシュ30日ローテーション**） |
| レート制限 | 120req/分/トークン（目安） | 要確認 | 5,000req/時（超過は**HTTP 400**）+ 商品登録1,000件/日 |
| 商品 | GET/POST/PUT（DELETE不可） | Query/Mutation（create/update/delete、CSV一括登録も可） | GET/add/edit（deleteあり・不使用）。画像はURL指定で登録可 |
| カテゴリ | GETのみ（**作成不可**） | 取得/作成/更新可 | 取得/作成/更新可（**3階層まで**） |
| 顧客 | GET/POST/PUT（DELETE不可） | 取得/登録/更新/削除/パスワード更新/ポイント更新 | **APIなし**（受注詳細の購入者情報から抽出のみ） |
| 受注 | GET/POST/PUT+キャンセル（DELETE不可） | 取得/登録/キャンセル/属性変更/配送ステータス変更 | 取得+発送ステータス変更のみ（**新規作成不可**） |
| クーポン | GETのみ | CRUD可 | APIなし |
| 在庫 | GET（更新は商品PUT経由） | 取得/更新（バリエーション在庫含む） | 商品GET経由 / edit_stockで更新 |
| バリエーション | 2軸 | 2軸（縦横） | **1軸のみ** |
| Webhook | なし | あり（アプリ向け: install/uninstall/決済） | なし |

詳細は `01-plan-colorme.md` / `02-plan-makeshop.md` / `04-plan-base.md` を参照。

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
  （v1.0: ColorMe / v2.0: Base / v3.0: MakeShop）    （WC CRUD APIのラッパー）
                    │
        Support\{HttpClient, RateLimiter, TokenStore, Logger}
```

### 3.2 PlatformAdapter インターフェース

> **注**: 本節は初期構想。**確定版のIFは `03-design-decisions.md` §2**（オプショナルエンティティ=D5、
> サンプル選定用メソッド=D15 を含む）を参照。

```php
namespace CartBridgeJP\Adapters;

interface PlatformAdapter {
    public function id(): string;                     // 'colorme' | 'makeshop' | 'base'
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

## 4. 開発フェーズ・リリース計画

リリースは**1プラットフォームずつ**行う（D18・2026-09-05改訂）。各バージョンで対象ASPのインポート（ASP→Woo）と
エクスポート（Woo→ASP）を揃えて公開する。アーキテクチャ（PlatformAdapter / Canonical / Capabilities / TokenStore 等）は
当初どおり3ASP対応で設計・実装済みのため、v2.0以降は**アダプタの追加のみ**で成立させることを検証観点とする。

| バージョン | 対象 | フェーズ |
|---|---|---|
| v1.0 | カラーミーショップ（インポート＋エクスポート） | Phase 0〜3 |
| v2.0 | BASE（インポート＋エクスポート。受注・顧客のエクスポートはAPI制約で対象外） | Phase 4〜5 |
| v3.0 | MakeShop（インポート＋エクスポート） | Phase 6〜7 |

タスク単位の台帳は `10-tasks.md`（旧計画からのタスクID採番変更も同ファイル冒頭に記載）。

### v1.0: カラーミーショップ

#### Phase 0: 基盤（完了）

1. プラグインスケルトン（メインファイル、Composer/PSR-4、activation hookでテーブル作成、HPOS互換宣言）
2. `wp-env` セットアップ（`.wp-env.json`: WooCommerce同梱、PHP 8.2）
3. CI用に PHPCS / PHPStan / PHPUnit の設定
4. `Support\` 層: HttpClient（リトライ+指数バックオフ）、RateLimiter（トークンバケット）、TokenStore（暗号化: `sodium_crypto_secretbox` + AUTH_KEYベース）、Logger
5. `PlatformAdapter` インターフェース・Canonicalモデル・Capabilities
6. 管理画面の骨格（設定ページ、接続管理タブ、React環境）

BASE/MakeShop を見込んで先行実装した基盤（TokenStoreのリフレッシュ構造=D13、HttpClientのレート制限判定フック、
`canFetchCustomers` 等の capability）は v1.0 でもそのまま残す（削除・簡略化しない）。

#### Phase 1: カラーミー → Woo インポート（MVP）

`01-plan-colorme.md` 参照。OAuth接続 → カテゴリ → 商品（画像sideload）→ 顧客 → 受注 → 在庫。dry-run必須。

#### Phase 2: Woo → カラーミー エクスポート

- カテゴリマッピングUI（カラーミーは作成不可のため既存カテゴリ選択。自動作成の分岐は capability `canCreateCategory` で切り替えられる構造にし、実装は v2.0）
- 商品・顧客・受注・在庫のエクスポート。SKU/email突合でupsert。無料版はWoo側の最新受注10件起点のサンプル上限（D15）、実行前の本番書込み警告（D17）
- 画像: `canPushImages`（プレミアムプラン判定。03 §9 #1）が false のショップは画像URL一覧CSVの代替フロー案内
- **受注**: `canCreateOrder`（`POST /sales.json` はプレミアムプラン契約ショップのみ利用可=`ColorMeAdapter::is_premium_plan()`）が false のショップでは受注エクスポート不可。エクスポート先エンティティ選択UIは capability を尊重し、レギュラープランでは受注を選択肢から除外する（画像同様プラン依存であることをE2-1のマッピングUI・E2-2のExporterで扱う）

#### Phase 3: v1.0 仕上げ・公開

- カラーミーのテストショップで往復E2E（実データ移行リハーサル）
- readme.txt、スクリーンショット、wordpress.org申請（スラッグ `cart-bridge-jp`）。プラグインヘッダー・`composer.json` の Description を「Color Me Shop」のみに改める（03 §7）
- WooCommerce is a trademark of Automattic の表記、カラーミーは本文中で「対応プラットフォーム」として言及（名称・スラッグに商標を含めない）

### v2.0: BASE

#### Phase 4: BASE → Woo インポート

`04-plan-base.md` 参照。OAuthリフレッシュトークン対応（1時間期限+30日ローテーション。TokenStore側は Phase 0 で実装済み=D13）。
顧客は受注購入者からのemail名寄せ抽出（顧客一覧APIなし=D12）。クーポン・タグ・レビューは対象外。
Importer本体を変更せずアダプタ追加のみで動くことを確認（アーキテクチャ検証を兼ねる。旧計画ではMakeShopが担っていた観点）。

#### Phase 5: Woo → BASE エクスポート + v2.0公開

- 商品・カテゴリ（自動作成可・3階層まで）・在庫のみ（受注作成・顧客APIなし）。商品登録1,000件/日の分割実行、バリエーション1軸化の警告
- 画像はURL指定登録（要検証#13）
- readme / Description に BASE を追記して 2.0.0 を公開

### v3.0: MakeShop

#### Phase 6: MakeShop → Woo インポート

`02-plan-makeshop.md` 参照。GraphQLクライアント + 永続トークン接続。

#### Phase 7: Woo → MakeShop エクスポート + v3.0公開

- カテゴリ自動作成 → 商品 → 会員 → 注文（決済なしモード）→ 在庫。1,000商品超は `importProductBulk`（CSV一括）経路を検討
- 画像: `canPushImages` の検証結果（要検証#4）に応じ、不可の場合はCSV+画像一括登録の代替フロー案内
- readme / Description に MakeShop を追記して 3.0.0 を公開

### Pro版アドオン（別リポジトリ・フェーズ番号なし）

- ライセンス統合: WooCommerce API Manager クライアント（アクティベーション・アップデート取得。期限起点は初回アクティベーション=D14）
- `cbjp/limits/*` フィルターによる無料版上限の解除（プラットフォーム非依存。v2.0/v3.0 のアダプタ追加で Pro 側の変更は不要）
- 301リダイレクトCSV生成（旧商品URL→新商品URL、mappingsから生成）
- 本リポジトリ側の対応は拡張ポイント（`cbjp/limits/*` フィルター・AdapterRegistry）の提供のみ。継続同期は販売しない（D14）
- v1.0 公開と同時に Pro 版を購入可能にするか（無料版の Pro 案内の導線先）は公開前に判断する（`10-tasks.md` Phase 3 の要判断）

## 5. 管理画面UI構成

1. **接続管理**: プラットフォーム追加（カラーミー/BASE: OAuthウィザード / MakeShop: トークン+エンドポイント入力）、接続テスト。表示されるのは AdapterRegistry に登録済みのアダプタのみ（v1.0 はカラーミーだけ）
2. **インポート**: エンティティ選択 → dry-runプレビュー（件数・警告一覧、CSVダウンロード）→ 実行 → 進捗バー（RESTポーリング）→ 結果レポート（件数・金額突合の検証レポート含む）。無料版は上限到達時に残件数つきでPro版を案内（D15/D17）
3. **エクスポート**: 同上 + マッピング設定（カテゴリ/決済方法/配送方法/注文ステータス）+ 実行前の本番書込み警告（D17）
4. **ログ**: ジョブ履歴・エラー詳細・再実行ボタン
5. **ツール**: サンプルクリーンアップ / リンク再構築（D16。専用の Tools タブに配置）

## 6. セキュリティ・運用要件

- トークンは暗号化保存、画面には末尾4桁のみ表示
- REST APIは `manage_woocommerce` + nonce
- 個人情報（顧客・受注）のログ出力禁止（context_jsonにはIDのみ）
- アンインストール時: 設定・マッピング・ログの削除可否をオプションで選択（デフォルト保持）

## 7. リスクと要検証事項（着手前にフィクスチャ収集）

| # | 項目 | 影響 | 対応 |
|---|---|---|---|
| 1 | カラーミー: 商品POST/PUTで画像登録可否 | Phase 2（E2-3）のUX | swagger.json精査+テストショップ実測 |
| 2 | MakeShop: レート制限値 | JobRunner設計（v3.0） | FAQ/問い合わせで確認 |
| 3 | MakeShop: API自社利用登録の条件（プラン・費用） | 利用者の前提条件（v3.0） | 公式に確認しREADMEに明記 |
| 4 | MakeShop: createProductの画像入力形式 | v3.0 Phase 7 | GraphQLスキーマ精査+実測 |
| 5 | カラーミー: 受注POSTの必須項目と決済/配送ID参照 | 受注エクスポート（Phase 2 E2-3） | テストショップ実測 |
| 6 | 大規模ショップ（商品1万点超）のジョブ実行時間 | 全Phase | カーソル分割+Action Schedulerの並列度調整 |
| 7 | カラーミー: リダイレクトURIのhttps要否（ローカル開発時のOAuth可否） | Phase 1 | テストショップ実測 |
| 8 | MakeShop: searchProduct等のページング方式 | v3.0 Phase 6 | リファレンス精査+実測 |
| 9 | BASE: リダイレクトURIのhttps要否（ローカル開発時のOAuth可否） | v2.0 Phase 4 | テストショップ実測 |
| 10 | BASE: 明細単位発送ステータスの注文全体への集約規則 | 受注移行（v2.0） | テストショップ実測（一部発送の受注を作成） |
| 11 | BASE: エラーレスポンス形式・レート制限超過時の挙動（Retry-After有無） | v2.0 Phase 4 | 実測 |
| 12 | BASE: API利用費用・スコープ承認フロー | READMEの前提条件記載（v2.0） | 公式FAQ確認（登録済みのため契約内容を記録） |

（#7以降の確定状況は `03-design-decisions.md` §9 のトラッカーで管理）

## 8. ディレクトリ構成

```
cart-bridge-jp/
├── cart-bridge-jp.php          # メインファイル（ヘッダー、autoload、起動）
├── CLAUDE.md
├── docs/
│   ├── 00-plan-overview.md
│   ├── 01-plan-colorme.md
│   ├── 02-plan-makeshop.md
│   ├── 03-design-decisions.md
│   ├── 04-plan-base.md
│   └── 10-tasks.md
├── includes/
│   ├── Core/                   # Plugin, Activator, Uninstaller, Container
│   ├── Adapters/
│   │   ├── PlatformAdapter.php, Capabilities.php, Cursor.php, Page.php
│   │   ├── ColorMe/            # ColorMeAdapter, ColorMeClient, OAuth, 変換クラス（v1.0）
│   │   ├── Base/               # BaseAdapter, BaseClient, BaseOAuth, 変換クラス（v2.0で追加）
│   │   └── MakeShop/           # MakeShopAdapter, GraphQLClient, 変換クラス（v3.0で追加）
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
