# 実装タスク（WBS）

最終更新: 2026-07-06

本ファイルが実装タスクの唯一の管理台帳。各タスクは Opusplan の1セッション（plan → 実装 → 検証）で
完結する粒度に分割してある。

## 進め方（各セッション共通）

1. セッション開始時に `docs/00〜03` と本ファイルの該当タスクを読む
2. `main` から `feat/{タスクID}-{短い説明}` ブランチを作成
3. plan モードで実装計画を立ててから着手
4. **完了条件**: 各タスク記載の成果物 + `composer lint && composer analyze && composer test` が通ること（npm を含むタスクは `npm run lint && npm run build` も）
5. PR 作成（gh コマンド）→ CI 通過 → マージ → 本ファイルのチェックボックスを更新
6. 要検証事項（03 §9）が確定したら 03 と該当計画ドキュメントを更新

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
  - `TokenStore`（sodium暗号化、復号失敗時の再接続要求状態、末尾4桁マスク取得）
  - 各クラスのユニットテスト（HTTPは `pre_http_request` フィルターでモック）

- [ ] **P0-5: Canonicalモデル + アダプタIF**
  - `Canonical\*` 8種（Product/Category/Tag/Customer/Order/Stock/Coupon/Review。readonly、`toArray/fromArray`、checksum算出用の正規化JSON）
  - `Adapters\`: `PlatformAdapter`（03 §2 確定版）、`Capabilities`、`Cursor`、`Page`、`PushResult`、`ConnectionResult`、`ConnectionField`、`UnsupportedOperationException`
  - `AdapterRegistry`（フィルター `cbjp/adapters/register` で登録。Pro拡張ポイント）
  - Canonicalモデルのシリアライズ往復・checksumのユニットテスト

- [ ] **P0-6: Sync層骨格（ジョブ基盤）**
  - `Sync\JobRepository` / `MappingRepository` / `LogRepository`（$wpdb + prepare）
  - `Sync\JobManager`（startRun、ステートマシン、Action Schedulerエンキュー、1アクション=1ページのループ、エンティティ直列実行、同時実行ガード）
  - `Sync\Importer`（fetch→変換→書込のパイプライン。書込先は `WooWriter` IFにし、`DryRunReporter` と差し替え可能に）
  - ログ30日保持の日次クリーンアップ
  - モックアダプタ（テスト用フィクスチャを返すだけ）でジョブが完走・再開できるユニットテスト

- [ ] **P0-7: 管理画面骨格 + REST骨格**
  - `Admin\Menu`（WooCommerce配下にページ登録）、`Admin\Assets`
  - `Admin\RestController`（03 §6 のルート定義。connections/runs/logs は P0-6 のリポジトリと接続、未実装部分は501）
  - React アプリ骨格: タブ4つ（Connections/Import/Export/Logs）、api-fetch セットアップ、Connections タブは AdapterRegistry 由来の一覧を表示（アダプタ0件の空状態）
  - i18n: `wp_set_script_translations` 設定

**Phase 0 完了チェック**: モックアダプタを登録すると管理画面に接続カードが出て、
ダミーインポートの run が開始→進捗ポーリング→完了まで通ること。

---

## Phase 1: カラーミー → Woo インポート（MVP）

> 前提: デベロッパー登録・テストショップ・アプリ登録済み（D2）。詳細は `01-plan-colorme.md`。

- [ ] **F1-0: フィクスチャ収集 + swagger精査**
  - `swagger.json` を `tests/fixtures/colorme/` に保存し、商品/受注/顧客スキーマを精査
  - **要検証#1（画像書き込み可否）をここで確定** → 03 §9 と Capabilities を更新
  - テストショップにサンプルデータ（バリエーション商品・オプション商品・法人顧客・各決済の受注）を登録し、各エンドポイントの実レスポンスJSONをフィクスチャ保存
- [ ] **F1-1: ColorMeClient**（GET/POST/PUT、errors[]→ApiException、RateLimiter統合）+ ユニットテスト
- [ ] **F1-2: ColorMeOAuth + 接続ウィザードUI**（認可URL生成、callback REST、state検証、code手動貼付フォールバック、shop.json接続テスト。**要検証#7を確定**）
- [ ] **F1-3: Transformer 4種+**（Product/Customer/Order/Category + Tag(groups)/Coupon読取。フィクスチャベースのユニットテスト。マッピング表は 01 §4）
- [ ] **F1-4: WooRepository**（商品/カテゴリ/タグ/顧客/受注/在庫のupsert書込。画像sideload、受注は 03 §5 の詳細仕様・HPOS対応CRUDのみ使用）+ テスト
- [ ] **F1-5: ColorMeAdapter.fetch\* + Importer結合**（カーソル=offset、dry-run動作確認）
- [ ] **F1-6: インポートUI仕上げ**(エンティティ選択→dry-runプレビュー→実行→進捗→結果レポート、Logsタブ)
- [ ] **F1-7: 実データE2E**（テストショップから商品100件・受注50件規模。中断→再開、再実行の冪等性、実行時間計測=要検証#6）

---

## Phase 2: MakeShop → Woo インポート

> 前提: 自社利用登録・エンドポイント・永続トークン取得済み（D2）。詳細は `02-plan-makeshop.md`。
> アーキテクチャ検証: Importer本体を変更せずアダプタ追加のみで成立させること。

- [ ] **M2-0: フィクスチャ収集 + スキーマ精査**（searchProduct/searchMember/searchOrder/createProduct。**要検証#2/#3/#4/#8を確定**）
- [ ] **M2-1: GraphQLClient**（Bearer認証、errors[]変換、partial data処理、リトライ、RateLimiter統合）+ ユニットテスト
- [ ] **M2-2: 接続設定UI**（endpoint+token入力、getShop接続テスト）
- [ ] **M2-3: Transformer 4種+**（Product/Member/Order/Category + Coupon/Review。フィクスチャテスト）
- [ ] **M2-4: MakeShopAdapter.fetch\* + Importer結合**（ページング実装、dry-run）
- [ ] **M2-5: 実ショップE2E**

---

## Phase 3: Woo → ASP エクスポート

- [ ] **E3-1: マッピングUI**（カテゴリ: カラーミーは既存選択・MakeShopは自動作成 / 決済・配送・注文ステータス対応表）
- [ ] **E3-2: Exporter パイプライン**（Woo→Canonical読出、SKU/email突合upsert、dry-run）
- [ ] **E3-3: ColorMe push\***（商品→顧客→受注→在庫。**要検証#5を確定してから受注実装**。画像不可なら画像URL一覧CSV出力フロー）
- [ ] **E3-4: MakeShop push\***（カテゴリ自動作成→商品→会員→注文(決済なしモード)→在庫）
- [ ] **E3-5: importProductBulk 経路**（1,000商品超向けCSV一括。任意・要検証のCSV仕様確認後）

---

## Phase 4: 仕上げ・公開

- [ ] **R4-1: 全件E2Eリハーサル**（両ASPのテストショップで実データ移行、往復移行のデータ欠損確認）
- [ ] **R4-2: i18n**（POT生成、languages/ja.po 翻訳、make-json。参考スキル: wp-i18n）
- [ ] **R4-3: readme.txt + アセット**（スクリーンショット、商標表記: WooCommerce is a trademark of Automattic / ASP名は本文でのみ言及）
- [ ] **R4-4: wordpress.org 申請**（スラッグ `cart-bridge-jp`、Plugin Check通過。参考スキル: wp-org-release）
- [ ] **R4-5: アンインストールオプションUI + セキュリティ最終監査**（wp-security-check スキル）

---

## Phase 5（Pro・将来、設計のみ）

- 無料版には `cbjp/sync/*` フックのみ用意（P0-6 の JobManager 実装時に拡張ポイントを設けること）
