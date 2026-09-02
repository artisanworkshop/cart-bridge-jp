# 設計補遺・確定事項

最終更新: 2026-07-09

`00-plan-overview.md` を具体化した実装設計。他の計画ドキュメント（00〜02・04）と本書が矛盾する場合は**本書を優先**する。
タスクの進行管理は `10-tasks.md` を参照。

## 1. 確定した方針（ユーザー確認済み・2026-07-06 / D11〜D13は2026-07-07 / D14〜D17は2026-07-08）

| # | 論点 | 決定 |
|---|---|---|
| D1 | 最初の開発範囲 | Phase 0 のみを最初のマイルストーンとする |
| D2 | API資格情報 | カラーミー: デベロッパー登録・テストショップ・アプリ登録済み / MakeShop: 自社利用登録・エンドポイント・永続トークン取得済み / BASE: BASE Developersアプリ登録済み・テストショップあり（2026-07-07確認）。**要検証事項は各Phaseの最初のタスクで実測して確定する** |
| D3 | タスク管理 | `docs/10-tasks.md` のWBSで管理（GitHub Issuesは使わない） |
| D4 | CI | GitHub Actions を Phase 0 で構築（リモート: `artisanworkshop/cart-bridge-jp`） |
| D5 | インターフェース範囲 | クーポン・タグ（カラーミーのグループ）・レビュー（MakeShop）を**オプショナルエンティティとして Phase 0 のIFに組み込む**（実装は後Phase） |
| D6 | 管理画面 | Phase 0 から React（@wordpress/scripts + TypeScript）基盤を構築 |
| D7 | 接続数 | 無料版は 1接続/プラットフォーム。DBは `platform` カラムで識別（将来 `connection_id` 追加で拡張可能な構造にする） |
| D8 | ブランチ運用 | `main` をデフォルトに、フェーズ/タスクごとに `feat/xxx` ブランチ → PR → CI通過でマージ。既存の `trunk` ブランチは `main` に統合して廃止 |
| D9 | 作者表記 | Author: Artisan Workshop（GitHub org と一致。Author URI は実装時に実URLを確認） |
| D10 | 受注明細の未マッチ商品 | Woo側に商品が無い明細は**カスタム行**（注文時商品名・単価・数量をそのまま）として作成し、元商品IDをメタ保存。スキップしない |
| D11 | BASE対応の追加 | 対応プラットフォームに **BASE を追加**し、**Phase 3（MakeShopの次）**でインポートを実装。エクスポートはAPIが許す範囲（商品・カテゴリ・在庫のみ）で Phase 4 に含める。**v1.0公開はBASE込み**（フェーズ構成: 0基盤→1カラーミー→2MakeShop→3BASE→4エクスポート→5公開）。詳細は `04-plan-base.md` |
| D12 | BASEの顧客移行方式 | BASEには顧客一覧APIが無いため、**受注インポート時に購入者情報からemail名寄せで顧客を生成**（オプション、デフォルトON。初回作成のみで上書きしない）。単独の顧客エンティティとしてはUIに出さない（`canFetchCustomers: false`） |
| D13 | 有効期限付きトークン対応 | BASEのアクセストークン1時間+リフレッシュトークン30日ローテーションに対応するため、**TokenStoreはPhase 0から構造化ペイロード（access/refresh/expires_at）+リフレッシュ排他ロックを前提に設計**する（§4参照。カラーミー/MakeShopは単一トークンとして同構造に格納） |
| D14 | ビジネスモデル | 無料版=挙動確認用（**dry-runは全量無料**+実移行はサンプルのみ）。Pro版=買切り**「移行プロジェクトライセンス」**: サイト数無制限・**初回アクティベーションから3ヶ月**のアップデート&サポート・認証済みサイトは期限後も永続動作（新規サイト認証と更新のみ不可）・価格 ¥19,800 前後・自社サイト直販（**WooCommerce API Manager**）・返金保証なし（無料版で事前検証可能なことを明記）。**継続同期（Pro同期）は販売しない**。詳細は §10.1 |
| D15 | 無料版の実行上限 | **最新受注10件起点のサンプル移行**: サンプル受注に紐づく商品（ハードキャップ50件）・顧客（最大10件）・受注10件のみ実インポート/エクスポート可。カテゴリ/タグは全量無料。上限はサーバーサイド（JobManager）で強制し、`cbjp/limits/{entity}` フィルター（総称表記: `cbjp/limits/*`）でPro版が解除。詳細は §10.2 |
| D16 | Pro本移行時の重複防止 | mappings による冪等 upsert + 本移行はカーソル先頭から全走査。取込済みデータの扱いは**開始時に選択式（更新/スキップ、デフォルト更新）**。mappings欠損時の**リンク再構築ツール**（SKU/email/注文番号突合）と**サンプルクリーンアップツール**を提供。詳細は §10.3 |
| D17 | 付帯機能 | dry-runレポートCSVダウンロード / 移行後検証レポート（件数・金額突合）/ 301リダイレクトCSV（Pro）/ エクスポート実行前の本番書込み警告 を実装する。期限切れ後の再購入導線（リピート割引等）は**実装しない**。詳細は §10.4 |

## 2. PlatformAdapter インターフェース（確定版）

`00-plan-overview.md` §3.2 を D5 に基づき拡張。

```php
namespace CartBridgeJP\Adapters;

interface PlatformAdapter {
    public function id(): string;                     // 'colorme' | 'makeshop' | 'base'
    public function label(): string;
    public function capabilities(): Capabilities;
    public function testConnection(): ConnectionResult;

    // 接続設定スキーマの宣言（UIが動的にフォーム生成。例: makeshopはendpoint+token）
    public function connectionFields(): array;        // ConnectionField[]

    // 取得（カーソルベースで再開可能）
    public function fetchProducts( Cursor $cursor ): Page;   // Page<CanonicalProduct>
    public function fetchCategories(): array;                // CanonicalCategory[]
    public function fetchTags(): array;                      // CanonicalTag[]（colorme: groups）
    public function fetchCustomers( Cursor $cursor ): Page;  // Page<CanonicalCustomer>
    public function fetchOrders( Cursor $cursor ): Page;     // Page<CanonicalOrder>
    public function fetchStocks( Cursor $cursor ): Page;     // Page<CanonicalStock>
    public function fetchCoupons( Cursor $cursor ): Page;    // Page<CanonicalCoupon>
    public function fetchReviews( Cursor $cursor ): Page;    // Page<CanonicalReview>（makeshopのみ）

    // 無料版サンプル選定・ID指定取得（D15。詳細は§10.2。API対応可否は要検証#14/#15）
    public function fetchLatestOrders( int $limit ): array;                          // CanonicalOrder[]（新しい順）
    public function fetchProductByRemoteId( string $remoteId ): ?CanonicalProduct;   // 404はnull
    public function fetchCustomerByRemoteId( string $remoteId ): ?CanonicalCustomer; // base: UnsupportedOperationException（D12）

    // 書き込み（capabilityで不可のものは UnsupportedOperationException）
    public function pushProduct( CanonicalProduct $p, ?string $remoteId ): PushResult;
    public function pushCategory( CanonicalCategory $c ): PushResult;
    public function pushCustomer( CanonicalCustomer $c, ?string $remoteId ): PushResult;
    public function pushOrder( CanonicalOrder $o ): PushResult;
    public function pushStock( CanonicalStock $s ): PushResult;
    public function pushCoupon( CanonicalCoupon $c, ?string $remoteId ): PushResult;
}
```

### Capabilities（readonly値オブジェクト）

```php
final class Capabilities {
    public function __construct(
        public readonly bool $canCreateCategory,
        public readonly bool $canCreateOrder,     // base: false（注文作成APIなし）
        public readonly bool $canFetchCustomers,  // colorme/makeshop: true / base: false（受注から抽出=D12）
        public readonly bool $canUpdateCustomer,
        public readonly bool $canPushImages,      // 要検証#1/#4の結果で確定。base: true（URL指定方式）
                                                   // colorme: 接続先ショップのcontract_plan（shop.json）を見てプラン依存で算出（§9 #1）
        public readonly bool $canCreateCoupon,
        public readonly bool $hasCoupons,         // colorme: true（読取のみ）/ makeshop: true / base: false
        public readonly bool $hasTags,            // colorme: true（groups）/ makeshop: false / base: false
        public readonly bool $hasReviews,         // colorme: false / makeshop: true / base: false
        public readonly bool $hasVariants,        // base: true（ただし1軸のみ）
        public readonly int  $rateLimitPerMinute,
    ) {}
}
```

UI・JobManager は capability が false のエンティティを選択肢から除外する。アダプタ側は
非対応メソッドで `UnsupportedOperationException` を投げる（防御の二重化）。

### 値オブジェクト仕様

- **`Cursor`**: 不透明なペイロード `array<string,mixed>`（colorme: `['offset' => int]`、makeshop: ページング仕様確定後に定義、base: `['offset' => int]`（limit最大100）。`toJson()/fromJson()` で `cbjp_jobs.cursor_json` に永続化。初回は `Cursor::start()`。
- **`Page`**: `items: array`（Canonical配列）、`nextCursor: ?Cursor`（null = 終端）、`total: ?int`（取得可能な場合のみ。進捗率表示用）。
- **`PushResult`**: `remoteId: string`、`operation: 'created'|'updated'|'skipped'`、`warnings: string[]`。
- **`ConnectionResult`**: `ok: bool`、`shopName: ?string`、`message: ?string`（失敗理由。トークン等の機密を含めない）。
- **`ConnectionField`**: `key, label, type('text'|'password'|'oauth_button'), required, help`。

### Canonical追加モデル

- `CanonicalTag`（id, name）
- `CanonicalCoupon`（code, type('fixed'|'percent'), amount, minAmount, expiresAt, usageLimit, extras）
- `CanonicalReview`（productRef, authorName, rating, title, content, createdAt, extras）

## 3. DBスキーマ（DDL確定版）

dbDelta 互換で `Core\Activator` が作成。スキーマバージョンを `cbjp_db_version` オプションに保存し、
将来のマイグレーションは Activator でバージョン比較して実行。

```sql
CREATE TABLE {$prefix}cbjp_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id CHAR(36) NOT NULL,                 -- 1回の移行実行（複数エンティティ）を束ねるUUID
  type VARCHAR(20) NOT NULL,                -- 'import' | 'export' | 'dry_run'
  platform VARCHAR(20) NOT NULL,            -- 'colorme' | 'makeshop' | 'base'
  entity VARCHAR(20) NOT NULL,              -- 'product'|'category'|'tag'|'customer'|'order'|'stock'|'coupon'|'review'
  status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- 下記ステートマシン参照
  cursor_json TEXT NULL,
  totals_json TEXT NULL,                    -- {total,processed,created,updated,skipped,warned,failed}
  error_json TEXT NULL,                     -- 失敗時の最終エラー {code,message}（個人情報禁止）
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY status_platform (status, platform)
);

CREATE TABLE {$prefix}cbjp_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform VARCHAR(20) NOT NULL,
  entity_type VARCHAR(20) NOT NULL,
  remote_id VARCHAR(191) NOT NULL,
  local_id BIGINT UNSIGNED NOT NULL,
  checksum CHAR(64) NULL,                   -- Canonical正規化JSONのsha256。差分検出用
  synced_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY platform_entity_remote (platform, entity_type, remote_id),
  KEY platform_entity_local (platform, entity_type, local_id)
);

CREATE TABLE {$prefix}cbjp_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NULL,
  level VARCHAR(10) NOT NULL,               -- 'debug'|'info'|'warning'|'error'
  message TEXT NOT NULL,
  context_json TEXT NULL,                   -- IDのみ。個人情報・トークン禁止
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY job_level (job_id, level),
  KEY created_at (created_at)
);
```

- `00-plan-overview.md` §3.5 からの変更点: `direction` 列を廃止（`type` で判別可能）、
  `run_id`・`entity` 列を追加、`cursor`→`cursor_json`（CURSORはMySQL予約語）。
- ログ保持: 日次cron（Action Scheduler）で30日超を削除。日数は `cbjp/logs/retention_days` フィルターで変更可。
- 接続情報・マッピング設定はオプションテーブル（`cbjp_settings_{platform}`、autoload無効）。トークンのみ TokenStore（§4）。

### ジョブのステートマシン

```
pending → running → completed
                  → failed      （リトライ上限到達。UIから retry で pending に戻せる）
                  → cancelled   （ユーザー操作）
running ⇄ paused                （レート制限長期化・ユーザー操作時）
```

1回の移行実行（run）はエンティティ順序 `category → tag → product → customer → order → stock → coupon → review`
で per-entity のジョブを直列実行（依存関係: 商品はカテゴリに、受注は商品・顧客に依存するため）。

## 4. Support層 設計

### TokenStore

- 暗号化: `sodium_crypto_secretbox`（PHP 7.2+ 標準バンドルのため fallback 不要。念のため activation 時に `function_exists('sodium_crypto_secretbox')` を検査し、無ければ管理画面通知）
- 鍵導出: `sodium_crypto_generichash( AUTH_KEY . AUTH_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES )`
- nonce は保存ごとにランダム生成し `base64( nonce . ciphertext )` をオプション `cbjp_token_{platform}`（autoload無効）に保存
- **保存単位は構造化ペイロード（D13）**: `{access_token, refresh_token?, expires_at?, extras?}` のJSONを暗号化。
  カラーミー（無期限）/ MakeShop（永続）は `refresh_token`/`expires_at` なしで同構造に格納
- **リフレッシュ排他ロック**: 有効期限付きトークン（BASE）の更新は `$wpdb` の原子的UPDATE（GET_LOCKまたはオプションCAS）で排他し、
  ローテーション式refresh_tokenの二重更新による失効を防ぐ。更新後は新しいaccess/refresh両方を即時上書き保存
- `AUTH_KEY` 変更等で復号失敗した場合、およびリフレッシュトークン失効（BASE: 30日超の放置）の場合は例外にせず「再接続が必要」状態を返し、UIで再接続を促す
- 画面表示は末尾4文字のみ（`****abcd`）

### HttpClient

- `wp_remote_request` ラッパー。タイムアウト30秒、`User-Agent: CartBridgeJP/{ver}`
- リトライ: 429/5xx/接続タイムアウトで指数バックオフ+ジッター（1s→2s→4s、最大3回）。`Retry-After` ヘッダーがあれば優先
- 4xx（429以外）はリトライせず `ApiException`（platform固有のエラー配列→メッセージ変換はアダプタ側Client担当）
- **例外**: BASEはレート制限超過を **HTTP 400** + エラーコード `hour_api_limit`/`day_api_limit` で返すため、
  「このレスポンスはレート制限か」の判定をアダプタ側Clientがフックできる拡張ポイント（コールバックまたはoverride）を設ける
- 全リクエストは呼び出し前に RateLimiter の許可を取る

### RateLimiter

- トークンバケット方式。プラットフォームごとに `capacity = rateLimitPerMinute`、毎分補充
- 状態はオプションに保存し、`$wpdb` の原子的UPDATEで競合回避（Action Schedulerの並列実行対策）
- 枯渇時は `wait()` でスリープ（Action Scheduler内なので許容）。長時間枯渇はジョブを `paused` にして次のスケジュールへ

### Logger

- `cbjp_logs` テーブルへの書き込み + `WC_Logger` へのミラー（source: `cart-bridge-jp`）
- context には エンティティ種別・remote_id・local_id のみ。氏名・メール・住所・トークンの記録を**コードレビュー観点として禁止**

## 5. ジョブ実行（Sync層）設計

- `JobManager::startRun( type, platform, entities[] )` → run_id 発行、per-entity ジョブ作成、先頭を Action Scheduler にエンキュー
- 1回のASアクション = 1ページ処理（fetch → 変換 → Woo書き込み → mappings upsert → cursor更新）。処理後に自分を再エンキュー（`as_enqueue_async_action`）。終端で次エンティティのジョブを起動
- ページサイズ初期値50（アダプタが上書き可）。1アクションはPHPのmax_execution_time内に収まる粒度を保つ
- **dry-run**: 同一パイプラインで Woo書き込みだけを `DryRunReporter` に差し替え。件数・警告（未マッピング決済方法、SKU重複等）を totals_json に集計し、UIでプレビュー表示
- 冪等性: mappings の UNIQUE キーで upsert。checksum 一致ならスキップ（totals.skipped++）
- 同時実行: 同一 platform で running のジョブがある場合は新規開始を拒否（レート制限保護）

### 受注インポートの詳細（D10）

1. 明細のSKU（無ければ remote product id → mappings）でWoo商品を解決
2. 解決できた明細: 商品リンク付き line item（ただし価格・商品名は**注文時の値**を使用）
3. 解決できない明細: 商品リンクなしのカスタム line item（注文時商品名・単価・数量）+ メタ `_cbjp_remote_product_id`
4. 合計・送料・手数料・割引はASP側の値をそのまま設定（Wooに再計算させない）
5. 注文メタ: `_cbjp_platform`, `_cbjp_remote_order_number`, 未マッピングの決済/配送は `_cbjp_original_payment_method` 等に元名称を保存
6. ステータスマッピングは 01/02 の表に従う。受注メール・在庫減算・ポイント付与等の副作用は全て抑止（`wc_create_order` 後に直接プロパティ設定、通知フック一時解除）

### 税の扱い

カラーミー・MakeShop・BASEとも価格は税込（BASEは `item_tax_type` で軽減税率商品を判別可能。extrasに保存）。インポート開始前に Woo の
`woocommerce_prices_include_tax` が `no` の場合は dry-run 警告に含める（自動変更はしない）。

## 6. 管理画面・REST API 設計

### REST ルート（namespace: `cbjp/v1`、permission: 特記なき限り `manage_woocommerce` + nonce）

| Method | Route | 用途 |
|---|---|---|
| GET | `/connections` | 全プラットフォームの接続状態一覧 |
| PUT | `/connections/{platform}` | 接続設定保存（makeshop: endpoint+token / colorme・base: client_id+secret） |
| DELETE | `/connections/{platform}` | 接続解除（トークン削除） |
| POST | `/connections/{platform}/test` | 接続テスト（ショップ名を返す） |
| GET | `/connections/{platform}/authorize-url` | OAuth認可URL取得（OAuth型プラットフォーム: colorme / base）。`?mode=oob` でコード手動貼り付けフォールバック用URLを取得 |
| POST | `/connections/{platform}/exchange-code` | OAuthコード手動貼り付けフォールバック（`{code}`。認証済み管理画面からの呼び出しのため通常のnonce+capability保護のみ。F1-2で追加） |
| GET | `/connect/{platform}/callback` | OAuthコールバック（ASP側に登録する公開URL。**permission例外**: `__return_true` + state検証必須。詳細は下記） |
| POST | `/runs` | 移行実行の開始 `{type, platform, entities[]}` |
| GET | `/runs/{run_id}` | 進捗（per-entityジョブのstatus/totals。UIが2秒間隔でポーリング） |
| POST | `/runs/{run_id}/cancel` | キャンセル |
| POST | `/jobs/{id}/retry` | 失敗ジョブの再実行 |
| GET | `/logs?job_id=&level=&page=` | ログ閲覧 |
| GET/PUT | `/settings/mappings/{platform}` | 決済/配送/注文ステータスのマッピング設定 |
| GET | `/limits?platform={platform}` | 無料版上限・Pro解除状態（アップセル表示用。D15/§10.2）。`platform` 指定時は使用状況（mappings累積カウント）・残数も返す |
| POST | `/tools/sample-cleanup` | 無料版サンプルデータの一括削除（mappings記録に基づく。D16/§10.3） |
| POST | `/tools/rebuild-mappings` | SKU/email/注文番号メタの突合による mappings 再構築（D16/§10.3） |

nonce（`X-WP-Nonce`）は管理画面Reactアプリからの呼び出しにのみ適用。`/connect/{platform}/callback` は
ASPからの外部リダイレクトで叩かれるためnonce・capabilityを課さず、代わりに `state` ワンタイムトークンで検証する。

### OAuth コールバック（カラーミー・BASE共通）

- コールバックURL: `{site_url}/wp-json/cbjp/v1/connect/{platform}/callback`（ユーザーが各ASPのアプリ登録画面に登録する。設定画面にコピー用で表示）
- `state` = ワンタイムトークン（transient、10分、管理ユーザーIDに紐付け）で CSRF 対策。`permission_callback` は `__return_true` とし、state 検証を必須にする
- code→トークン交換後、管理画面（接続タブ）へリダイレクト
- **フォールバック**: ローカル開発（http://localhost:8888）でASP側がhttpsリダイレクトURIを要求する場合に備え、「認可後のURLからcodeを手動貼り付け」する入力欄も用意（要検証#7/#9。BASEの認可コード有効期限は約1時間なので手動貼付でも運用可能）
- BASE固有: トークン交換・リフレッシュ時に `redirect_uri` パラメータが**毎回必須**（BaseOAuthで保持）

### React アプリ（src/）

- `@wordpress/scripts` ビルド、TypeScript strict、`@wordpress/components` + `@wordpress/api-fetch`
- ルーティングは単一管理ページ内のタブ切替（Connections / Import / Export / Logs / **Tools**）。URLは `#/import` 形式。Tools タブにはサンプルクリーンアップ / リンク再構築（D16、`/tools/*` ルート）を配置
- ページ登録: WooCommerce メニュー配下 `admin.php?page=cart-bridge-jp`
- UI文字列は英語 + `@wordpress/i18n`（`wp_set_script_translations`）

## 7. プラグインヘッダー・互換宣言（確定）

```php
/**
 * Plugin Name: Cart Bridge JP – Migrate for WooCommerce
 * Description: Migrate products, customers, and orders between Japanese e-commerce platforms (Color Me Shop, MakeShop, BASE) and WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Author: Artisan Workshop
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cart-bridge-jp
 * Domain Path: /languages
 *
 * WC requires at least: 10.0
 */
```

- HPOS: `before_woocommerce_init` で `FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true )`
- WooCommerce 未有効時は管理画面通知を出して機能を無効化（fatalにしない）
- アンインストール: `uninstall.php`。オプション `cbjp_delete_data_on_uninstall`（デフォルトfalse）が true の場合のみテーブル・オプション削除

## 8. CI（GitHub Actions）

`.github/workflows/ci.yml` — push / PR（main宛）で実行:

1. **php-quality**: PHP 8.2/8.3 マトリクスで `composer lint`（PHPCS）+ `composer analyze`（PHPStan level 6）
2. **php-test**: `wp-env` を起動して `composer test`（PHPUnit）
3. **js**: `npm ci && npm run lint && npm run build`（tsc型チェック含む）

## 9. 要検証事項トラッカー（00 §7 の具体化）

| # | 項目 | 確定タイミング | 状態 |
|---|---|---|---|
| 1 | カラーミー: 商品POST/PUTの画像登録可否 | Phase 1 タスク F1-0（swagger精査+実測） | **済（プラン依存）**: `products.json`のcreate/update本体に画像フィールドはないが、専用エンドポイント`POST /v1/products/{product_id}/images`（マルチパート、`image`+`position`）が別途存在する。**ただしプレミアムプラン契約ショップのみ利用可**（レギュラープラン等は403想定・要実機確認）。`canPushImages`は固定falseではなく、`GET /shop.json`の`contract_plan`を見てプラン依存で判定する設計に変更（F1-5で実装、E4-3で画像push実装時に403時のCSVフォールバックへの切替を含める） |
| 2 | MakeShop: レート制限値 | Phase 2 タスク M2-0（FAQ/問い合わせ） | 未 |
| 3 | MakeShop: 自社利用登録の条件（プラン・費用） | 取得済みのため契約内容をREADME用に記録（M2-0） | 未 |
| 4 | MakeShop: createProduct の画像入力形式 | Phase 2 タスク M2-0 | 未 |
| 5 | カラーミー: 受注POSTの必須項目・決済/配送ID | Phase 4 タスク E4-3（テストショップ実測） | 未 |
| 6 | 大規模ショップのジョブ実行時間 | Phase 1 E2E（F1-8）で計測 | 未 |
| 7 | カラーミー: リダイレクトURIのhttps要否（ローカル開発時のOAuth可否） | Phase 1 タスク F1-2 | 未 |
| 8 | MakeShop: searchProduct等のページング方式（cursor/offset・最大件数） | Phase 2 タスク M2-0 | 未 |
| 9 | BASE: リダイレクトURIのhttps要否・localhost可否 | Phase 3 タスク B3-0 | 未 |
| 10 | BASE: 明細単位発送ステータスの注文全体への集約規則（dispatch_statusの実値一覧含む） | Phase 3 タスク B3-0 | 未 |
| 11 | BASE: エラーレスポンス形式・レート制限超過時の挙動（Retry-Afterヘッダー有無） | Phase 3 タスク B3-0 | 未 |
| 12 | BASE: API利用費用・スコープ承認フロー（README前提条件用） | Phase 3 タスク B3-0（公式FAQ確認） | 未 |
| 13 | BASE: add_image のURL取得要件（Basic認証下・ローカルURLの挙動）と canPushImages 最終確定 | Phase 4 タスク E4-5 | 未 |
| 14 | 各ASP: 一覧APIの新しい順ソート指定可否（受注は必須、商品・顧客・クーポンはフォールバック用。サンプル選定=D15） | F1-0 / M2-0 / B3-0 | **カラーミー済**: `GET /sales.json`はソートパラメータなしでデフォルト`make_date`降順（新しい順）で返るが、**`after`/`before`省略時の検索対象は直近7日間に限定される**（`after`未指定時は`before`の7日前0時がデフォルト。swagger実測確認）。ショップの直近7日間の受注が10件未満の場合、`fetchLatestOrders(10)`は探索窓（`after`）を過去方向へ4倍ずつ広げて複数回リクエストし、10件集まるか受注履歴の下限（2000-01-01）に達するまで走査する（**F1-5実装済み**: `before`は常に省略し暗黙の現在時刻に固定したまま`after`のみを広げる方式。`fetch_orders`によるカーソル全量走査も`after=2000-01-01`を明示することで直近7日制限を回避する。テストショップでの実測未確認、F1-8で確認）。MakeShop/BASEは未 |
| 15 | 各ASP: 商品・顧客のID指定取得エンドポイントの有無（サンプル取得=D15） | F1-0 / M2-0 / B3-0 | **カラーミー済**: `GET /products.json` `/customers.json` `/sales.json` すべて `ids` クエリパラメータで複数ID指定取得可能。個別詳細 `/products/{id}.json` 等も利用可（swagger + 実測確認）。MakeShop/BASEは未 |
| 16 | カラーミー: 商品の定価（`price`）が税抜/税込どちらか（`CanonicalProduct.sale_price` への反映可否） | F1-3で判明。実店舗での実測時（Phase 1 E2E等） | 未（`ProductTransformer`実装時、PR#9のCodexレビューで指摘）。`price`には`sales_price_including_tax`のような税込版フィールドがAPI上存在しない（swagger.json実測確認）ため、税抜/税込を推測せず`extras['list_price']`に生値を退避し`sale_price`は`null`のままとしている。定価と実売価格が異なる商品（セール品）を持つ実店舗のデータで両フィールドの関係を実測してから、`CanonicalProduct.price`/`sale_price`への反映方法を確定する |

確定したら本表と該当計画ドキュメント（Capabilities値等）を更新すること。

**カラーミー顧客APIの補足（F1-0で判明、要検証事項外）**: `customers.json` レスポンスには法人名`hojin`・部署`busho`フィールドが存在し実データでも値が入る場合があるが、管理画面の標準「顧客登録」フォームにはこの2項目の入力欄がない（CSV一括登録等の別経路でのみ設定可能と推測）。F1-3のCustomerTransformer実装時に、hojin/bushoがnullでも異常とせず正しくマッピングすること。

## 10. 無料版制限・Pro版ライセンス設計（D14〜D17）

### 10.1 ビジネスモデル・ライセンス（D14）

無料版の役割は「自分のショップのデータで挙動確認ができること」に限定し、本番利用は Pro 版に誘導する。

- **無料版**（wordpress.org 配布）: dry-run は全エンティティ全量無料（変換結果・警告のプレビューが購入判断材料）。実インポート/エクスポートは §10.2 のサンプル上限内のみ
- **Pro版**（別プラグイン・自社サイト直販）: 「移行プロジェクトライセンス」として販売
  - サイト数無制限 / **初回アクティベーション時点から3ヶ月**のアップデート・メールサポート
  - 期限後: **認証済みサイトは永続動作**。新規サイトのライセンス認証とアップデート取得のみ不可（＝新規案件では買い直し）
  - 価格: ¥19,800 前後 / 返金保証なし（無料版で事前検証可能なことを販売ページに明記）
  - 販売基盤: 自社WooCommerceサイト + **WooCommerce API Manager**（アクティベーションAPI・アップデート配信）。
    有効期限の起点を「購入時」でなく「初回アクティベーション時」にする点はカスタマイズポイント
  - 適格請求書（インボイス）対応は販売サイト側で行う（本体プラグインのスコープ外）
  - 期限切れ後の再購入導線（リピート割引・プラグイン内販促通知）は**実装しない**
- **継続同期（定期差分同期・在庫双方向同期）は販売しない**。`cbjp/sync/*` フックの提供予定も廃止
- Pro版の解除機構: wordpress.org 規約上、無料版内に鍵付きコードを同梱できないため、
  Pro は別プラグインが `cbjp/limits/*` フィルターで上限を解除する構成とする（無料版に Pro 固有コードを含めない方針は維持）

### 10.2 無料版の実行上限とサンプリング（D15）

**エンティティ別上限**（インポート/エクスポート共通。dry-run は全量無料）:

| エンティティ | 無料版の実行上限 |
|---|---|
| カテゴリ / タグ | 全量（サンプル商品の検証に必須のため制限しない） |
| 受注 | 最新10件（サンプル） |
| 顧客 | サンプル受注の購入者（最大10件） |
| 商品 | サンプル受注に含まれる商品（**ハードキャップ50件**） |
| 在庫 | サンプル商品分のみ |
| レビュー | サンプル商品に紐づくもののみ |
| クーポン | 最新10件 |

**サンプル選定ロジック（SampleSelector）**:

1. 実行開始時に `fetchLatestOrders(10)` で最新受注10件を取得。
   カラーミーは一覧APIの日時範囲パラメータ省略時に検索対象が直近7日間へ暗黙的に絞られるため（§9 #14）、
   `fetchLatestOrders` 実装は7日間で10件に満たない場合、探索窓（`after`）を過去方向へ4倍ずつ広げて
   再取得を繰り返し、10件集まるか受注履歴の下限（2000-01-01）に達するまで走査すること
   （単発リクエストでは不足しうる。**F1-5実装確定**: `before`は常に省略し現在時刻を暗黙の上限に
   固定したまま`after`のみを過去へ広げる。swagger記述（`after`パラメータの説明文）を読むと
   `before`省略時のデフォルトは`after`の有無に関わらず常に現在時刻であるため、`before`を
   明示的にずらす必要はない。ただし要検証#14はテストショップでの実測確認までは行っていない
   ため、実データでの挙動確認はF1-8（実データE2E）で行うこと）
2. 明細から商品 remote_id、購入者（email / remote_id）を抽出し重複排除（ゲスト購入は顧客枠にカウントしない）
3. サンプルセットをオプション `cbjp_sample_{platform}`（autoload無効）に保存。再実行は同一セットの upsert
4. 商品・顧客は **ID指定取得**（`fetchProductByRemoteId` / `fetchCustomerByRemoteId`）で取り込む
   （全量カーソル走査してスキップする方式はレート制限を浪費するため不採用）。
   カテゴリ/タグ/クーポンは通常のカーソル走査。
   **在庫はサンプル商品のID指定取得の結果（CanonicalProduct.stock）から書き込み**、`fetchStocks` の全量走査は無料版では使わない
5. **フォールバック**: 受注0件のショップ・受注エンティティ未選択時は「各エンティティ10件」。
   受注が10件未満なら全受注 + 残枠を商品・顧客で補完。
   **「最新10件」の並び順定義**: 受注は新しい順（`fetchLatestOrders`）。商品・顧客・クーポンは
   APIが新しい順ソートを指定できる場合のみ新しい順、できない場合は**API標準順の先頭10件**とする
   （通常カーソルの先頭ページで代用。ソート指定可否は要検証#14で各ASP・各エンティティについて確定）。
   **F1-5実装済み**: `SampleSelector::top_up_with_first_page()` が `fetch_products`/`fetch_customers`
   の `Cursor::start()` 先頭ページのみ（複数ページの全量走査はしない）から不足分を補う。
   顧客側は `Capabilities::can_fetch_customers` が false のアダプタ（例: BASE。D12）では呼び出さず、
   いずれのエンティティも一覧取得自体が失敗した場合は補完をスキップして受注由来の分だけで確定する
   （Sync層にプラットフォーム固有知識を置かない原則。アーキテクチャ原則8）
6. エッジケース: 削除済み商品の明細は404→警告+カスタム行（D10）/ バリエーションは親商品で1件 /
   BASE は顧客のID指定取得なし→サンプル受注10件分の購入者から生成（D12）
7. サンプルのやり直しは「サンプルクリーンアップ（§10.3）→ 再選定」で行う。
   **クリーンアップせずに再選定は不可**（mappings累積カウントと商品ハードキャップの整合を守るため。UIでもこの順序を強制する）
8. **エクスポート側の選定**: Woo→ASPも同基準で、**Woo側の最新受注10件**（`wc_get_orders` の日付降順）を起点に
   紐づく商品・顧客をサンプルとする。フォールバック規則も同様（Woo側は日付ソートが常に可能）

**上限の強制**: UI ではなく `Sync\JobManager` がサーバーサイドで強制する。累積カウントは
`cbjp_mappings` の件数（platform + entity_type）を正とし、再実行で上限が加算されない。
上限値は `cbjp/limits/{entity}` フィルターで提供し、Pro プラグインが解除する
（実際のフック名は `cbjp/limits/product` のようにエンティティごと。本ドキュメント群で `cbjp/limits/*` とあるのはその総称）。

### 10.3 Pro本移行時の重複防止・ツール（D16）

- **本移行**（Pro解除後）: カーソル先頭から全走査。mappings 一致分は checksum 比較のうえ
  **開始時に選択した上書きポリシー**（既存を更新 / 既存はスキップ。デフォルト: 更新）に従い、未取込分のみ新規作成。
  dry-run で「新規◯件・更新◯件・スキップ◯件」を事前表示する
- **リンク再構築ツール**（`POST /tools/rebuild-mappings`）: 再インストール・DB移設等で mappings が失われた場合に、
  SKU（商品）/ email（顧客）/ `_cbjp_remote_order_number` メタ（受注）で既存Wooデータと突合して mappings を再構築
- **サンプルクリーンアップツール**（`POST /tools/sample-cleanup`）: 無料版サンプル由来のWooデータと対応 mappings を一括削除。
  対象は mappings の記録に基づき、実行前に削除件数を表示して確認を取る（本移行前のリセット・サンプル再選定に使用）
- **アップセル表示**: dry-run で総数が判明するため、上限到達時に
  「移行対象◯件のうち10件を無料版で移行済み。残り◯件は Pro 版で移行できます」と具体数で表示（`GET /limits`）

### 10.4 付帯機能（D17）

- **dry-runレポートのCSVダウンロード**: 変換結果・警告（未マッピング決済方法、SKU重複、バリエーション軸超過等）を全量出力（F1-6 PR-A実装済み）
- **移行後検証レポート**: エンティティ別件数と受注合計金額の ASP / Woo 突合を実行結果画面に表示
- **301リダイレクトCSV**（Pro機能・Pro側で実装）: 旧商品URL→新商品URLの対応表を mappings から生成
- **エクスポート実行前の本番書込み警告**: 無料版のサンプル10件でも ASP 本番環境に書き込むため、
  実行前に確認ダイアログでテストショップの利用を推奨する

#### dry-runレポートCSVの実装詳細（F1-6 PR-A）

- **生成経路**: `Woo\Writer\EntityWriter::validate()`（`write()`と参照解決・値検証ロジックを共有する新設メソッド）
  → `Woo\DryRunRepository`（`Sync\WooWriter`実装。`validate()`しか呼ばず何も永続化しない）
  → `Sync\Importer::process_items()`がページ単位で`Sync\DryRunItemRepository`へバッチ記録
  → `Admin\DryRunReportCsv`が`GET /runs/{run_id}/report`（`Admin\RestController::get_run_report()`）でCSVをストリーミング配信
- **保存**: 新テーブル`cbjp_dry_run_items`（`(job_id, entity, remote_id)`のUNIQUE KEY + `ON DUPLICATE KEY UPDATE`で再実行冪等）。NULL許容カラムを持たず、`label=''`/`existing_local_id=0`を「無し」の番兵値とする（生SQLがnullを空文字に変換する罠を回避）。保持期間は`cbjp/dry_run_items/retention_days`フィルター（既定30日）で`Sync\LogCleanup`の日次ジョブに相乗り
- **CSV列**: `entity, remote_id, label, operation, existing_local_id, warning_code, warning_detail, note`。1アイテム×1警告=1行に展開（`WarningCode::split()`で`:`区切りを最初の1つだけ分割）。`note`列は`WarningCode::indicates_unresolved_reference()`が真の警告に`reference_pending_import`を付与（初回dry-runではmappingsが空なため大量に出る「未インポートが原因の未解決」を、実際の不整合と区別するため）。UTF-8 BOM付き、OWASP CSVインジェクション対策（`=`/`+`/`-`/`@`/タブ/CR始まりのセルに`'`前置）
- **dry-runでは判定できない警告**（保存を実際に試みないと分からない、またはネットワークI/Oを伴うため`validate()`では意図的に実行しない）: `PRODUCT_SAVE_FAILED` / `ORDER_CREATE_FAILED` / `COUPON_SAVE_FAILED` / `TERM_CREATE_FAILED` / `TERM_UPDATE_FAILED`（更新パスのバリデーション失敗のみ。新規作成パスの名前衝突は`term_exists()`による事前チェックで`write()`と共有し判定可能） / `VARIATION_SAVE_FAILED` / `VARIATION_REMOVED` / `VARIATION_PRICE_INVALID` / `VARIATION_SNAPSHOT_INCOMPLETE`（`VariationWriter`は親ID確定後にしか走らないため） / `IMAGE_DOWNLOAD_FAILED`（dry-runは実際のダウンロードを行わない） / `CUSTOMER_CREATE_FAILED`（`CUSTOMER_EMAIL_CONFLICT`は`email_exists()`による読取専用の事前チェックで`write()`と共有し判定可能）
- **F1-6の残作業（PR-B）**: React Import タブ（エンティティ選択・dry-runプレビュー・CSVダウンロードリンク・進捗ポーリング・結果レポート・上限到達時のPro案内）と Logs タブのUI実装。バックエンド（本節の内容）はPR-Aで完結し、`GET /runs/{run_id}`（進捗）・`GET /runs/{run_id}/report`（CSV）・`GET /limits`（Pro案内用の残数）は実装済み
