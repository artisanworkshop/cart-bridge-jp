# 実装タスク（WBS）

最終更新: 2026-07-08

本ファイルが実装タスクの唯一の管理台帳。各タスクは Opusplan の1セッション（plan → 実装 → 検証）で
完結する粒度に分割してある。

## 進め方（各セッション共通）

1. セッション開始時に `docs/00〜03` と本ファイルの該当タスクを読む
2. `main` から `feat/{タスクID}-{短い説明}` ブランチを作成
3. plan モードで実装計画を立ててから着手
4. **完了条件**: 各タスク記載の成果物 + `composer lint && composer analyze && composer test` が通ること（npm を含むタスクは `npm run lint && npm run build` も）
5. PR 作成（gh コマンド）→ CI 通過 → マージ → 本ファイルのチェックボックスを更新
6. 要検証事項（03 §9）が確定したら 03 と該当計画ドキュメントを更新
7. フィクスチャ収集タスク（F1-0 / M2-0 / B3-0）では、コミット前に必ず
   `tests/fixtures/README.md` の匿名化ルールを適用する（public リポジトリのため個人情報厳禁）

---

## Phase 0: 基盤

> ゴール: アダプタを1つも持たない状態で、テーブル・IF・Support層・ジョブ骨格・管理画面骨格・CIが揃い、
> Phase 1 が「ColorMe ディレクトリを足すだけ」で始められる状態。

- [ ] **P0-1: リポジトリ整備 + プラグインスケルトン**
  - `trunk` ブランチを `main` に統合し、以後 main をデフォルトに（`git branch -m` + `gh repo edit --default-branch main` 等）
  - `.gitignore`（node_modules, vendor, build, .wp-env.override.json 等）、`.editorconfig`
  - `cart-bridge-jp.php`（03 §7 のヘッダー、WooCommerce有効チェック、HPOS互換宣言）
  - `composer.json`（PSR-4: `CartBridgeJP\` → `includes/`）、`Core\Plugin`（シングルトン起動）、`Core\Activator`（03 §3 のDDLをdbDeltaで作成、DBバージョン管理）、`uninstall.php`
  - 成果物: wp-env上で有効化でき、3テーブルが作成される

- [ ] **P0-2: 開発環境 + 品質ツール**
  - `.wp-env.json`（PHP 8.1、WooCommerce同梱、testsインスタンス）
  - `phpcs.xml.dist`（WordPress ruleset、PSR-4クラス名許容の調整）→ `composer lint`
  - `phpstan.neon.dist`（level 6、wordpress/woocommerceスタブ）→ `composer analyze`
  - PHPUnit（wp-env のtestsインスタンスで実行する bootstrap）→ `composer test`、Activatorのテーブル作成テスト1本
  - `package.json` + `@wordpress/scripts` + TypeScript設定、空のエントリポイントがビルドできること
  - 参考スキル: wp-phpcs / wp-phpstan / wp-phpunit

- [ ] **P0-3: GitHub Actions CI**
  - 03 §8 の3ジョブ（php-quality マトリクス / php-test / js）
  - main保護（PR必須・CI必須）の設定
  - 参考スキル: wp-github-actions

- [ ] **P0-4: Support層**
  - `Logger`（cbjp_logsへの書込 + WC_Loggerミラー。個人情報禁止ルールをdocblockに明記）
  - `HttpClient`（リトライ+指数バックオフ、Retry-After対応、ApiException）
  - `RateLimiter`（トークンバケット、$wpdbによる原子的更新）
  - `TokenStore`（sodium暗号化、**構造化ペイロード access/refresh/expires_at + リフレッシュ排他ロック=D13**、復号失敗・refresh失効時の再接続要求状態、末尾4桁マスク取得）
  - 各クラスのユニットテスト（HTTPは `pre_http_request` フィルターでモック）

- [ ] **P0-5: Canonicalモデル + アダプタIF**
  - `Canonical\*` 8種（Product/Category/Tag/Customer/Order/Stock/Coupon/Review。readonly、`toArray/fromArray`、checksum算出用の正規化JSON）
  - `Adapters\`: `PlatformAdapter`（03 §2 確定版。**サンプル選定用の `fetchLatestOrders` / `fetchProductByRemoteId` / `fetchCustomerByRemoteId` を含む=D15**）、`Capabilities`（`canFetchCustomers` 含む）、`Cursor`、`Page`、`PushResult`、`ConnectionResult`、`ConnectionField`、`UnsupportedOperationException`
  - `AdapterRegistry`（フィルター `cbjp/adapters/register` で登録。Pro拡張ポイント）
  - Canonicalモデルのシリアライズ往復・checksumのユニットテスト

- [ ] **P0-6: Sync層骨格（ジョブ基盤）**
  - `Sync\JobRepository` / `MappingRepository` / `LogRepository`（$wpdb + prepare）
  - `Sync\JobManager`（startRun、ステートマシン、Action Schedulerエンキュー、1アクション=1ページのループ、エンティティ直列実行、同時実行ガード）
  - `Sync\Importer`（fetch→変換→書込のパイプライン。書込先は `WooWriter` IFにし、`DryRunReporter` と差し替え可能に）
  - **`Sync\LimitPolicy` + `Sync\SampleSelector`（D15/03 §10.2）**: `cbjp/limits/{entity}` フィルター、mappings累積カウントによるサーバーサイド強制、サンプルセットの保存（`cbjp_sample_{platform}`）とフォールバック規則
  - ログ30日保持の日次クリーンアップ
  - モックアダプタ（テスト用フィクスチャを返すだけ）でジョブが完走・再開できるユニットテスト（**上限強制・サンプル選定・フォールバックのテスト含む**）

- [ ] **P0-7: 管理画面骨格 + REST骨格**
  - `Admin\Menu`（WooCommerce配下にページ登録）、`Admin\Assets`
  - `Admin\RestController`（03 §6 のルート定義。connections/runs/logs/limits は P0-6 のリポジトリと接続、未実装部分は501）
  - React アプリ骨格: タブ4つ（Connections/Import/Export/Logs）、api-fetch セットアップ、Connections タブは AdapterRegistry 由来の一覧を表示（アダプタ0件の空状態）
  - i18n: `wp_set_script_translations` 設定

**Phase 0 完了チェック**: モックアダプタを登録すると管理画面に接続カードが出て、
ダミーインポートの run が開始→進捗ポーリング→完了まで通ること。

---

## Phase 1: カラーミー → Woo インポート（MVP）

> 前提: デベロッパー登録・テストショップ・アプリ登録済み（D2）。詳細は `01-plan-colorme.md`。

- [ ] **F1-0: フィクスチャ収集 + swagger精査**
  - `swagger.json` を `tests/fixtures/colorme/` に保存し、商品/受注/顧客スキーマを精査
  - **要検証#1（画像書き込み可否）/#14（受注の新しい順ソート）/#15（商品・顧客のID指定取得）をここで確定** → 03 §9 と Capabilities を更新
  - テストショップにサンプルデータ（バリエーション商品・オプション商品・法人顧客・各決済の受注）を登録し、各エンドポイントの実レスポンスJSONをフィクスチャ保存
- [ ] **F1-1: ColorMeClient**（GET/POST/PUT、errors[]→ApiException、RateLimiter統合）+ ユニットテスト
- [ ] **F1-2: ColorMeOAuth + 接続ウィザードUI**（認可URL生成、callback REST、state検証、code手動貼付フォールバック、shop.json接続テスト。**要検証#7を確定**）
- [ ] **F1-3: Transformer 4種+**（Product/Customer/Order/Category + Tag(groups)/Coupon読取。フィクスチャベースのユニットテスト。マッピング表は 01 §4）
- [ ] **F1-4: WooRepository**（商品/カテゴリ/タグ/顧客/受注/在庫のupsert書込。画像sideload、受注は 03 §5 の詳細仕様・HPOS対応CRUDのみ使用）+ テスト
- [ ] **F1-5: ColorMeAdapter.fetch\* + Importer結合**（カーソル=offset、`fetchLatestOrders`/ID指定取得含む、dry-run + **サンプル選定〜上限強制の実機確認=D15**）
- [ ] **F1-6: インポートUI仕上げ**（エンティティ選択→dry-runプレビュー（**CSVダウンロード=D17**）→実行→進捗→結果レポート、Logsタブ。**上限到達時の残件数つきPro案内=D15/§10.3**）
- [ ] **F1-7: ツール + 検証レポート**（サンプルクリーンアップ / リンク再構築（`/tools/*` REST + UI、D16）、移行後検証レポート（件数・受注合計金額の突合表示、D17））
- [ ] **F1-8: 実データE2E**（テストショップから商品100件・受注50件規模。中断→再開、再実行の冪等性、**無料版サンプル→上限解除→本移行の重複なし確認（上書きポリシー両方）=D16**、実行時間計測=要検証#6）

---

## Phase 2: MakeShop → Woo インポート

> 前提: 自社利用登録・エンドポイント・永続トークン取得済み（D2）。詳細は `02-plan-makeshop.md`。
> アーキテクチャ検証: Importer本体を変更せずアダプタ追加のみで成立させること。

- [ ] **M2-0: フィクスチャ収集 + スキーマ精査**（searchProduct/searchMember/searchOrder/createProduct。**要検証#2/#3/#4/#8と、MakeShop分の#14/#15（受注ソート・ID指定取得）を確定**）
- [ ] **M2-1: GraphQLClient**（Bearer認証、errors[]変換、partial data処理、リトライ、RateLimiter統合）+ ユニットテスト
- [ ] **M2-2: 接続設定UI**（endpoint+token入力、getShop接続テスト）
- [ ] **M2-3: Transformer 4種+**（Product/Member/Order/Category + Coupon/Review。フィクスチャテスト）
- [ ] **M2-4: MakeShopAdapter.fetch\* + Importer結合**（ページング実装、dry-run）
- [ ] **M2-5: 実ショップE2E**

---

## Phase 3: BASE → Woo インポート

> 前提: BASE Developersアプリ登録済み・テストショップあり（D2）。詳細は `04-plan-base.md`。
> BASE固有の制約: 顧客一覧API・注文作成API・クーポンAPIなし。トークンは1時間期限+リフレッシュ30日ローテーション（D13）。

- [ ] **B3-0: フィクスチャ収集 + 仕様実測**
  - テストショップにサンプルデータ（バリエーション商品・複数カテゴリ商品・各決済の受注・一部発送の受注）を登録
  - items / items/detail / categories / item_categories / orders / orders/detail / users/me の実レスポンスを `tests/fixtures/base/` に保存
  - **要検証#9（redirect_uriのhttps要否）/#10（発送ステータス集約規則）/#11（エラー形式・レート制限挙動）/#12（費用・スコープ承認）と、BASE分の#14/#15（受注ソート・商品ID指定取得）をここで確定** → 03 §9 と Capabilities を更新
- [ ] **B3-1: BaseOAuth**（認可URL生成、callback REST（カラーミーと共通基盤）、**リフレッシュ+ローテーション+排他ロック**、TokenStore統合）+ ユニットテスト
- [ ] **B3-2: BaseClient**（GET/POST、**HTTP 400のレート制限コード判別**、期限切れ時の自動リフレッシュ、RateLimiter統合）+ ユニットテスト
- [ ] **B3-3: 接続ウィザードUI**（client_id/secret入力 → 認可 → users/me接続テスト。code手動貼付フォールバック）
- [ ] **B3-4: Transformer**（Product/Order/Category + **CustomerExtractor**（受注購入者のemail名寄せ=D12）。フィクスチャベースのユニットテスト）
- [ ] **B3-5: BaseAdapter.fetch\* + Importer結合**（カーソル=offset、受注は一覧→詳細の2段取得、dry-run動作確認）
- [ ] **B3-6: 実ショップE2E**（商品・受注・顧客抽出の冪等性確認）

---

## Phase 4: Woo → ASP エクスポート

- [ ] **E4-1: マッピングUI**（カテゴリ: カラーミーは既存選択・MakeShop/BASEは自動作成（BASEは3階層まで） / 決済・配送・注文ステータス対応表）
- [ ] **E4-2: Exporter パイプライン**（Woo→Canonical読出、SKU/email突合upsert、dry-run。**無料版はインポートと同基準のサンプル上限（Woo側の最新受注10件起点）を適用=D15。実行前の本番書込み警告=D17**）
- [ ] **E4-3: ColorMe push\***（商品→顧客→受注→在庫。**要検証#5を確定してから受注実装**。画像不可なら画像URL一覧CSV出力フロー）
- [ ] **E4-4: MakeShop push\***（カテゴリ自動作成→商品→会員→注文(決済なしモード)→在庫）
- [ ] **E4-5: BASE push\***（カテゴリ自動作成→商品upsert→画像add_image(URL方式・**要検証#13**)→在庫edit_stock。**1日1,000件制限の分割実行**、バリエーション1軸化・絵文字除去のdry-run警告。受注・顧客は対象外）
- [ ] **E4-6: importProductBulk 経路**（MakeShop 1,000商品超向けCSV一括。任意・要検証のCSV仕様確認後）

---

## Phase 5: 仕上げ・公開

- [ ] **R5-1: 全件E2Eリハーサル**（3ASPのテストショップで実データ移行、往復移行のデータ欠損確認）
- [ ] **R5-2: i18n**（POT生成、languages/ja.po 翻訳、make-json。参考スキル: wp-i18n）
- [ ] **R5-3: readme.txt + アセット**（スクリーンショット、商標表記: WooCommerce is a trademark of Automattic / ASP名は本文でのみ言及）
- [ ] **R5-4: wordpress.org 申請**（スラッグ `cart-bridge-jp`、Plugin Check通過。参考スキル: wp-org-release）
- [ ] **R5-5: アンインストールオプションUI + セキュリティ最終監査**（wp-security-check スキル）

---

## Phase 6（Pro版アドオン・別リポジトリ）

> 本リポジトリのスコープ外（無料版に Pro 固有コードを含めない）。無料版側は `cbjp/limits/*` フィルターと
> AdapterRegistry の拡張ポイントを提供するのみ（P0-6 / P0-5 に含む）。継続同期は販売しない（D14）。

- Pro プラグイン: `cbjp/limits/*` による上限解除、301リダイレクトCSV生成（D17）
- ライセンス統合: WooCommerce API Manager クライアント（アクティベーション・アップデート取得）
- 販売サイト側: WooCommerce API Manager 導入、**有効期限の起点を初回アクティベーション時にするカスタマイズ**（D14/03 §10.1）、適格請求書対応
