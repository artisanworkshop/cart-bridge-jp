# 設計補遺・確定事項

最終更新: 2026-09-13

`00-plan-overview.md` を具体化した実装設計。他の計画ドキュメント（00〜02・04）と本書が矛盾する場合は**本書を優先**する。
タスクの進行管理は `10-tasks.md` を参照。

## 1. 確定した方針（ユーザー確認済み・2026-07-06 / D11〜D13は2026-07-07 / D14〜D17は2026-07-08 / D18は2026-09-05 / D19は2026-09-13）

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
| D11 | BASE対応の追加 | 対応プラットフォームに **BASE を追加**し、インポートと、APIが許す範囲（商品・カテゴリ・在庫のみ）のエクスポートを実装する。詳細は `04-plan-base.md`。~~Phase 3（MakeShopの次）でインポート、Phase 4でエクスポート、v1.0公開はBASE込み（0基盤→1カラーミー→2MakeShop→3BASE→4エクスポート→5公開）~~ → **提供時期は D18 で改訂**: v1.0 には含めず **v2.0（Phase 4〜5）**で提供 |
| D12 | BASEの顧客移行方式 | BASEには顧客一覧APIが無いため、**受注インポート時に購入者情報からemail名寄せで顧客を生成**（オプション、デフォルトON。初回作成のみで上書きしない）。単独の顧客エンティティとしてはUIに出さない（`canFetchCustomers: false`） |
| D13 | 有効期限付きトークン対応 | BASEのアクセストークン1時間+リフレッシュトークン30日ローテーションに対応するため、**TokenStoreはPhase 0から構造化ペイロード（access/refresh/expires_at）+リフレッシュ排他ロックを前提に設計**する（§4参照。カラーミー/MakeShopは単一トークンとして同構造に格納） |
| D14 | ビジネスモデル | 無料版=挙動確認用（**dry-runは全量無料**+実移行はサンプルのみ）。Pro版=買切り**「移行プロジェクトライセンス」**: サイト数無制限・**初回アクティベーションから3ヶ月**のアップデート&サポート・認証済みサイトは期限後も永続動作（新規サイト認証と更新のみ不可）・価格 ¥19,800 前後・自社サイト直販（**WooCommerce API Manager**）・返金保証なし（無料版で事前検証可能なことを明記）。**継続同期（Pro同期）は販売しない**。詳細は §10.1 |
| D15 | 無料版の実行上限 | **最新受注10件起点のサンプル移行**: サンプル受注に紐づく商品（ハードキャップ50件）・顧客（最大10件）・受注10件のみ実インポート/エクスポート可。カテゴリ/タグは全量無料。上限はサーバーサイド（JobManager）で強制し、`cbjp/limits/{entity}` フィルター（総称表記: `cbjp/limits/*`）でPro版が解除。詳細は §10.2 |
| D16 | Pro本移行時の重複防止 | mappings による冪等 upsert + 本移行はカーソル先頭から全走査。取込済みデータの扱いは**開始時に選択式（更新/スキップ、デフォルト更新）**。mappings欠損時の**リンク再構築ツール**（SKU/email/注文番号突合）と**サンプルクリーンアップツール**を提供。詳細は §10.3 |
| D17 | 付帯機能 | dry-runレポートCSVダウンロード / 移行後検証レポート（件数・金額突合）/ 301リダイレクトCSV（Pro）/ エクスポート実行前の本番書込み警告 を実装する。期限切れ後の再購入導線（リピート割引等）は**実装しない**。詳細は §10.4 |
| D18 | リリース計画の改訂（1ASPずつ公開） | **v1.0はカラーミーショップのみ**（インポート＋エクスポート）で公開し、**v2.0でBASE**、**v3.0でMakeShop**を追加する（各バージョンでインポート＋エクスポートを揃える）。D11のフェーズ構成と「v1.0公開はBASE込み」は本決定で置き換え、MakeShop/BASEの順序も入れ替える（新フェーズ構成: 0基盤→1カラーミーインポート→2カラーミーエクスポート→3 v1.0公開→4 BASEインポート→5 BASEエクスポート+v2.0公開→6 MakeShopインポート→7 MakeShopエクスポート+v3.0公開）。3ASP対応を前提に設計・実装済みのアーキテクチャ（PlatformAdapter・Canonical・Capabilities・TokenStoreのリフレッシュ構造=D13・HttpClientのレート制限判定フック・`canFetchCustomers` 等）は**そのまま維持し削除しない**。v2.0以降は、プラットフォーム固有のコードをアダプタ外に書かない（アーキテクチャ原則1）ことを維持しつつ、プラットフォーム非依存のコア拡張点（例: 受注インポート時に抽出した顧客をImporterが永続化するフック=B4-5、レート制限超過時の再試行遅延をアダプタ側から指定できるJobManagerの拡張点=E5-1）の追加は許容し、Importer/Exporter本体にプラットフォーム固有の分岐を持ち込まないことを検証観点とする（旧計画でMakeShopが担っていた観点はBASEへ）。v1.0 完了前に Phase 4 以降へ着手しない。フェーズ再編・タスクID採番は `10-tasks.md` 冒頭を参照 |
| D19 | マッピング候補一覧の取得方式（E2-1） | `PlatformAdapter`（§2「確定版」）に `mappingCandidates(): array` を追加する。`/settings/mappings/{platform}` のマッピングUI（カテゴリ/決済/配送/注文ステータス）が選択肢を動的に描画するための自己記述スキーマで、既存の `connectionFields()` と同じ設計思想。外部アドオンによるカスタムアダプタ実装は現時点で存在しないため、確定版インターフェースへの追加による後方互換リスクは低いと判断した（該当メソッドが無いカスタムアダプタは致命的エラーになるため、将来外部アダプタが増えた場合はこの追加を周知する）。あわせて `cbjp_settings_{platform}` に `category_map`（キー: Woo側カテゴリID、値: ASP側カテゴリID）を追加。カラーミーがカテゴリ作成不可なため、既存の `payment_map`/`shipping_map`/`status_map`（ASP側ID→Woo側ID）とは向きが逆になる。**E2-2/E2-3への申し送り**: `payment_map`/`shipping_map`はASP→Wooの単射とは限らない（複数のASP決済/配送方法が同じWooゲートウェイ/配送方法へ寄せられうる）ため、エクスポート時にWoo側の値からASP側の値へ機械的に逆引きすることはできない。E2-3の`push_order`実装時にこの逆引きの曖昧性をどう解決するか（例: 最初に一致した1件を使う、複数一致時は警告付きでフェイルクローズする等）を設計すること |

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

    // `/settings/mappings/{platform}` UI向けのASP側マッピング候補一覧（D19。connectionFields()と
    // 同じ「自己記述スキーマをUIが消費する」設計）。キーは category/payment/shipping/status。
    // 該当エンティティ・機能を持たないプラットフォームはキー省略・空配列可。
    public function mappingCandidates(): array;        // array<string, array<{id,name}>>

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
- `CanonicalCoupon`（code, type('fixed'|'percent'), amount, minAmount, expiresAt, usageLimit, extras,
  freeShipping, usageLimitPerUser, hasUnsupportedRestrictions）
  - `hasUnsupportedRestrictions`（`?bool`）はASP側の利用制限のうち、現在の変換経路ではWooのクーポン設定へ
    **写せない**ものが残っているかの正規化フィールド。Woo自身は商品・カテゴリ・メールアドレスの制限軸を
    ネイティブに持つ（`WC_Coupon::set_product_ids()` 等）ため「ASPに制限がある」と「写せない」は同義ではない。
    ただし `CanonicalCoupon` にそれらを運ぶフィールドが無く `CouponWriter` も該当setterを呼ばないため、
    **v1.0 時点で `false` にしてよいのは「ASP側に制限が無い」場合のみ**（変換経路を実装した分だけ範囲を広げる）。
    ASP固有のキー名・enum値と写せるかの判定はアダプタしか持たないため判定は各Transformerが行い、
    `Woo\Writer\CouponWriter` はこのフィールドだけを見て保存を見送る（アーキテクチャ原則1）。
    `null`＝アダプタが宣言していない（不明）も保存しない側に倒す
    （原則9。楽観的デフォルトによるフェイルクローズ回避を防ぐ）。issue #15
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
| GET | `/runs/{run_id}/verification` | 移行後検証レポート（件数・受注合計の ASP/Woo 突合。`type=import` の run のみ。D17/§10.4） |
| POST | `/jobs/{id}/retry` | 失敗ジョブの再実行 |
| GET | `/logs?job_id=&level=&page=` | ログ閲覧 |
| GET/PUT | `/settings/mappings/{platform}` | カテゴリ/決済/配送/注文ステータスのマッピング設定（`category_map`/`payment_map`/`shipping_map`/`status_map`）。GETは選択肢UI用の `asp_candidates`/`woo_candidates`（D19）も同梱する |
| GET | `/limits?platform={platform}` | 無料版上限・Pro解除状態（アップセル表示用。D15/§10.2）。`platform` 指定時は使用状況（mappings累積カウント）・残数も返す |
| GET | `/tools/sample-cleanup?platform=` | サンプルクリーンアップの削除件数プレビュー（D16/§10.3） |
| POST | `/tools/sample-cleanup` | 無料版サンプルデータの一括削除（mappings記録に基づく。1バッチ分を処理し `has_more` を返す。D16/§10.3） |
| POST | `/tools/rebuild-mappings` | 所有メタ（`_cbjp_platform` + remote_id）の走査による mappings 再構築（1バッチ分を処理し `cursor` を返す。D16/§10.3） |

nonce（`X-WP-Nonce`）は管理画面Reactアプリからの呼び出しにのみ適用。`/connect/{platform}/callback` は
ASPからの外部リダイレクトで叩かれるためnonce・capabilityを課さず、代わりに `state` ワンタイムトークンで検証する。

### OAuth コールバック（カラーミー・BASE共通）

- コールバックURL: `{site_url}/wp-json/cbjp/v1/connect/{platform}/callback`（ユーザーが各ASPのアプリ登録画面に登録する。設定画面にコピー用で表示）
- `state` = ワンタイムトークン（transient、10分、管理ユーザーIDに紐付け）で CSRF 対策。`permission_callback` は `__return_true` とし、state 検証を必須にする
- code→トークン交換後、管理画面（接続タブ）へリダイレクト
- **フォールバック**: ASP側がhttpsリダイレクトURIを要求する場合に備え、「認可後のURLからcodeを手動貼り付け」する入力欄も用意（カラーミーはhttp/localhostでも自動リダイレクト可と実機確認済み=要検証#7。BASEは要検証#9で確認。BASEの認可コード有効期限は約1時間なので手動貼付でも運用可能）
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

- **Description の v1.0 化（D18）**: 上記ヘッダーと `composer.json` の Description は3ASPを併記しているが、v1.0 公開時（R3-3）に「Color Me Shop」のみへ改め、BASE（v2.0 / R5-1）・MakeShop（v3.0 / R7-1）はそれぞれの公開時に追記する
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
| 1 | カラーミー: 商品POST/PUTの画像登録可否 | Phase 1 タスク F1-0（swagger精査+実測） | **済（プラン依存）**: `products.json`のcreate/update本体に画像フィールドはないが、専用エンドポイント`POST /v1/products/{product_id}/images`（マルチパート、`image`+`position`）が別途存在する。**ただしプレミアムプラン契約ショップのみ利用可**（レギュラープラン等は403想定・要実機確認）。`canPushImages`は固定falseではなく、`GET /shop.json`の`contract_plan`を見てプラン依存で判定する設計に変更（F1-5で実装、E2-3で画像push実装時に403時のCSVフォールバックへの切替を含める） |
| 2 | MakeShop: レート制限値 | v3.0 Phase 6 タスク M6-0（FAQ/問い合わせ） | 未 |
| 3 | MakeShop: 自社利用登録の条件（プラン・費用） | 取得済みのため契約内容をREADME用に記録（v3.0 M6-0） | 未 |
| 4 | MakeShop: createProduct の画像入力形式 | v3.0 Phase 6 タスク M6-0 | 未 |
| 5 | カラーミー: 受注POSTの必須項目・決済/配送ID | v1.0 Phase 2 タスク E2-3（テストショップ実測） | **済（swagger精査 2026-09-15）**: `POST /v1/sales`はプレミアムプラン契約のショップのみ利用可。必須は`sale.details`（各行`product_id`+`product_num`）と`sale.payment_id`のみ。`sale.customer`は丸ごと省略可（既存顧客IDを渡す場合、他の属性は無視される）。`sale.sale_deliveries`は配送不要商品を含む場合を除き必須（各行必須: `delivery_id`/`name`/`furigana`/`postal`/`pref_id`/`address1`/`tel`）。`sale.details[].price`は省略可（省略するとColorMeの現在のカタログ価格が適用される）。`PUT /sales/{id}`は入金状態・配送情報の一部しか更新できない（明細・決済/配送方法の変更は不可）。実装詳細は§10.2「E2-3 PR-C」。実店舗での`add_member`通知メール確認（要検証#17）とあわせて、実際のColorMeショップに対する動作確認はE2-4またはF1-8相当の実機E2Eで行う |
| 6 | 大規模ショップのジョブ実行時間 | Phase 1 E2E（F1-8）で計測 | 未 |
| 7 | カラーミー: リダイレクトURIのhttps要否（ローカル開発時のOAuth可否） | Phase 1 タスク F1-2 | **済（実機確認 2026-09-03）**: デベロッパーコンソールに `http://localhost:8888/wp-json/cbjp/v1/connect/colorme/callback`（wp-env 既定ポート。実際に登録するURLは `GET /connections` が返す `callback_url` を使うこと。実測環境では wp-env が 8898 にバインドされていたが結果は同じ）を登録でき、自動リダイレクト方式で接続完了した。**httpsは必須ではない**（ローカル開発でも自動リダイレクトが使える）。OOB手動貼付フォールバックは引き続き保持する（BASE=要検証#9は別途） |
| 8 | MakeShop: searchProduct等のページング方式（cursor/offset・最大件数） | v3.0 Phase 6 タスク M6-0 | 未 |
| 9 | BASE: リダイレクトURIのhttps要否・localhost可否 | v2.0 Phase 4 タスク B4-0 | 未 |
| 10 | BASE: 明細単位発送ステータスの注文全体への集約規則（dispatch_statusの実値一覧含む） | v2.0 Phase 4 タスク B4-0 | 未 |
| 11 | BASE: エラーレスポンス形式・レート制限超過時の挙動（Retry-Afterヘッダー有無） | v2.0 Phase 4 タスク B4-0 | 未 |
| 12 | BASE: API利用費用・スコープ承認フロー（README前提条件用） | v2.0 Phase 4 タスク B4-0（公式FAQ確認） | 未 |
| 13 | BASE: add_image のURL取得要件（Basic認証下・ローカルURLの挙動）と canPushImages 最終確定 | v2.0 Phase 5 タスク E5-1 | 未 |
| 14 | 各ASP: 一覧APIの新しい順ソート指定可否（受注は必須、商品・顧客・クーポンはフォールバック用。サンプル選定=D15） | F1-0 / B4-0 / M6-0 | **カラーミー済（実機確認済み 2026-09-03）**: `GET /sales.json`はソートパラメータなしでデフォルト`make_date`降順（新しい順）で返るが、**`after`/`before`省略時の検索対象は直近7日間に限定される**（`after`未指定時は`before`の7日前0時がデフォルト。swagger実測確認）。ショップの直近7日間の受注が10件未満の場合、`fetchLatestOrders(10)`は探索窓（`after`）を過去方向へ4倍ずつ広げて複数回リクエストし、10件集まるか受注履歴の下限（2000-01-01）に達するまで走査する（**F1-5実装済み**: `before`は常に省略し暗黙の現在時刻に固定したまま`after`のみを広げる方式。`fetch_orders`によるカーソル全量走査も`after=2000-01-01`を明示することで直近7日制限を回避する）。**テストショップ実測**: 直近7日の受注0件・全履歴2件の店舗で、`after`省略→28日→112日→448日→1792日→7168日→2000-01-01 の7回の`sales.json`呼び出し（＋`payments.json`/`deliveries.json`各1回）で下限に到達し2件を取得、所要1.7秒。直近7日に10件以上ある店舗では1回で確定する。MakeShop/BASEは未 |
| 15 | 各ASP: 商品・顧客のID指定取得エンドポイントの有無（サンプル取得=D15） | F1-0 / B4-0 / M6-0 | **カラーミー済**: `GET /products.json` `/customers.json` `/sales.json` すべて `ids` クエリパラメータで複数ID指定取得可能。個別詳細 `/products/{id}.json` 等も利用可（swagger + 実測確認）。MakeShop/BASEは未 |
| 16 | カラーミー: 商品の定価（`price`）が税抜/税込どちらか（`CanonicalProduct.sale_price` への反映可否） | F1-3で判明。実店舗での実測時（Phase 1 E2E等） | **済（実機確認 2026-09-03）**: テストショップ（`shop.tax_type=excluded`, `tax=10`）で定価8,000円・販売価格6,000円の商品を登録した結果、APIは`price=8000`, `sales_price=6000`, `sales_price_including_tax=6600`を返し、店頭は定価「¥8,800」・販売価格「¥6,600」を表示した。つまり**`price`（定価）は`sales_price`と同じ税基準の値**（`tax_type=excluded`なら税抜、`included`なら税込）で、税込版フィールドは無い。Woo反映は「`regular_price`=定価の税込換算値、`sale_price`=`sales_price_including_tax`（定価未設定または定価≦販売価格なら`regular_price`=`sales_price_including_tax`、`sale_price`=null）」とし、税込換算は`shop.tax_type`/`tax`/`reduce_tax_rate`/`tax_rounding_method`と商品`tax_reduced`から行う。**実装済み**: `ProductTransformer`が店舗税設定をコンストラクタで受け取り（`ColorMeAdapter::product_transformer()`が`GET /shop.json`から注入）、既知の許可値（`tax_type`が`excluded`/`included`、丸め方式が`round_off`/`round_down`/`round_up`）のみ肯定形で判定する。未知値・欠損・税設定未取得の場合は換算せず現行の`regular_price = sales_price_including_tax` / `sale_price = null`にフェイルクローズする。`tax_type=included`の店舗は未実測（計算上は換算不要） |
| 17 | カラーミー: `POST /v1/customers`の`add_member: true`が会員登録時に通知メール（パスワード設定案内等）を自動送信するか | E2-3 PR-Bで判明。実店舗での実測時（要検証#5と合わせて） | 未。swaggerに記載無し。`push_customer()`は往復インポート整合性のため新規作成時に常時`add_member: true`を送るが、本プロジェクトは移行時の副作用（通知メール等）抑止を重視する方針（`docs/01-plan-colorme.md`「通知メールは送らない」）。`POST /sales/{id}/mails.json`が受注確認メールを独立エンドポイントに切り出している設計から自動送信の可能性は低いと推測するが未確認。実店舗確認まで、E2-3の実機確認（要検証#5）と合わせて要検証のまま残す |

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
   明示的にずらす必要はない。要検証#14はF1-5実機確認（2026-09-03）で実測済み: 直近7日の受注が
   0件の店舗で7回の`sales.json`呼び出し・1.7秒で下限に到達し全受注を取得した）
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

**import/export共有**: `cbjp_mappings` は方向を持たない設計（`UNIQUE(platform, entity_type,
remote_id)`）のため、上記累積カウントは import 由来・export 由来の行を区別せず合算する
（E2-2で確定。無料版=挙動確認という位置づけ（D14）から、往復を通じた合計上限として扱う）。

**checksum列も同じ行をimport/exportで共有するが値の意味は別物**（E2-2 R1で判明。詳細は
`Sync\Exporter`クラスdocblock）: `Importer`が書くchecksumはASP側`CanonicalModel`のハッシュ、
`Exporter`が書くのはWoo側`CanonicalModel`のハッシュで、同じ実体でも一致しない。素朴に同じ値として
比較すると、一度でも両方向が触った実体（例: importで作られた商品がWooで購入されexportのサンプルにも
選ばれた場合）で、以後のimportが「ASP側は変わっていないのにchecksum不一致」と誤判定し
`ProductWriter::write()`がWoo側の手動編集を無条件に上書きし続けてしまう。`cbjp_mappings.checksum`は
`CHAR(64)`固定長（生のsha256 hex digest専用）のためImporterの生ハッシュへ文字列プレフィックスを
付ける方式は使えず、`Exporter::export_checksum()`が`canonical_json()`をハッシュする**前**に
固定の名前空間文字列を混ぜ込むことで、出力を64文字のsha256 hex digestに保ったまま名前空間を分離する
（`Importer`側は無変更。SHA-256の衝突耐性に依拠し、双方とも「自分が最後に書いた値と一致するか」
だけを見るため相手方向の生ハッシュとは構造的に一致せず安全側＝再同期に倒れる）。

**この修正が解決する範囲（E2-2 R2で明確化）**: 解決するのは「本来は変更があるのに誤って
`一致`と判定してしまう」偽陽性のみである。両方向が同じ行を触った直後は、生ハッシュと名前空間付き
ハッシュが構造的に一致しないため**必ず**「変更あり」と判定され、次にそのentityを触った方向が
再同期（Woo→ASPは再push、ASP→Wooは`ProductWriter::write()`等の再書込）を行う。これは本修正が
新たに生んだ挙動ではなく、修正前から（ASP側JSONとWoo側JSONは`extras`・画像URL等が構造的に
異なるため通常は）実質的に同じ結果だった（=修正前後で「再同期コストがある」という性質自体は
不変で、本修正は「本来なら再同期すべきなのに誤ってスキップする」危険な方を無くしたもの）。
「両方向を跨いで触られた行は次の同期で必ず1回だけ余分な書込が起きる」こと自体を無くすには
`cbjp_mappings`に方向別のchecksum列を追加する（スキーマ変更）等の設計が必要で、無料版のサンプル
規模（最大50件）では実害が小さいと判断し本PRのスコープ外とした。

#### エクスポート方向の実装（E2-2 PR-A）

- **アーキテクチャ**: `Sync\Importer`/`Sync\WooWriter` の対称形として `Sync\Exporter`/
  `Sync\PlatformWriter`/`Sync\WooReader` を新設。Woo→Canonical読出は `Woo\Reader\EntityReader`
  実装（PR-A: `Woo\Reader\ProductReader` のみ。`Woo\WooReaderRepository` が entity ごとに
  ディスパッチ）、ASPへのpushは `Woo\Export\AdapterPlatformWriter`
  （`PlatformAdapter::push_*()` へディスパッチ）/ `Woo\Export\DryRunPlatformWriter`
  （dry-run。アダプタを一切呼ばずmappingsの有無だけでcreated/updatedを判定）が担う。
  `JobManager::process_job()` は `type` が `export`/`dry_run_export` のとき
  この経路へ分岐する（`import`/`dry_run`は既存の`Importer`経路のまま）。
- **「SKU/email突合」の実装範囲**: `cbjp_mappings`（Wooローカルエンティティからの逆引き。
  `MappingRepository::find_remote_id()`/`find_many_by_local_ids()`）の有無のみでcreate/update
  を判定する。ASP側APIへの投機的なSKU/email検索は行わない（D16が`MappingRebuilder`で
  「SKU/email突合は誤リンクの危険があるため不採用」と確定済みの方針と整合）。
- **無料版サンプル選定（エクスポート方向。D15 §10.2 #8）**: `Sync\ExportSampleSelector`
  （`SampleSelector`のWoo向け対称形）が `wc_get_orders()`（日付降順・`wc-checkout-draft`除外）
  で最新10件を起点に、明細の商品（`WC_Order_Item_Product::get_product_id()`で常に親商品IDを
  取得するため、バリエーションは自動的に親商品で1件になる）と購入者（ゲスト=`customer_id 0`は
  除外）を抽出する。フォールバック（受注10件未満）は商品・顧客一覧の先頭ページ（Woo側は日付
  ソートが常に可能なため無条件に新しい順）で補う。永続化キーは `cbjp_export_sample_{platform}`
  （import用 `cbjp_sample_{platform}` とは別オプション）。
- **`cbjp_dry_run_items` はスキーマ変更なし**: export方向のdry-run行は `existing_local_id` 列に
  Wooローカルエンティティの実ID（常に既知）を格納し、`remote_id` 列（`NOT NULL`・
  `UNIQUE(job_id, entity, remote_id)`）は更新時は既存remote_id、新規作成候補時は
  一意性確保のためのプレースホルダ `local:{local_id}` を格納する（`Sync\Exporter::dry_run_row()`）。
- **カテゴリ**: Wooのカテゴリ/タグ自体は独立したexportエンティティにしない
  （`PlatformAdapter`に`push_tag()`は存在せず、`push_category()`はcategory作成可能な
  プラットフォーム向け。ColorMeは`can_create_category=false`）。`Woo\Reader\ProductReader`が
  D19の`category_map`（Woo側カテゴリID→ASP側カテゴリID）を商品ごとに解決し、未マッピングの
  カテゴリは警告（`WarningCode::CATEGORY_MAP_UNRESOLVED`）付きで除外する。Wooのタグはv1.0では
  転送しない（`tag_map`が存在せず、ColorMeは groups をカテゴリとしてのみ扱うため）。
- **エンティティの絞り込み**: `JobManager::EXPORT_ENTITIES_WITH_READER`（PR-A時点は`product`
  のみ）で、Readerが未実装のentityは要求されても対象にしない。customer/order/stock/coupon用の
  Readerを追加するPR-Bで、`can_create_category`/`has_coupons && can_create_coupon`/
  `can_update_customer`/`can_create_order`によるcapabilityベースの絞り込みも合わせて追加する
  （PR-A時点でこれを含めるとPHPStanが到達不能コードとして検出するため見送った）。
- **本番書込み警告（D17）のサーバー側担保**: `POST /runs`（`type=export`）は
  `acknowledge_production_write`（`true`/`'1'`/`'true'`のみ受理。フェイルクローズ）が
  真であることを要求し、無ければ400。dry-run（`dry_run_export`）には適用しない。
- **受注（D19の申し送り。PR-Bで実装）**: `payment_map`/`shipping_map`の逆引きの曖昧性解決は
  スコープ外のまま据え置き、`Woo\Reader\OrderReader`はWoo側の生コード（決済ゲートウェイID・
  配送方法ID）のままCanonicalOrderへ載せ、ASP側コードへの解決はアダプタの`push_order()`実装
  （E2-3）に委ねる。詳細は下記「エクスポート方向の実装（E2-2 PR-B）」参照。

#### エクスポート方向の実装（E2-2 PR-B）

PR-A（product）に続き、`Woo\Reader\CustomerReader`/`OrderReader`/`StockReader`/`CouponReader`
（`JobManager::EXPORT_ENTITIES_WITH_READER`に追加）を実装した。

- **住所はWooネイティブのキーのまま運ぶ**: `Woo\Support\AddressMapper::to_woo()`はColorMeの
  `pref_id`スキームを解釈するインポート方向専用の変換（`PREF_ID_SCHEME_PLATFORMS`で明示的に
  限定）。`CustomerReader`/`OrderReader`は`WC_Order::get_address()`/`billing_*` usermetaが返す
  Wooネイティブのキー（`address_1`/`address_2`/`city`/`state`/`postcode`/`country`等）をそのまま
  `CanonicalCustomer::$address`/`CanonicalOrder::$shipping`へ格納し、ASP固有スキームへの変換は
  E2-3の`push_customer()`/`push_order()`（ColorMeアダプタ）の責務にする（決済/配送方法IDと
  同じD19の原則。アーキテクチャ原則1）。
- **受注明細の`remote_product_id`と`customer_ref`は`cbjp_mappings`で解決する**:
  payment_map/shipping_mapは「ASPだけが知っているコード変換」に限定された申し送りであり、
  商品・顧客参照の解決はプラットフォーム非依存のインフラ（`ProductReader::variants()`が
  既にvariantのremote_id解決に使っている仕組みと同じ）。未解決の場合はその明細/顧客参照だけを
  `null`にして注文自体は構築を続け（`ORDER_LINE_PRODUCT_NOT_EXPORTED`/`ORDER_CUSTOMER_NOT_EXPORTED`。
  `WarningCode::indicates_unresolved_reference()`対象＝商品/顧客が後からエクスポートされれば
  自動的に再試行される）、importの`ORDER_LINE_PRODUCT_UNRESOLVED`（カスタム行として注文自体は
  保存する設計）と同じ方針を踏襲する。
- **StockReaderは商品を販売単位へ展開する**: simple商品は1件、variable商品は
  `get_children()`＋`publish`ステータスの明示チェック（`ProductReader::variants()`と同じ方針。
  **`get_visible_children()`は使わない**——在庫切れバリエーションも
  `woocommerce_hide_out_of_stock_items`設定次第で除外してしまい、在庫がゼロになった瞬間に
  その行がASP側へ在庫切れを伝える手段ごと消えてしまうため）で公開バリエーションのみを
  1件ずつ`CanonicalStock`に展開する。`product_ref`/`variant_ref`が`cbjp_mappings`で未解決の場合、
  `CanonicalStock::$product_ref`が非nullable stringのため有効な値を作れず、新規コード
  `STOCK_PRODUCT_NOT_EXPORTED`を`WarningCode::indicates_export_blocking()`に加えてpush自体を
  止める（checksumはキャッシュされないため商品エクスポート後に自動再試行される）。非公開
  バリエーションはこの行自体を生成しない（`CanonicalStock::remote_id()`がvariant_ref欠落時に
  product_refへフォールバックするため、警告付きで行を出すと親商品の在庫を誤って更新しかねない。
  'product'エンティティ側の`VARIATION_UNPUBLISHED`警告で情報は失われない）。
- **CouponReaderは`fixed_product`型と一部のWooネイティブ制限を`has_unsupported_restrictions`へ
  倒す**: `CanonicalCoupon::$type`は`'fixed'|'percent'`の2値のみで、Wooの`discount_type`の
  3値目`fixed_product`（商品単位の定額値引き）を表現できない。`fixed_cart`（カート単位）へ
  無警告で丸めると割引の効き方が変わる金銭的リスクがあるため、他の「Wooにはあるが運べない
  制限」（商品/カテゴリ/メールアドレス制限・`maximum_amount`）と同じ`has_unsupported_restrictions
  =true`の扱いにする（`CanonicalCoupon`のdocblockが定める三値契約。値は必ず`true`/`false`を
  明示し`null`にはしない）。既存の`WarningCode::COUPON_RESTRICTIONS_UNSUPPORTED`（import方向の
  `CouponWriter`と同じ意味）を読出時点でも積み、`indicates_export_blocking()`に追加して
  E2-3で`push_coupon()`が実装される前から安全側に倒す。
- **サンプリング判定のバグ修正**: `Sync\JobManager::process_export_page()`は元々
  `$this->limits->limit_for($entity)`（エンティティ自身の上限）でサンプリング要否を判定していたが、
  `LimitPolicy::DEFAULT_LIMITS['stock']`は`null`（数値上限なし＝サンプル商品分のみという
  メンバーシップ制限）のため、この基準では`stock`が無料版でも常に全量対象になってしまっていた
  （D15 §10.2の表「在庫: サンプル商品分のみ」に反する）。importの`process_page()`が`stock`を
  `'product'`の上限基準で判定しているのと対称に、`product`/`customer`/`order`/`stock`の
  4エンティティ（`EXPORT_SAMPLE_ID_ENTITIES`）は`product`の上限を基準に`ExportSampleSelector`の
  サンプルID（`order_ids`/`product_ids`/`customer_ids`。`stock`は`product_ids`を受け取り内部で
  バリエーションへ展開）で絞り込むよう修正した。
- **couponは受注サンプルに紐づかない独立上限**: D15 §10.2の表で「クーポン: 最新10件」は
  受注サンプル起点ではなく独立した上限（`LimitPolicy::DEFAULT_LIMITS['coupon']`=10）。importの
  `coupon`処理と同じく、`only_local_ids`は使わず通常のカーソル走査＋`LimitPolicy`だけで
  上限を効かせる（`EXPORT_SAMPLE_ID_ENTITIES`に含めない）。
- **capabilityゲート**: `filter_and_order_export_entities()`に`$adapter`引数を復活させ、
  `customer`→`can_update_customer`、`order`→`can_create_order`、`coupon`→
  `has_coupons && can_create_coupon`のゲートを追加した（`product`/`stock`は対応する
  capabilityフラグが存在しないため常に対象）。`category`は引き続き`EXPORT_ENTITIES_WITH_READER`
  に含めない（Readerを作らない。`category_map`で解決する既存方針のまま）。
- **`AdapterPlatformWriter::write()`のディスパッチ拡張**: PR-A時点は`product`のみだった
  entityディスパッチに`customer`/`order`/`stock`/`coupon`を追加し、対応する
  `PlatformAdapter::push_*()`へ委譲するようにした（`DryRunPlatformWriter`はentity非依存の
  実装のため変更不要）。

**PR-B review-loop（自己レビュー＋独立サブエージェント）で判明し対応した項目**:

- **明細金額は`get_subtotal()`/`get_subtotal_tax()`を使う（`get_total()`/`get_total_tax()`ではない）**:
  Wooの`total`はWoo自身のクーポン計算後（割引後）の金額になるが、`totals.discount`
  （`get_discount_total()`）は明細とは無関係な注文レベルの控除として別に運ぶ契約
  （`Woo\Writer\OrderWriter::apply_totals()`。ColorMeのポイント/GMOポイント値引きが明細価格を
  一切変えないimport方向の契約と対称）。`get_total()`を使うと割引が「明細に織り込み済み」と
  「`totals.discount`で別途控除」の二重に効いてしまう（Wooネイティブのクーポンを使った注文で
  顕在化）。`OrderReader::line_item_amounts()`参照。
- **請求先住所・支払済み状態をexportする**: `Woo\Writer\OrderWriter::apply_addresses()`は
  `extras['customer_snapshot']`から、`apply_dates()`は`extras['paid']`から復元するため、
  空の`extras`のままだとエクスポート→再取込の往復で毎回失われる。`OrderReader::extras()`が
  請求先住所（Wooネイティブのキー。上記の住所方針と同じ）と`null !== $order->get_date_paid()`を
  積む。
- **決済手数料・ギフト包装料等のFee明細を合算する**: `$order->get_items()`は既定で`line_item`のみ
  返し`WC_Order_Item_Fee`を含まないが、`totals.total`（`get_total()`）にはFeeが合算済みで
  反映される。`OrderReader::payment()`が`$order->get_items('fee')`の合計を`payment.fee`へ積む
  （Woo側の汎用Fee明細は種別（決済手数料/ギフト包装料等）を安定に見分ける手段が無いため合算のみ。
  再取込では1本のFee行に統合される簡略化）。
- **`ORDER_TOTALS_INVALID`を`indicates_export_blocking()`に追加**: 注文合計（discount/
  shipping_fee/tax/total）が不正な場合、`0`へフェイルクローズするだけでは「¥0の注文」として
  実際の明細と一緒にpushされてしまう。importの`OrderWriter::validate_totals()`（`WC_Order`に
  一切触れる前に注文全体をskip）と対称に、push自体を止める。
- **参照先が削除済みの場合は再試行警告を出さない**: `ORDER_LINE_PRODUCT_NOT_EXPORTED`/
  `ORDER_CUSTOMER_NOT_EXPORTED`は「商品/顧客が実在するがまだエクスポートされていない」場合のみ
  積む（再エクスポートで解決する見込みがあるため）。参照先が削除済み（`WC_Order_Item_Product::
  get_product()`が`false`、または`get_userdata()`が`false`）の場合は再試行しても解決しない
  終端状態のため警告を積まない（`indicates_unresolved_reference()`のdocblockが定める
  「解決される見込みが無い終端状態は含めない」方針と同じ）。
- **N+1クエリの解消**: `OrderReader`/`StockReader`はページ内の全商品/バリエーション/顧客IDを
  先にスキャンし、`MappingRepository::find_many_by_local_ids()`で一括解決してから各行を組み立てる
  （`ProductReader::variants()`の一括先読みと同じパターン。アイテム毎の`find_remote_id()`呼び出しを
  避ける）。
- **CouponReaderの`has_native_restrictions()`を拡張**: 商品/カテゴリ/メールアドレス制限・
  `maximum_amount`に加え、`get_limit_usage_to_x_items()`（対象商品1点限定）・
  `get_exclude_sale_items()`（セール品除外）・`get_individual_use()`（他クーポンと併用不可）も
  `CanonicalCoupon`が運べない制限のため同じ扱いにする。
- **CouponReaderのカーソル順を新しい順に変更**: D15 §10.2「クーポン: 最新10件」を実現するため
  `orderby=date, order=DESC`にする（`'ID'`昇順＝作成日昇順のままだと、店を長く運営しているほど
  古い（期限切れの可能性が高い）クーポンだけが無料枠を占有してしまう）。
- **CustomerReaderの氏名復元を修正**: ColorMeの氏名は「姓 名」の単一文字列で、
  `Woo\Support\AddressMapper::split_name()`が最初のトークンを`last_name`、残りを`first_name`
  としてWooへ保存する。`first_name . ' ' . last_name`（Western順）で単純に組み直すと姓名が
  入れ替わって復元される（例:「山田 太郎」→復元すると「太郎 山田」）ため、
  `CustomerWriter::apply_extras_meta()`が同時に書く`_cbjp_full_name`（元の文字列そのもの）を
  優先して使う。このメタが無い場合（Woo上でネイティブに作成された顧客等）はWestern順に
  フォールバックする。
- **`Admin\DryRunReportCsv`に`reference_pending_export`ノートを追加**: `ORDER_LINE_PRODUCT_NOT_EXPORTED`/
  `ORDER_CUSTOMER_NOT_EXPORTED`/`STOCK_PRODUCT_NOT_EXPORTED`を`indicates_pending_import()`
  （「先にインポートしてください」）にそのまま混ぜると向きが逆の誤った案内になるため、
  `WarningCode::indicates_pending_export()`を新設し専用の`note`値を割り当てた
  （`CATEGORY_MAP_UNRESOLVED`用の`indicates_mapping_required()`と同じ設計）。

**E2-3への申し送り（E2-2 R1/PR-Bレビューで判明した未解決事項）**:

- **バリエーションのremote_id永続化経路が無い**: `Woo\Reader\ProductReader`は
  `cbjp_mappings`（entity_type `variant`）からバリエーションの既存remote_idを逆引きするが
  （`VariationWriter::sync_one()`が書く行の読出側対称形）、`push_product()`の戻り値
  `Adapters\PushResult`は商品1件につきremote_id 1つしか運べない。E2-3で実際にColorMeへ
  バリエーションを作成できるようになった時点で、個々のバリエーションremote_idを
  `cbjp_mappings`（`variant`）へ書き戻す経路（`PushResult`の拡張、または商品とは別の
  戻り値チャネル）を設計すること。**現状のまま実装すると、バリエーションを持つ商品の
  再エクスポートのたびに新しいバリエーションがASP側に重複作成される**（`variant`のremote_idが
  常に未確定＝空文字列のまま新規作成候補として送られ続けるため）。
- **サンプルクリーンアップは自プラットフォーム未所有のWoo商品を削除できない**:
  `Woo\Tools\SampleCleanup`は`_cbjp_platform`メタで所有権を確認できる実体のみ削除する。
  Woo側で直接作成された商品（インポート由来ではない）をエクスポートしてもこのメタは付与
  されない（`AdapterPlatformWriter`はWoo側を一切書き込まないため）ため、クリーンアップは
  該当商品のmapping行を`unlink`するだけで実体もASP側remote entityも削除しない。この状態で
  再度サンプル選定→エクスポートを行うと、同じWoo商品が「未リンク」として扱われ**ASP側に
  重複した商品が作成される**。エクスポート方向のサンプルクリーンアップを提供する場合、
  「作成元がexportで、対応する削除APIをASPが提供しない」実体はunlinkも含めて拒否する
  （原則4「破壊的操作の禁止」を踏まえ、削除ではなく状況を明示した警告に倒す）等の設計が必要。
- **複数リクエストから成るpushの部分完了契約が無い（E2-2 G3レビューで判明）**: `push_product()`
  が商品本体の作成に続けて画像・バリエーション等の別リクエストを行う実装になった場合
  （E2-3のスコープ）、後続リクエストが失敗・レート制限に達すると、現状の`Adapters\PushResult`
  は「商品自体は作成できたがremote_idを持つ」という部分完了状態を表現できない。アダプタが
  例外を投げれば`Exporter`は1件失敗として扱うが、既に作成された商品のremote_idはどこにも
  記録されないため再実行時に別の重複商品が作られる。かといってremote_idを持つ`PushResult`を
  警告付きで返しても、その警告が`WarningCode::indicates_unresolved_reference()`の集合に
  含まれない限り`Exporter`はchecksumをキャッシュしてしまい、以後の再試行でアダプタ自体が
  スキップされ画像・バリエーション等の欠落が永久に修復されない。E2-3で複数リクエストに
  分割されるpushを実装する場合、部分完了（remote_idは確定したがサブリソースは要再試行）を
  表現できる耐久的な契約を`PushResult`/`Exporter`に設計すること（`docs/review-backlog.md`
  `e2-2-exporter-core/G3-H-partial-completion-contract`参照）。
- **`push_order()`は更新（remote_id指定）を受け付けない（PR-B review-loopで判明）**:
  `PlatformAdapter::push_order( CanonicalOrder $order ): PushResult`は`push_product()`/
  `push_customer()`/`push_coupon()`と異なり`?string $remote_id`引数を持たないため、
  `AdapterPlatformWriter`は`Sync\Exporter`が解決した既存remote_idをpushに渡せない。
  受注の内容変更（ステータス変更等）でchecksumが変わり再pushが必要になった場合、E2-3の
  `push_order()`実装は常に「新規作成」として扱わざるを得ず、ASP側に重複した受注が作られる
  懸念がある。E2-3で受注の更新を許容するASPが出てきた場合、インターフェース変更
  （`push_order( CanonicalOrder $order, ?string $remote_id ): PushResult`。3ASP全ての実装への
  影響を要確認）を検討すること。

#### エクスポート方向の実装（E2-3 PR-A: `push_product()`）

`ColorMeAdapter::push_product()`（PR #43）が上記「E2-3への申し送り」の3項目
（バリエーションremote_id永続化・部分完了契約・要検証#5着手前の実装）に対応した。
`PushResult::$variant_remote_ids` + `ReadItem::$variant_local_ids`でバリエーション単位の
remote_idを`cbjp_mappings`（`variant`）へ書き戻し、部分完了は`WarningCode::
indicates_unresolved_reference()`対象の警告＋`is_retryable_failure()`/`record_failure()`/
`append_failure_warning()`（再試行可能/終端の分類）で表現する。詳細・レビュー履歴は
`docs/reviews/feat/e2-3-push-product/`参照。

#### エクスポート方向の実装（E2-3 PR-B: `push_customer()`）

- **【重大・要対応】ColorMeの`pref_id`はJIS X 0401標準（＝WooCommerceの`JPxx`番号）と並びが
  一致しない（G3ゲートで判明, Codex/Copilot, P1）**: `state_code()`（インポート方向。**Phase 1
  から本番稼働中の既存コード**）は`pref_id`をそのまま`JP%02d`の数値部分として使っていたが、
  ColorMeのAPIドキュメント（swagger `info.description`に埋め込まれた「都道府県コード一覧」表。
  構造化されたJSONスキーマとしては提供されていないため見落としやすい）はJIS標準と異なる並びで、
  47都道府県中約20件で番号がずれる（例: ColorMeのpref_id=4は秋田県だがJIS/Wooの4番は宮城県、
  16↔18は福井/富山が入れ替わり、19〜23・25〜30・31〜34・36〜37・43〜44も同様）。都道府県名で
  突き合わせた明示的な対応表`AddressMapper::PREF_ID_TO_JIS_NUMBER`を新設し、`state_code()`と
  新設`pref_id_from_state()`の両方をこの表経由に修正した（`wp eval`でのWooCommerce実測と
  swagger記載を名前で機械的に突き合わせ、全47件をプログラムで検証済み）。
  **本番データへの影響**: この不具合はPhase 1（F1-4/F1-5）から存在するため、影響を受ける
  約20都道府県の顧客・受注住所は、本PRマージ以前にインポート済みの実店舗データで**既に誤った
  都道府県が保存されている可能性が高い**（F1-8の実店舗2件を含む）。是正には該当都道府県の
  既存Woo顧客・受注の`billing_state`/`shipping_state`を再インポートまたは一括修正する対応が
  別途必要（本PRのスコープ外。マージ後に別issueとして対応要）。
- **住所スキーム変換は`Woo\Support\AddressMapper`に対称の逆関数を追加**: インポート方向の
  `state_code()`/`is_overseas()`（ColorMeの`pref_id`1-47=都道府県／48=海外というエンコーディングを
  `PREF_ID_SCHEME_PLATFORMS`でColorMeのみに限定して解釈する）に対し、エクスポート方向で必要な
  `state`→`pref_id`の逆変換を`pref_id_from_state( string $platform, ?string $state, ?string $country
  ): ?int`として同じファイル・同じゲートで追加した。`state`が`JP01`〜`JP47`（`PREF_ID_TO_JIS_NUMBER`
  経由）ならその`pref_id`、一致せず`country`が非空かつ`'JP'`以外なら`48`（海外。swaggerの`pref_id`
  description記載の特別値）、それ以外は変換不能として`null`。「ASP固有スキームへの変換は
  push_customer()の責務」（E2-2 PR-B、上記「住所はWooネイティブのキーのまま運ぶ」参照）という方針は
  「`Woo\Reader\CustomerReader`（プラットフォーム非依存）に変換を持ち込まない」ことが本旨であり、
  既にColorMe専用ゲート済みの`AddressMapper`を`Adapters\ColorMe\Transform\CustomerTransformer`から
  再利用することはこれに反しないと判断した（対称の変換を複製すると2箇所が食い違うリスクを負う）。
- **新規作成必須フィールドの欠落はAPIを呼ばずスキップ**: `POST /v1/customers`は`name`/`mail`/
  `pref_id`/`postal`/`address1`/`tel`が必須（`PUT`は部分更新で必須項目なし）。Woo顧客の請求先情報
  から`pref_id`/`postal`/`address1`/`tel`のいずれかを解決できない場合、送信すると確実に422になる
  ため`CustomerTransformer::to_create_payload()`が`null`を返し、`ColorMeAdapter::push_customer()`が
  `PushResult('', OPERATION_SKIPPED, [WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING])`で
  フェイルクローズする（`push_product()`の`requires_hidden_safeguard()`と同じ思想）。`remote_id`が
  空文字列のため`Sync\Exporter`はmappingsへupsertせず、店舗がWoo側の顧客情報を補完すれば次回
  exportで自動的に再試行される。この警告は`PushResult`からのみ発生し`DryRunPlatformWriter`は
  アダプタを呼ばないため、dry-runでは検出できない（`PRODUCT_DETAILS_PUSH_INCOMPLETE`等と同じ
  既知の限界）。
- **新規作成時に`add_member: true`を送る**: ColorMeの`member`（会員登録済みフラグ）が`false`の
  顧客は`CustomerTransformer::transform()`（インポート方向）がWoo顧客として取り込まないゲスト
  スナップショット扱いのため、対称性を保つには作成した顧客も会員登録する必要がある。付けないと
  ColorMe側でログイン不可のゲスト相当になり、往復インポートで再度取り込めなくなる。
- **`extras`の往復ロスは対応しない（既知の制限）**: `Woo\Reader\CustomerReader::to_read_item()`は
  `CanonicalCustomer::$extras`を常に`[]`で構築するため、`fax`/`sex`/`tel_mobile`/
  `answer_free_form1-3`はColorMeにインポート時点で取り込まれていてもexportで送信できない。
  この往復時のデータ欠損の扱いはE2-4「往復E2E」のスコープとする。
- **`address1`は`city`+`address_1`を連結する（R1レビューで判明）**: ColorMeの`address1`は
  swagger上「住所1（**市区町村**・番地）」の1フィールドだが、WooCommerceのJPロケール
  （`WC()->countries->get_address_fields('JP')`で実測確認）は`billing_city`（市区町村・必須）と
  `billing_address_1`（番地・必須）を別フィールドとして扱う。`city`を無視すると、ネイティブWoo顧客
  （exportの主対象）の住所から市区町村がまるごと欠落したまま警告も無く作成されてしまう
  （独立サブエージェントによる敵対的レビューで検出）。ColorMe由来の往復顧客は`AddressMapper::
  to_woo()`が`city`を常に空文字列にする契約のため、連結しても元の1フィールド文字列のまま変わらない。
- **`tel`は装飾文字のみ除去してパターン検証する（R1レビューで判明）**: swaggerの`tel`は
  `pattern: "^[\d-]+$"`（数字とハイフンのみ）だが、Wooの`billing_phone`は空白・括弧を含む表記を
  許容する。明らかに装飾目的の空白・半角/全角括弧のみを除去してからパターン一致を検証し、
  それでも一致しない値（国際番号の`+`付き等）は解決不能としてnullへ倒す（`+`を機械的に除去すると
  国番号が消えた別の番号に化けるため、桁を落とす変換はしない）。新規作成は必須項目のためスキップ、
  更新は省略する。
- **`postal`/`address1`/`pref_id`は3点セットで解決できた場合のみ送る（R1レビューで判明）**:
  `to_update_payload()`が各要素を個別に省略すると、一部だけ解決できた場合（例:
  郵便番号は分かるが都道府県が不明で`pref_id`が省略される）に「新しい郵便番号＋ColorMe側に
  残った古い都道府県・住所」という内部矛盾した住所へ更新しかねない。`address2`は補足情報のため
  この3点セットとは独立に送ってよい。
- **`add_member: true`の通知メール有無は未検証（要検証#17）**: 会員登録時にColorMeが
  パスワード設定案内等を自動送信するかはswaggerに記載が無い。本プロジェクトは移行時の副作用
  （通知メール等）抑止を重視する方針のため、実店舗確認まで要検証のまま残す（コードは
  round-trip整合性のため`add_member: true`を維持）。

#### エクスポート方向の実装（E2-3 PR-C: `push_order()`）

要検証#5（受注POSTの必須項目・決済/配送ID）を`tests/fixtures/colorme/swagger.json`の
`POST /v1/sales`精査で確定: プレミアムプラン契約のショップのみ利用可（`capabilities()`の
`can_create_order`は`is_premium_plan()`で既にゲート済み）、必須は`sale.details`
（各行`product_id`+`product_num`）と`sale.payment_id`のみ、`sale.customer`は丸ごと省略可で
既存顧客IDを渡す場合は他の属性が無視される、`sale.sale_deliveries`は配送不要商品を含む場合を
除き必須（各行の必須項目に`furigana`を含む＝Wooに無いデータ）、`sale.details[].price`を
省略するとColorMeの現在のカタログ価格が適用される、`PUT /sales/{id}`は入金状態・配送情報の
一部しか更新できず内容更新は実質不可能。

- **`PlatformAdapter::push_order()`のシグネチャを他のpush系と統一**: `push_order(CanonicalOrder
  $order): PushResult` → `push_order(CanonicalOrder $order, ?string $remote_id): PushResult`
  （`push_product`/`push_customer`/`push_coupon`と同じ形。D19が確立した「外部アドオンによる
  カスタムアダプタ実装は現時点で存在しないため、確定版インターフェースへの追加による後方互換
  リスクは低い」という判断を踏襲）。`$remote_id`が非nullの場合はAPIを呼ばず`PushResult('',
  OPERATION_SKIPPED, [ORDER_UPDATE_NOT_SUPPORTED])`を返す。PUTが内容更新を実質サポートしない
  ため、再POSTすると重複した受注が作成されてしまう（E2-2 PR-Bレビューで判明した申し送り事項の
  解消）。checksumはキャッシュされないため、この状態は解消される見込みがない終端警告として
  毎回の再エクスポートで出続ける（`CUSTOMER_ACCOUNT_PROTECTED`と同じ位置づけ）。
- **D19の申し送り（`payment_map`/`shipping_map`の逆引きの曖昧性）の解決**: `Woo\Support\
  MethodMap`に`asp_payment_id()`/`asp_delivery_id()`を追加。Woo側の値に対応するASP側キーを
  列挙し、**ちょうど1件**のときのみ解決する（0件=未マッピング・2件以上=複数のASP方式が同じ
  Woo方式に寄せられ曖昧、のいずれも同じ「未解決」として扱い、申し送りが挙げた案のうち
  「複数一致時はフェイルクローズする」を採用）。未解決の場合は受注全体をpushせず、既存の
  `WarningCode::PAYMENT_METHOD_UNMAPPED`/`SHIPPING_METHOD_UNMAPPED`（import方向と共通のコード）
  で警告する。
- **明細行**: `Woo\Reader\OrderReader`が既に解決済みの`remote_product_id`（親商品のremote_id。
  バリエーションは`option1_value_current`/`option2_value_current`で識別）をそのまま使う。1行
  でも未解決なら受注全体をpushしない（`details[].product_id`必須のため部分的な受注を作れない）。
  この場合の警告は`OrderTransformer::to_create_payload()`からは積まない: `Woo\Reader\
  OrderReader`が既にreadItemの警告（`ORDER_LINE_PRODUCT_NOT_EXPORTED`等）へ積んでおり、
  `Sync\Exporter::process_items()`がpush結果と無関係にこれを最終警告へマージするため重複させる
  必要が無い。
- **顧客**: `customer_ref`が解決済みなら`customer.id`のみ送る（swagger:
  「顧客ID以外の顧客情報は無視されます」）。未解決の場合は`extras['customer_snapshot']`
  （Wooの請求先情報）からベストエフォートでゲスト顧客情報を組み立てる。`customer`自体が
  `sale`作成に必須ではないため、解決できない項目は省略し受注全体はブロックしない。
- **配送先（`sale_deliveries`）**: Wooの配送先住所が空（`shipping.address_1`が空）の場合は
  請求先住所（`customer_snapshot`）へフォールバックする（「配送先を別途指定」しなかった場合、
  配送先は空のまま保存され請求先が実際の届け先になる一般的なWooチェックアウトの挙動を踏まえた
  判断）。`postal`/`pref_id`/`address1`/`tel`/`name`のいずれかが解決できない場合は受注全体を
  pushしない（新警告`ORDER_SHIPPING_ADDRESS_INCOMPLETE`）。`furigana`は値を持たないため
  空文字列を送る（swagger上パターン制約・`minLength`指定が無いため有効な値）。**既知の制限**:
  配送不要な仮想商品のみの受注も`CanonicalOrder`が配送要否を運ぶフィールドを持たないため
  同じ経路でスキップされる。
- **明細価格**: ショップの`tax_type`（`shop.json`）が既知の値（`excluded`/`included`）の場合
  のみ、`Woo\Reader\OrderReader`が計算済みの明細単価（`unit_price_excl_tax`/`price`＝税込）を
  明示指定する。省略するとColorMeの現在のカタログ価格が適用されてしまう（swagger）ため、過去の
  受注金額を保持するには本来必要だが、税区分が不明なまま断定的に送ると誤った税基準の金額に
  なりうるため、不明な場合は省略しカタログ価格適用という文書化済みのフォールバックに委ねる。
  `push_order()`専用に`ColorMeAdapter::order_tax_type()`（`shop.json`を遅延取得・インスタンス
  単位でキャッシュ）を新設した。import方向の`OrderTransformer::transform()`はこのデータを
  使わないため、全fetch系メソッドが経由する共有インスタンス`order_transformer()`には持たせず
  独立させている（`order_transformer()`に混ぜて全呼び出しで無条件に`shop.json`を叩くと、
  importのみを行うジョブでも不要なAPIコールが発生する）。
- **在庫二重引き当ての防止**: `POST /sales.json?reserve_stocks=false`を指定する。過去のWoo
  受注を複製するのであって新規注文ではないため、既定（在庫引き当て）のままだとColorMe側の
  現在庫を実売と無関係に消費してしまう（在庫同期は別タスクの`push_stock()`の責務）。
- **共有ロジックの抽出（移動のみ、ロジック変更なし）**: `CustomerTransformer::
  address_payload()`の本体（postal/pref_id/address1/address2の3点セット判定＋海外住所の地域
  付記＋`join_address1()`）を`Woo\Support\AddressMapper::to_asp_address_payload( string
  $platform, array $woo_address ): array`へ移設し（`pref_id_from_state()`と同様`$platform`
  引数を持つプラットフォーム非依存の形）、`CustomerTransformer`・新設`OrderTransformer`
  （配送先・ゲスト顧客変換）で共有する。`CustomerTransformer::normalize_tel()`も
  `Adapters\ColorMe\Transform\Cast::normalize_tel()`へ移設した。「対称の変換を複製すると
  2箇所が食い違うリスクを負う」というD19 PR-Bで確立済みの方針を踏襲したが、`normalize_tel()`
  自体はR1レビューで`/v1/customers`専用（swaggerのパターン制約`^[\d-]+$`はこのエンドポイント
  にしか無い）と判明したため、`OrderTransformer`はこのメソッドを使わない設計に修正した
  （下記「R1レビューで判明し対応した指摘」参照）。
- **対象外（既知の制限として記録。次PR以降）**: 受注ステータス（paid/delivered/cancelled）の
  事後同期は行わない（作成時点のColorMe既定状態のまま）。将来必要になれば`PUT /sales/{id}`
  （`paid`）・`PUT /sales/{id}/cancel`へのフォローアップリクエストとして別途設計する
  （`push_product()`の複数リクエスト部分完了契約と同種の設計が必要になる）。`push_stock()`は
  次のPRで対応する。
- **R1レビューで判明し対応した指摘**（独立サブエージェントによる敵対的レビュー）:
  - 配送先の`name`/`tel`は住所（`postal`/`pref_id`/`address1`）とは独立に、無い方だけ請求先へ
    フォールバックするよう修正（Wooの配送先フォームは電話番号欄を持たないテーマ・バージョンが
    多く、住所自体は入力されているのに`tel`だけ欠ける一般的なケースで受注全体が不必要に
    スキップされていた）
  - `Adapters\ColorMe\Transform\Cast::normalize_tel()`（`/v1/customers`の`pattern: "^[\d-]+$"`
    専用）を受注方向（`sale_deliveries[].tel`/`sale.customer.tel`）には適用しないよう修正
    （swagger確認: どちらもパターン制約が無く、国際番号等の正当な値を無警告でnullへ丸めていた）
  - `Woo\WarningCode::indicates_export_blocking()`に`ORDER_LINE_AMOUNT_INVALID`/
    `ORDER_LINE_QUANTITY_INVALID`を追加（`Woo\Reader\OrderReader`がフェイルクローズ済みの
    ¥0/捏造数量を、`price`明示指定時にColorMeへ恒久的な金額・数量として送ってしまう
    `PRODUCT_PRICE_INVALID`と同型の金銭的リスクだったため）
  - `sale.details`が0行（商品明細を持たない受注）を`line_items_unresolved`と区別する
    `line_items_empty`フラグ・`WarningCode::ORDER_LINE_ITEMS_EMPTY`を新設（`Woo\Reader\
    OrderReader`は明細0行に警告を積まないため、従来は無警告のまま結果から消えていた）
  - `WarningCode::ORDER_DISCOUNT_NOT_PUSHED`を新設（`POST /v1/sales`のリクエストスキーマに
    割引・クーポン額を運ぶフィールドが無いため、Wooのクーポン値引きが定価のまま送信されることを
    情報提供として警告する。ブロックはしない）
  - 対応を見送った指摘は`docs/review-backlog.md`の`e2-3-push-order/R1-*`を参照（顧客が未export
    のまま受注が先にゲスト扱いでpushされ後から会員紐付けを復元できない設計上の限界=Medium、
    memo/preferred_date/preferred_periodの往復ロス=Low、非数値マッピング値の`(int)`丸め=Low）
- **G1ゲート（Copilot/Codex）で判明し対応した指摘**:
  - 明細単価の端数丸めで合計がずれる（Copilot, High）: `sale.details[].price`は単価×`product_num`
    方式のため、Wooの明細合計が数量で割り切れない場合（例: ¥1000÷3個→単価333.33→整数円333、
    333×3=999）、整数円へ丸めた単価×数量が実際の合計と一致しなくなる。割り切れない場合は
    `price`自体を省略しカタログ価格適用へフォールバックするよう修正（`unit_price_divides_evenly()`）
  - 既存受注スキップ時の情報提供警告欠落（Copilot, Medium）: `push_order()`の早期return
    （既にエクスポート済みの受注のスキップ経路）が`to_create_payload()`を経由しないため、
    割引・手数料付きの受注でも対応する警告が一切積まれなかった。`OrderTransformer::
    has_discount()`/`has_non_representable_charges()`をI/O不要の`public static`にし、
    早期returnからも呼べるよう修正
  - 決済手数料・送料が運べない（Codex, P1）: `sale`のリクエストスキーマ
    （`customer`/`sale_deliveries`/`details`/`payment_id`）には`payment.fee`/`shipping.fee`を
    運ぶフィールドが無い（swagger確認済み）。`ORDER_DISCOUNT_NOT_PUSHED`と同じ理由・同じ設計
    （blocking化すると送料の付くほぼ全ての受注が移行できなくなる）で`WarningCode::
    ORDER_FEE_NOT_PUSHED`を新設し情報提供の警告に留める
  - 受注日時が保持されない（Codex, P1）: `sale`のリクエストスキーマに受注日時フィールドが無い
    （swagger確認済み）ため、ColorMe側の受注日時は`CanonicalOrder::$placed_at`ではなくpushを
    実行した時刻になる。新規作成成功時は常に`WarningCode::ORDER_PLACED_AT_NOT_PRESERVED`を
    付与する（`PRODUCT_IMAGES_NOT_PUSHED`と同種の、ストアの性質上恒久的に解消しない情報提供警告）
  - 対応を見送った指摘は`docs/review-backlog.md`の`e2-3-push-order/G1-*`を参照
    （dry-runでの割引/手数料警告の非対応=Low/既知の限界、応答喪失時の重複作成リスク=High/対象外
    ＜push_product/customerと同根の限界＞、404での再作成不可=Medium/対象外、create-sale非対応
    決済種別のフィルタリング未実装=Medium/保留）

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

#### ツールの実装詳細（F1-7）

- **サンプルクリーンアップ**（`Woo\Tools\SampleCleanup`、`GET/POST /tools/sample-cleanup`）: `cbjp_mappings`（platform単位）を正とし、
  指す先の実体が `_cbjp_platform` メタで自プラットフォーム所有と確認できるものだけ削除する。所有権が無い・実体が既に無い行は
  mapping 行だけ外す（`unlinked`）。削除順は order → stock/review（mapping行のみ）→ product（`VariationWriter::find_owned_variation_remote_ids()`
  + `remove_all()` で所有 variation を先に削除）→ variant → coupon → customer → tag → category → 所有添付（`_cbjp_platform` + `_cbjp_source_url` 付き
  attachment のうち、親投稿が無い／削除済みで、既存タームの `thumbnail_id` でもない**孤児**のみ。mappings を失った店舗でクリーンアップを実行しても
  残っている商品・タームの画像を道連れにしない）。
  **顧客**: `CustomerWriter` は email 突合で採用した既存 WP ユーザーにも `_cbjp_platform` を書くため、新規作成時にのみ書く
  `_cbjp_created_by_import` マーカー（値は**作成したプラットフォームID**。不変で、採用では書き換えない）が自プラットフォームと一致し、
  別プラットフォームが現在リンク中（`_cbjp_platform` が他プラットフォーム）でなく、店舗スタッフ権限（`PROTECTED_ROLES`）を持たず、
  実行中の管理者自身でもないアカウントを、**実行者が `delete_user` 権限を持つ場合のみ** `wp_delete_user()` する（ルートの `manage_woocommerce`
  だけでは shop_manager が WP 管理画面でできないアカウント削除を行えてしまうため）。それ以外はリンク用メタ（`_cbjp_platform`/`_cbjp_remote_id`。
  自プラットフォームがリンクしている場合のみ）を外して残し、マーカーは残す（別プラットフォームがリンクを解いた後に作成元が再び取り込めば削除できる）。
  ただし**自プラットフォームが作成したアカウントが mapping に残っているのに実行者が `delete_users` を持たない場合は、実行自体を 403 で拒否**する
  （unlink だけして mappings とサンプルセットをリセットすると、無料版の顧客上限（`LimitPolicy`）を回避してアカウントを増やし続けられるため。
  アーキテクチャ原則 7）。
  **プレビュー**（`GET /tools/sample-cleanup`）は mapping 行数ではなく、`run()` と同じ所有権・実在・権限判定で「削除される件数」と
  「mapping を外すだけの件数」をエンティティ別に返し、`requires_delete_users` / `can_delete_users` / `sample_selected`（mapping が無くても
  サンプルセットだけ残っている場合に「選定のクリア」として実行できる）を併せて返す。
  **バッチ契約**: Action Scheduler ジョブにはせず、1リクエストで予算（100実体。商品削除に伴う variation も数え、予算を使い切った時点で
  取得済みページの残りも次のバッチへ回す）まで削除して `has_more` を返し、管理画面がループする（削除済み行は消えるため cursor 不要）。全て消え切った呼び出しで残骸 mappings（`delete_for_platform()`）と `cbjp_sample_{platform}` を削除し、
  次回 import でサンプルが再選定される（§10.2 #7）。実行中のジョブ（pending/running/paused）がある間は 409。削除中は `SideEffectGuard` で
  メール・在庫復元を抑止する
- **リンク再構築**（`Woo\Tools\MappingRebuilder`、`POST /tools/rebuild-mappings`）: D16 の記述（SKU/email/注文番号突合）に対し、実装は
  各 Writer が Woo 側実体へ必ず書く **`_cbjp_platform` + `_cbjp_remote_id`（受注は `_cbjp_remote_order_number` = `CanonicalOrder::remote_id()`）
  メタを主キー**に走査する（category/tag は term meta、product/variant/coupon は post meta、customer は user meta、order は `wc_get_orders()`。
  受注の `meta_query` は HPOS の `OrdersTableQuery` しか解釈せず、レガシー投稿型ストレージでは WC 9.2+ が非対応引数として無視するため、
  HPOS 有効時の最適化としてのみ付け、どちらの構成でも取得後に `_cbjp_platform` を検証したものだけを対象にする。cursor の前進はクエリが
  返した件数で判定する）。SKU/email 突合は「本プラグイン外で作られた Woo データを ASP に紐付ける」動作になり誤リンクの危険があるため採用しない。
  checksum は null で upsert し、次回 import で必ず再検証させる。stock（product/variant から再解決される）と review（v1.0 に Writer 無し）は対象外。
  予算 200 件/リクエストで `{entity, offset}` の cursor を返し、管理画面がループする（upsert は走査結果を変えないため offset ページングで安定）

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
- **CSV列**: `entity, remote_id, label, operation, existing_local_id, warning_code, warning_detail, note`。1アイテム×1警告=1行に展開（`WarningCode::split()`で`:`区切りを最初の1つだけ分割）。`note`列は`WarningCode::indicates_pending_import()`が真の警告（`indicates_unresolved_reference()`の集合＋`stock_product_unresolved`）に`reference_pending_import`を付与（初回dry-runではmappingsが空なため大量に出る「未インポートが原因の未解決」を、実際の不整合と区別するため。在庫は親商品未解決だとアイテム自体を保存しないためchecksumキャッシュ判定の対象外だが、レポート上は同じ注記を付ける。F1-5実機確認で判明）。UTF-8 BOM付き。全ASCII制御文字（タブ/CR/LF含む）を除去したうえで、OWASP CSVインジェクション対策として`=`/`+`/`-`/`@`始まりのセルに`'`前置
- **dry-runでは判定できない警告**（保存を実際に試みないと分からない、またはネットワークI/Oを伴うため`validate()`では意図的に実行しない）: `PRODUCT_SAVE_FAILED` / `ORDER_CREATE_FAILED` / `COUPON_SAVE_FAILED` / `TERM_CREATE_FAILED` / `TERM_UPDATE_FAILED`（更新パスのバリデーション失敗のみ。新規作成パスの名前衝突は`term_exists()`による事前チェックで`write()`と共有し判定可能） / `VARIATION_SAVE_FAILED` / `VARIATION_REMOVED` / `VARIATION_PRICE_INVALID` / `VARIATION_SNAPSHOT_INCOMPLETE`（`VariationWriter`は親ID確定後にしか走らないため） / `IMAGE_DOWNLOAD_FAILED`（dry-runは実際のダウンロードを行わない） / `CUSTOMER_CREATE_FAILED`（`CUSTOMER_EMAIL_CONFLICT`は`email_exists()`による読取専用の事前チェックで`write()`と共有し判定可能）
- **F1-6の残作業（PR-B）**: React Import タブ（エンティティ選択・dry-runプレビュー・CSVダウンロードリンク・進捗ポーリング・結果レポート・上限到達時のPro案内）と Logs タブのUI実装。バックエンド（本節の内容）はPR-Aで完結し、`GET /runs/{run_id}`（進捗）・`GET /runs/{run_id}/report`（CSV）・`GET /limits`（Pro案内用の残数）は実装済み

#### 移行後検証レポートの実装詳細（F1-7）

- **ASP側の金額**: `Sync\Importer::process_items()` が `CanonicalOrder` の `totals['total']` を、書込の成否・checksum 一致スキップに関わらず
  全 processed 分で `remote_amount`（1/100 単位の int。`Support\Money` で文字列解析し float を使わない）へ累積し、`JobRepository::empty_totals()` の
  キーとして job の `totals_json` に永続化する（`JobManager::merge_totals()` はページ毎に加算）
- **集計**（`Sync\VerificationReport`、`GET /runs/{run_id}/verification`。`type=import` の run のみ。dry-run は 400）: ジョブごとに
  `processed`（この run で ASP から取得）/ `written`（created+updated）/ `skipped` / `warned` を totals から、`linked`（mappings が指す
  ローカルIDの重複除去数）/ `existing`（`Woo\Tools\LocalEntityLookup` が 200 件ずつ実在確認。受注は要件どおり `wc_get_order()` のみで判定し
  ゴミ箱は不在扱い）/ `missing`（= linked − existing）を現在の Woo から算出する。受注は `remote_amount` と、実在するリンク済み受注の
  `WC_Order::get_total()` 合計（`local_amount`）を `"1234.00"` 形式の10進文字列で返す。通貨は店舗通貨（`currency`）と ASP 側
  （`platform_currency` = `OrderWriter::PLATFORM_CURRENCY` = JPY）を分けて返し、両者が異なる場合（`currency_mismatch`）は `OrderWriter` が
  金額を換算せずそのまま保存しているため UI は金額突合を「不可」として扱う。F1-7 より前のジョブ（`totals_json` に `remote_amount` 無し）は
  ASP 側合計を null（不明）で返す
- **解釈**: ASP側は「この run で取得した全件」（無料版の上限でスキップした分を含む）、Woo側は「リンク済みで実在する全件」（プラットフォーム
  全体・全期間）とスコープが異なる。UI（`src/components/VerificationReport.tsx`）は単なる一致/不一致ではなく差の向きを行ごとに示す:
  `missing`（実体を失った mapping → warning。Rebuild links / 再 import を案内）、`fewer`（Woo 側が取得件数より少ない → info。無料版の上限・
  スキップ・警告）、`more`（Woo 側が多い → info。過去の run で取り込んだ分、ASP 側で減った場合等）、`amount`（件数一致で受注合計のみ不一致 → warning）、
  `reconciled`。全ジョブが completed の import run にのみ表示する（failed/cancelled を含む `isTerminal` だけでゲートしない）
