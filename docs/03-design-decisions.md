# 設計補遺・確定事項

最終更新: 2026-07-07

`00-plan-overview.md` を具体化した実装設計。他の計画ドキュメント（00〜02・04）と本書が矛盾する場合は**本書を優先**する。
タスクの進行管理は `10-tasks.md` を参照。

## 1. 確定した方針（ユーザー確認済み・2026-07-06 / D11〜D13は2026-07-07）

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
| GET | `/connections/{platform}/authorize-url` | OAuth認可URL取得（OAuth型プラットフォーム: colorme / base） |
| GET | `/connect/{platform}/callback` | OAuthコールバック（ASP側に登録する公開URL。**permission例外**: `__return_true` + state検証必須。詳細は下記） |
| POST | `/runs` | 移行実行の開始 `{type, platform, entities[]}` |
| GET | `/runs/{run_id}` | 進捗（per-entityジョブのstatus/totals。UIが2秒間隔でポーリング） |
| POST | `/runs/{run_id}/cancel` | キャンセル |
| POST | `/jobs/{id}/retry` | 失敗ジョブの再実行 |
| GET | `/logs?job_id=&level=&page=` | ログ閲覧 |
| GET/PUT | `/settings/mappings/{platform}` | 決済/配送/注文ステータスのマッピング設定 |

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
- ルーティングは単一管理ページ内のタブ切替（Connections / Import / Export / Logs）。URLは `#/import` 形式
- ページ登録: WooCommerce メニュー配下 `admin.php?page=cart-bridge-jp`
- UI文字列は英語 + `@wordpress/i18n`（`wp_set_script_translations`）

## 7. プラグインヘッダー・互換宣言（確定）

```php
/**
 * Plugin Name: Cart Bridge JP – Migrate & Sync for WooCommerce
 * Description: Migrate and sync products, customers, and orders between Japanese e-commerce platforms (Color Me Shop, MakeShop, BASE) and WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Artisan Workshop
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cart-bridge-jp
 * Domain Path: /languages
 *
 * WC requires at least: 8.0
 */
```

- HPOS: `before_woocommerce_init` で `FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true )`
- WooCommerce 未有効時は管理画面通知を出して機能を無効化（fatalにしない）
- アンインストール: `uninstall.php`。オプション `cbjp_delete_data_on_uninstall`（デフォルトfalse）が true の場合のみテーブル・オプション削除

## 8. CI（GitHub Actions）

`.github/workflows/ci.yml` — push / PR（main宛）で実行:

1. **php-quality**: PHP 8.1/8.2/8.3 マトリクスで `composer lint`（PHPCS）+ `composer analyze`（PHPStan level 6）
2. **php-test**: `wp-env` を起動して `composer test`（PHPUnit）
3. **js**: `npm ci && npm run lint && npm run build`（tsc型チェック含む）

## 9. 要検証事項トラッカー（00 §7 の具体化）

| # | 項目 | 確定タイミング | 状態 |
|---|---|---|---|
| 1 | カラーミー: 商品POST/PUTの画像登録可否 | Phase 1 タスク F1-0（swagger精査+実測） | 未 |
| 2 | MakeShop: レート制限値 | Phase 2 タスク M2-0（FAQ/問い合わせ） | 未 |
| 3 | MakeShop: 自社利用登録の条件（プラン・費用） | 取得済みのため契約内容をREADME用に記録（M2-0） | 未 |
| 4 | MakeShop: createProduct の画像入力形式 | Phase 2 タスク M2-0 | 未 |
| 5 | カラーミー: 受注POSTの必須項目・決済/配送ID | Phase 4 タスク E4-3（テストショップ実測） | 未 |
| 6 | 大規模ショップのジョブ実行時間 | Phase 1 E2E（F1-7）で計測 | 未 |
| 7 | カラーミー: リダイレクトURIのhttps要否（ローカル開発時のOAuth可否） | Phase 1 タスク F1-2 | 未 |
| 8 | MakeShop: searchProduct等のページング方式（cursor/offset・最大件数） | Phase 2 タスク M2-0 | 未 |
| 9 | BASE: リダイレクトURIのhttps要否・localhost可否 | Phase 3 タスク B3-0 | 未 |
| 10 | BASE: 明細単位発送ステータスの注文全体への集約規則（dispatch_statusの実値一覧含む） | Phase 3 タスク B3-0 | 未 |
| 11 | BASE: エラーレスポンス形式・レート制限超過時の挙動（Retry-Afterヘッダー有無） | Phase 3 タスク B3-0 | 未 |
| 12 | BASE: API利用費用・スコープ承認フロー（README前提条件用） | Phase 3 タスク B3-0（公式FAQ確認） | 未 |
| 13 | BASE: add_image のURL取得要件（Basic認証下・ローカルURLの挙動）と canPushImages 最終確定 | Phase 4 タスク E4-5 | 未 |

確定したら本表と該当計画ドキュメント（Capabilities値等）を更新すること。
