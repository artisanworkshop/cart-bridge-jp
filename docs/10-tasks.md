# 実装タスク（WBS）

最終更新: 2026-09-05

本ファイルが実装タスクの唯一の管理台帳。各タスクは Opusplan の1セッション（plan → 実装 → 検証）で
完結する粒度に分割してある。

## リリース計画（D18・2026-09-05改訂）

| バージョン | 対応プラットフォーム | フェーズ | 状態 |
|---|---|---|---|
| **v1.0** | カラーミーショップ（インポート＋エクスポート） | Phase 0〜3 | Phase 1 進行中（F1-5後続〜F1-8 残） |
| **v2.0** | + BASE（インポート＋エクスポート※） | Phase 4〜5 | 未着手（v1.0 公開後） |
| **v3.0** | + MakeShop（インポート＋エクスポート） | Phase 6〜7 | 未着手（v2.0 公開後） |
| Pro版アドオン | 無料版上限の解除（プラットフォーム非依存） | — | 別リポジトリ |

※ BASE のエクスポートは API 制約により商品・カテゴリ・在庫のみ（受注作成・顧客APIなし）。

旧計画（0基盤→1カラーミー→2MakeShop→3BASE→4エクスポート→5公開、v1.0はBASE込み）からの変更点:
MakeShop/BASE のインポートを v1.0 から外し、カラーミーのエクスポートを v1.0 に前倒し。BASE と MakeShop の
順序を入れ替え（BASE→MakeShop）。タスクIDは新フェーズ番号で採番し直した（旧 `M2-*`→`M6-*`、
旧 `B3-*`→`B4-*`、旧 `E4-1/2/3`→`E2-1/2/3`、旧 `E4-5`→`E5-1`、旧 `E4-4/6`→`E7-1/2`、旧 `R5-*`→`R3-*`）。
いずれも未着手だったため実装・PRへの影響なし。コード内コメントに残る旧ID（`ColorMeAdapter` の `E4-3`、
`RestController` の `E4-2`）は該当タスク着手時に直す。
**v1.0 完了（Phase 3）前に Phase 4 以降へ着手しない。**

## 進め方（各セッション共通）

1. セッション開始時に `docs/00〜03` と本ファイルの該当タスクを読む
2. `main` から `feat/{タスクID}-{短い説明}` ブランチを作成。
   **ただし1タスク=1ブランチを機械的に適用しない**: 隣接するタスクが同じ依存レイヤーに属し、
   単体では動作確認可能な振る舞いを生まない場合はまとめて1ブランチ/PRにする
   （例: Phase 1では `F1-1(Client)+F1-2(OAuth/接続UI)`＝「接続できる」単位、
   `F1-3(Transformer)+F1-4(WooRepository)`＝フィクスチャ検証のみで閉じる単位、でまとめ、
   `F1-5(Adapter.fetch*+Importer結合)`＝最初にE2Eで動く統合ポイントは単独PRのまま。
   Phase 2以降でも同じ考え方を類推適用する）
3. plan モードで実装計画を立ててから着手
4. **完了条件**: 各タスク記載の成果物 + `composer lint && composer analyze && composer test:wpenv` が通ること（npm を含むタスクは `npm run lint && npm run build` も）。
   `composer test` はwp-envコンテナ内専用でホストからは動かない（CLAUDE.md「コマンド」参照）
5. PR 作成（gh コマンド）→ CI 通過 → マージ → 本ファイルのチェックボックスを更新
6. 要検証事項（03 §9）が確定したら 03 と該当計画ドキュメントを更新
7. フィクスチャ収集タスク（F1-0 / B4-0 / M6-0）では、コミット前に必ず
   `tests/fixtures/README.md` の匿名化ルールを適用する（public リポジトリのため個人情報厳禁）

---

## Phase 0: 基盤

> ゴール: アダプタを1つも持たない状態で、テーブル・IF・Support層・ジョブ骨格・管理画面骨格・CIが揃い、
> Phase 1 が「ColorMe ディレクトリを足すだけ」で始められる状態。

- [x] **P0-1: リポジトリ整備 + プラグインスケルトン**
  - `trunk` ブランチを `main` に統合し、以後 main をデフォルトに（`git branch -m` + `gh repo edit --default-branch main` 等）
  - `.gitignore`（node_modules, vendor, build, .wp-env.override.json 等）、`.editorconfig`
  - `cart-bridge-jp.php`（03 §7 のヘッダー、WooCommerce有効チェック、HPOS互換宣言）
  - `composer.json`（PSR-4: `CartBridgeJP\` → `includes/`）、`Core\Plugin`（シングルトン起動）、`Core\Activator`（03 §3 のDDLをdbDeltaで作成、DBバージョン管理）、`uninstall.php`
  - 成果物: wp-env上で有効化でき、3テーブルが作成される

- [x] **P0-2: 開発環境 + 品質ツール**
  - `.wp-env.json`（PHP 8.2、WooCommerce同梱、testsインスタンス）
  - `phpcs.xml.dist`（WordPress ruleset、PSR-4クラス名許容の調整）→ `composer lint`
  - `phpstan.neon.dist`（level 6、wordpress/woocommerceスタブ）→ `composer analyze`
  - PHPUnit（wp-env のtestsインスタンスで実行する bootstrap）→ `composer test`、Activatorのテーブル作成テスト1本
  - `package.json` + `@wordpress/scripts` + TypeScript設定、空のエントリポイントがビルドできること
  - 参考スキル: wp-phpcs / wp-phpstan / wp-phpunit

- [x] **P0-3: GitHub Actions CI**
  - 03 §8 の3ジョブ（php-quality マトリクス / php-test / js）
  - main保護（PR必須・CI必須）の設定
  - 参考スキル: wp-github-actions

- [x] **P0-4: Support層**
  - `Logger`（cbjp_logsへの書込 + WC_Loggerミラー。個人情報禁止ルールをdocblockに明記）
  - `HttpClient`（リトライ+指数バックオフ、Retry-After対応、ApiException）
  - `RateLimiter`（トークンバケット、$wpdbによる原子的更新）
  - `TokenStore`（sodium暗号化、**構造化ペイロード access/refresh/expires_at + リフレッシュ排他ロック=D13**、復号失敗・refresh失効時の再接続要求状態、末尾4桁マスク取得）
  - 各クラスのユニットテスト（HTTPは `pre_http_request` フィルターでモック）

- [x] **P0-5: Canonicalモデル + アダプタIF**
  - `Canonical\*` 8種（Product/Category/Tag/Customer/Order/Stock/Coupon/Review。readonly、`toArray/fromArray`、checksum算出用の正規化JSON）
  - `Adapters\`: `PlatformAdapter`（03 §2 確定版。**サンプル選定用の `fetchLatestOrders` / `fetchProductByRemoteId` / `fetchCustomerByRemoteId` を含む=D15**）、`Capabilities`（`canFetchCustomers` 含む）、`Cursor`、`Page`、`PushResult`、`ConnectionResult`、`ConnectionField`、`UnsupportedOperationException`
  - `AdapterRegistry`（フィルター `cbjp/adapters/register` で登録。Pro拡張ポイント）
  - Canonicalモデルのシリアライズ往復・checksumのユニットテスト

- [x] **P0-6: Sync層骨格（ジョブ基盤）**
  - `Sync\JobRepository` / `MappingRepository` / `LogRepository`（$wpdb + prepare）
  - `Sync\JobManager`（startRun、ステートマシン、Action Schedulerエンキュー、1アクション=1ページのループ、エンティティ直列実行、同時実行ガード）
  - `Sync\Importer`（fetch→変換→書込のパイプライン。書込先は `WooWriter` IFにし、`DryRunReporter` と差し替え可能に）
  - **`Sync\LimitPolicy` + `Sync\SampleSelector`（D15/03 §10.2）**: `cbjp/limits/{entity}` フィルター、mappings累積カウントによるサーバーサイド強制、サンプルセットの保存（`cbjp_sample_{platform}`）とフォールバック規則（※§10.2 #5後半の「受注10件未満時の残枠を商品・顧客で補完」は実アダプタの一覧取得が必要なため F1-5 で実装。Phase 0 は `used_fallback` フラグまで）
  - ログ30日保持の日次クリーンアップ
  - モックアダプタ（テスト用フィクスチャを返すだけ）でジョブが完走・再開できるユニットテスト（**上限強制・サンプル選定・フォールバックのテスト含む**）

- [x] **P0-7: 管理画面骨格 + REST骨格**
  - `Admin\Menu`（WooCommerce配下にページ登録）、`Admin\Assets`
  - `Admin\RestController`（03 §6 のルート定義。connections/runs/logs/limits は P0-6 のリポジトリと接続、未実装部分は501）
  - React アプリ骨格: タブ5つ（Connections/Import/Export/Logs/Tools）、api-fetch セットアップ、Connections タブは AdapterRegistry 由来の一覧を表示（アダプタ0件の空状態。Tools タブは空の骨格のみ・実装は F1-7）
  - i18n: `wp_set_script_translations` 設定

**Phase 0 完了チェック**: モックアダプタを登録すると管理画面に接続カードが出て、
ダミーインポートの run が開始→進捗ポーリング→完了まで通ること。

---

## 保守タスク（フェーズ外・随時対応）

- [x] **chore: PHPStan 2.x移行 + PHPCS系依存の脆弱性対応**（2026-08-14）
  `phpstan/phpstan` `^1.11`→`^2.0`、`phpstan-strict-rules`/`szepeviktor/phpstan-wordpress`も追随。
  `treatPhpDocTypesAsCertain: false` を設定（`apply_filters()`等の外部境界でdocblockの型を過信しないため。
  szepeviktor/phpstan-wordpress拡張がフィルターの返り値型をdocblockから読み取る都合上、
  防御的な`is_array()`等の実行時チェックが「常にtrue」の誤検知になっていた）。
  `CanonicalCategory`/`CanonicalOrder`/`CanonicalStock`/`CanonicalTag`の`remote_id()`戻り値型を
  `?string`→`string`に是正（コンストラクタのid相当フィールドが非nullableなためnullを返すことはない）。
  あわせて `composer audit` で判明した `squizlabs/php_codesniffer`/`wp-coding-standards/wpcs`/
  `phpcsstandards/phpcsutils` の脆弱性修正版へのアップグレードも実施

---

## Phase 1: カラーミー → Woo インポート（MVP・v1.0）

> 前提: デベロッパー登録・テストショップ・アプリ登録済み（D2）。詳細は `01-plan-colorme.md`。

- [x] **F1-0: フィクスチャ収集 + swagger精査**
  - `swagger.json` を `tests/fixtures/colorme/` に保存し、商品/受注/顧客スキーマを精査
  - **要検証#1（画像書き込み可否）/#14（受注の新しい順ソート）/#15（商品・顧客のID指定取得）をここで確定** → 03 §9 と Capabilities を更新
  - テストショップにサンプルデータ（バリエーション商品・オプション商品・法人顧客・各決済の受注）を登録し、各エンドポイントの実レスポンスJSONをフィクスチャ保存
- [x] **F1-1: ColorMeClient**（GET/POST/PUT、errors[]→ApiException、RateLimiter統合）+ ユニットテスト
- [x] **F1-2: ColorMeOAuth + 接続ウィザードUI**（認可URL生成、callback REST、state検証、code手動貼付フォールバック、shop.json接続テスト。要検証#7はF1-5実機確認で「httpのlocalhostでも自動リダイレクト可」と確定。自動リダイレクト・OOB手動貼付の両対応で実装済み）
- [x] **F1-3: Transformer 4種+**（Product/Customer/Order/Category + Tag(groups)/Coupon読取。フィクスチャベースのユニットテスト。マッピング表は 01 §4）
- [x] **F1-4: WooRepository**（商品/カテゴリ/タグ/顧客/受注/在庫のupsert書込。画像sideload、受注は 03 §5 の詳細仕様・HPOS対応CRUDのみ使用）+ テスト。
  `tests/bootstrap.php` にWooCommerceのテーブル作成（`WC_Install::install()`）とHPOS権威データストアの明示的有効化を追加。
  `Sync\WooWriterFactory`（platform単位でwriterを組み立てる）を新設し `JobManager` を配線変更、`NotImplementedWriter` を削除。
  extrasメタのキー規約は `_cbjp_*`（汎用）に統一（01 §4を更新）。既存Wooデータとの突合は顧客のみemail突合、商品/カテゴリ/タグはmappings欠損時は常に新規作成（既存データの誤上書きを避ける）
- [x] **F1-5: ColorMeAdapter.fetch\* + Importer結合**（カーソル=offset、`fetchLatestOrders`/ID指定取得含む、dry-run + **サンプル選定〜上限強制の実機確認=D15**。§10.2 #5後半の受注10件未満時フォールバック補完の実装を含む）。
  実装・ユニットテスト（フィクスチャベース）は完了、`JobManager`/`RestController`のColorMe向けブロックも解除済み。
  在庫は`GET /products.json`の`variants[].id`から導出（`GET /stocks.json`はバリエーションIDを返さず
  remote_id衝突するため不採用。01 §2更新）。`fetch_latest_orders`は`after`を4倍ずつ過去へ広げる方式
  （`before`は常に省略し暗黙の現在時刻に固定。03 §9 #14更新）。
  **実機確認（2026-09-03、issue #18）**: テストショップ（商品5・受注2・非会員顧客5）でOAuth接続→dry-run全量→
  サンプルインポート→再インポートを実施。サンプル選定（受注2件→フォールバックで商品5件補完、`used_fallback=true`）、
  `GET /limits`の使用数（product 5/50, order 2/10）とmappings累積の一致、再インポートの冪等性（checksum一致skip・重複ゼロ）、
  受注合計/税額/受注日時のUTC保持、CSVレポート（BOM・`reference_pending_import`注記）を確認。要検証#14/#16を実測で確定。
  判明した不具合と修正: (1) カラーミー`options[]`はバリエーション軸の定義そのものなのに`CanonicalProduct::$options`
  （非バリエーション属性）にも重複出力しており、全バリエーション商品に`attribute_name_collision`が付いていた
  →`ProductTransformer::options()`で軸名と一致するものを除外。(2) dry-run CSVの`note`列が`stock_product_unresolved`に
  `reference_pending_import`を付けず、初回dry-runで在庫全件が「実際の不整合」に見えていた→`WarningCode::indicates_pending_import()`を追加。
  **未検証のまま残る経路**（テストショップ側のデータ不足。F1-8の事前準備に含めること）: 顧客インポート（全顧客が`member=false`で
  仕様どおり除外され0件。会員登録した顧客の受注が必要）、画像sideload（全商品`image_url=null`）、クーポン（0件）、
  `stock_managed=true`かつ`stocks=null`の商品（フェイルクローズで在庫0=outofstockにしている。店頭での購入可否を要確認）
- [ ] **F1-5後続: 実機確認で判明した改善**（F1-8着手前に実施。単独で動作確認可能な単位ごとにPR）
  - [x] **定価→`regular_price`マッピング**（要検証#16確定分の実装）: `ProductTransformer`に`shop.json`の税設定
    （`tax_type`/`tax`/`reduce_tax_rate`/`tax_rounding_method`）を渡し、`price`（定価）を税込換算して`CanonicalProduct.price`、
    `sales_price_including_tax`を`sale_price`に載せ替える（定価未設定・定価≦販売価格なら従来どおり）。01 §4 / 03 §9 #16 参照
  - **受注明細のバリエーション解決**: カラーミーの受注明細は親商品IDと`option1_value`/`option2_value`（最新値）しか持たず、
    `ProductResolver`はvariable親への解決を未解決扱いにするため、現状バリエーション商品の明細は全件が商品リンク無しの
    カスタム行（`order_line_product_unresolved`）になる。実店舗では明細の大半がこれに該当する。親の`variant` mappings
    から`option1_name/value`の一致でvariationを特定する経路を追加する（一致しなければ従来どおり未解決＝フェイルクローズ。
    option名変更後の受注は`pristine_product_full_name`のみが注文時の値である点に注意）。F1-7のリンク再構築とも連携
  - **決済/配送マッピング設定**: `GET/PUT /settings/mappings/{platform}`が501のままで、`MethodMap`が読む
    `cbjp_settings_{platform}`を書く経路が無い。受注は全件`payment_method_unmapped`/`shipping_method_unmapped`になる。
    F1-6 PR-B（Import UI）のスコープに含めるか、独立PRにするかを決めて着手する（03 §6のルート定義済み）
- [ ] **F1-6: インポートUI仕上げ**（エンティティ選択→dry-runプレビュー（**CSVダウンロード=D17**）→実行→進捗→結果レポート、Logsタブ。**上限到達時の残件数つきPro案内=D15/§10.3**）。
  着手前調査で「dry-run が実writerの検証ロジックを一切呼ばず警告が常に空」という前提バグが判明したため、
  **PR-A（バックエンド・完了）とPR-B（フロントエンド・未着手）に分割**して進めている（隣接タスクのまとめ方針の応用）。
  - **PR-A（完了）**: `Woo\Writer\EntityWriter::validate()` を各writer（Term/Stock/Coupon/Customer/Product/Order）に追加し、
    `write()`と参照解決・値検証ロジックを共有（詳細は `03-design-decisions.md` §10.4「dry-runレポートCSVの実装詳細」）。
    `Woo\DryRunRepository`（validate()のみ呼び何も永続化しない）+ `cbjp_dry_run_items` テーブル + `Sync\DryRunItemRepository`
    + `Admin\DryRunReportCsv` + `GET /runs/{run_id}/report` を実装。ユニットテストのみで検証が閉じ、
    `composer lint && composer analyze && composer test:wpenv` 通過済み（589テスト）。TermWriterは`term_exists()`による
    事前衝突判定を`write()`にも統合（従来の`wp_insert_term()`エラー依存から変更。既存テスト全通過で回帰なしを確認）。
  - **PR-B（未着手）**: React Import タブ（エンティティ選択・dry-runプレビュー・CSVダウンロードリンク・進捗ポーリング・
    結果レポート・Pro案内）と Logs タブ。PR-Aの `GET /runs/{run_id}`・`GET /runs/{run_id}/report`・`GET /limits` を消費するのみ。
- [ ] **F1-7: ツール + 検証レポート**（サンプルクリーンアップ / リンク再構築（`/tools/*` REST + UI、D16）、移行後検証レポート（件数・受注合計金額の突合表示、D17））
- [ ] **F1-8: 実データE2E**（テストショップから商品100件・受注50件規模。中断→再開、再実行の冪等性、**無料版サンプル→上限解除→本移行の重複なし確認（上書きポリシー両方）=D16**、実行時間計測=要検証#6）

---

## Phase 2: Woo → カラーミー エクスポート（v1.0）

> 旧計画の Phase 4（Woo → ASP エクスポート）からカラーミー分を切り出し、v1.0 に前倒し（D18）。
> Exporter パイプライン・マッピングUIは ASP 非依存に作り、v2.0/v3.0 では各アダプタの `push*` 追加と
> capability 分岐（カテゴリ自動作成等）の有効化だけで成立させる。
> 前提: F1-8 完了（インポート側の実データE2Eで Canonical⇔Woo の変換が実データに耐えることを確認済み）。

- [ ] **E2-1: マッピングUI**（カテゴリ: カラーミーは作成不可（`canCreateCategory=false`）のため既存カテゴリ選択のみ。自動作成の分岐点は capability 判定として用意し、実装は v2.0 E5-1 / 決済・配送・注文ステータス対応表。F1-5後続の `GET/PUT /settings/mappings/{platform}` と設定ストア `cbjp_settings_{platform}` を共用）
- [ ] **E2-2: Exporter パイプライン**（Woo→Canonical読出、SKU/email突合upsert、dry-run。**無料版はインポートと同基準のサンプル上限（Woo側の最新受注10件起点）を適用=D15。実行前の本番書込み警告=D17**。`RestController` の `type=export` 501 を解除）
- [ ] **E2-3: ColorMe push\***（商品→顧客→受注→在庫。**要検証#5を確定してから受注実装**。画像は `canPushImages`（`shop.json` の `contract_plan` 依存。03 §9 #1）が true なら `POST /products/{id}/images`、false/403 なら画像URL一覧CSV出力フローへ切替）
- [ ] **E2-4: エクスポートUI + 往復E2E**（Export タブ（エンティティ選択→dry-run→本番書込み警告→実行→進捗→結果レポート）。テストショップへの ColorMe→Woo→ColorMe 往復移行でデータ欠損・冪等性を確認）

**Phase 2 完了チェック**: カラーミーのテストショップに対して dry-run → サンプルエクスポート → 再エクスポート（checksum一致skip・重複ゼロ）が通ること。

---

## Phase 3: v1.0 仕上げ・公開

- [ ] **R3-1: 全件E2Eリハーサル**（カラーミーのテストショップで実データ移行。インポート→エクスポートの往復でデータ欠損確認。**無料版サンプル→上限解除→本移行の重複なし確認（上書きポリシー両方）=D16** を F1-8 の結果と合わせて最終確認）
- [ ] **R3-2: i18n**（POT生成、languages/ja.po 翻訳、make-json。参考スキル: wp-i18n）
- [ ] **R3-3: readme.txt + アセット + 説明文のv1.0化**（スクリーンショット、商標表記: WooCommerce is a trademark of Automattic / ASP名は本文でのみ言及。**プラグインヘッダーと `composer.json` の Description を「Color Me Shop」のみに改める**（現状は3ASP併記。03 §7）。BASE/MakeShop の対応予定を readme に載せるかは公開時に判断）
- [ ] **R3-4: wordpress.org 申請**（スラッグ `cart-bridge-jp`、Plugin Check通過、バージョン 1.0.0。参考スキル: wp-org-release）
- [ ] **R3-5: アンインストールオプションUI + セキュリティ最終監査**（wp-security-check スキル）

> **要判断（v1.0公開前）**: 無料版の上限到達時に表示する Pro 案内（03 §10.3）の導線先として、v1.0 公開と同時に Pro 版を購入可能にするか。
> Pro 版アドオンは別リポジトリ（本ファイル末尾）で、本リポジトリ側の拡張ポイントは Phase 0 で提供済み。

---

## Phase 4: BASE → Woo インポート（v2.0）

> 前提: BASE Developersアプリ登録済み・テストショップあり（D2）。詳細は `04-plan-base.md`。
> BASE固有の制約: 顧客一覧API・注文作成API・クーポンAPIなし。トークンは1時間期限+リフレッシュ30日ローテーション（D13。TokenStore 側の構造化ペイロード・排他ロックは Phase 0 で実装済み）。
> **アーキテクチャ検証**: Importer 本体を変更せずアダプタ追加のみで成立させること（旧計画で MakeShop が担っていた検証観点を引き継ぐ）。
> 旧タスクID `B3-*` を `B4-*` に採番し直した（内容は同じ）。

- [ ] **B4-0: フィクスチャ収集 + 仕様実測**
  - テストショップにサンプルデータ（バリエーション商品・複数カテゴリ商品・各決済の受注・一部発送の受注）を登録
  - items / items/detail / categories / item_categories / orders / orders/detail / users/me の実レスポンスを `tests/fixtures/base/` に保存
  - **要検証#9（redirect_uriのhttps要否）/#10（発送ステータス集約規則）/#11（エラー形式・レート制限挙動）/#12（費用・スコープ承認）と、BASE分の#14/#15（受注ソート・商品ID指定取得）をここで確定** → 03 §9 と Capabilities を更新
- [ ] **B4-1: BaseOAuth**（認可URL生成、callback REST（カラーミーと共通基盤）、**リフレッシュ+ローテーション+排他ロック**、TokenStore統合）+ ユニットテスト
- [ ] **B4-2: BaseClient**（GET/POST、**HTTP 400のレート制限コード判別**（`HttpClient` の `$rate_limit_detector` を使用）、期限切れ時の自動リフレッシュ、RateLimiter統合）+ ユニットテスト
- [ ] **B4-3: 接続ウィザードUI**（client_id/secret入力 → 認可 → users/me接続テスト。code手動貼付フォールバック）
- [ ] **B4-4: Transformer**（Product/Order/Category + **CustomerExtractor**（受注購入者のemail名寄せ=D12）。フィクスチャベースのユニットテスト）
- [ ] **B4-5: BaseAdapter.fetch\* + Importer結合**（カーソル=offset、受注は一覧→詳細の2段取得、dry-run動作確認。`Page::$total` は変換層で行の除外・展開がありうるため 1:1 を保証できなければ null）
- [ ] **B4-6: 実ショップE2E**（商品・受注・顧客抽出の冪等性確認）

---

## Phase 5: Woo → BASE エクスポート + v2.0 公開

- [ ] **E5-1: BASE push\***（カテゴリ自動作成（E2-1 のマッピングUIに `canCreateCategory=true` 分岐を実装、3階層まで・4階層以上は平坦化+警告）→商品upsert→画像add_image(URL方式・**要検証#13**)→在庫edit_stock。**1日1,000件制限の分割実行**（`paused`→翌日再エンキュー）、バリエーション1軸化・絵文字除去のdry-run警告。受注・顧客は対象外（capabilityでUI非表示））
- [ ] **R5-1: v2.0 公開**（BASEテストショップでの往復E2E、readme.txt / プラグインヘッダー / `composer.json` の Description に BASE を追記、ja.po 追補、バージョン 2.0.0、wordpress.org 更新）

---

## Phase 6: MakeShop → Woo インポート（v3.0）

> 前提: 自社利用登録・エンドポイント・永続トークン取得済み（D2）。詳細は `02-plan-makeshop.md`。
> 旧タスクID `M2-*` を `M6-*` に採番し直した（内容は同じ）。

- [ ] **M6-0: フィクスチャ収集 + スキーマ精査**（searchProduct/searchMember/searchOrder/createProduct。**要検証#2/#3/#4/#8と、MakeShop分の#14/#15（受注ソート・ID指定取得）を確定**）
- [ ] **M6-1: GraphQLClient**（Bearer認証、errors[]変換、partial data処理、リトライ、RateLimiter統合）+ ユニットテスト
- [ ] **M6-2: 接続設定UI**（endpoint+token入力、getShop接続テスト）
- [ ] **M6-3: Transformer 4種+**（Product/Member/Order/Category + Coupon/Review。フィクスチャテスト）
- [ ] **M6-4: MakeShopAdapter.fetch\* + Importer結合**（ページング実装、dry-run）
- [ ] **M6-5: 実ショップE2E**

---

## Phase 7: Woo → MakeShop エクスポート + v3.0 公開

- [ ] **E7-1: MakeShop push\***（カテゴリ自動作成→商品→会員→注文(決済なしモード)→在庫）
- [ ] **E7-2: importProductBulk 経路**（MakeShop 1,000商品超向けCSV一括。任意・要検証のCSV仕様確認後）
- [ ] **R7-1: v3.0 公開**（MakeShop実ショップでの往復E2E、readme.txt / プラグインヘッダー / `composer.json` の Description に MakeShop を追記、ja.po 追補、バージョン 3.0.0、wordpress.org 更新）

---

## Pro版アドオン（別リポジトリ・フェーズ番号なし）

> 本リポジトリのスコープ外（無料版に Pro 固有コードを含めない）。無料版側は `cbjp/limits/*` フィルターと
> AdapterRegistry の拡張ポイントを提供するのみ（P0-6 / P0-5 に含む）。継続同期は販売しない（D14）。
> 上限解除はプラットフォーム非依存のため、v2.0/v3.0 のアダプタ追加で Pro 側の変更は不要。

- Pro プラグイン: `cbjp/limits/*` による上限解除、301リダイレクトCSV生成（D17）
- ライセンス統合: WooCommerce API Manager クライアント（アクティベーション・アップデート取得）
- 販売サイト側: WooCommerce API Manager 導入、**有効期限の起点を初回アクティベーション時にするカスタマイズ**（D14/03 §10.1）、適格請求書対応
