# 実装タスク（WBS）

最終更新: 2026-09-24

本ファイルが実装タスクの唯一の管理台帳。各タスクは Opusplan の1セッション（plan → 実装 → 検証）で
完結する粒度に分割してある。

## リリース計画（D18・2026-09-05改訂）

| バージョン | 対応プラットフォーム | フェーズ | 状態 |
|---|---|---|---|
| **v1.0** | カラーミーショップ（インポート＋エクスポート） | Phase 0〜3 | Phase 1 完了（F1-8 実店舗2件でのインポート実データE2E完了、持ち越し事項あり。F1-6 完了時点を `v0.1.0` として GitHub Release で実サイト検証中）。Phase 2 完了: E2-1〜E2-4 完了（`push_product`/`push_customer`/`push_order`/`push_stock`、#43〜#45・#47、Export タブ実行フロー）。実店舗へのエクスポート（R3-1）の前に県コード修復（#46）が必要 |
| **v2.0** | + BASE（インポート＋エクスポート※）＋ OAuth中継サーバー（案B「かんたん接続」）の採否判断（B4-7） | Phase 4〜5 | 未着手（v1.0 公開後） |
| **v3.0** | + MakeShop（インポート＋エクスポート） | Phase 6〜7 | 未着手（v2.0 公開後） |
| Pro版アドオン | 無料版上限の解除（プラットフォーム非依存） | — | 別リポジトリ |

※ BASE のエクスポートは API 制約により商品・カテゴリ・在庫のみ（受注作成・顧客APIなし）。

旧計画（0基盤→1カラーミー→2MakeShop→3BASE→4エクスポート→5公開、v1.0はBASE込み）からの変更点:
MakeShop/BASE のインポートを v1.0 から外し、カラーミーのエクスポートを v1.0 に前倒し。BASE と MakeShop の
順序を入れ替え（BASE→MakeShop）。タスクIDは新フェーズ番号で採番し直した（旧 `M2-*`→`M6-*`、
旧 `B3-*`→`B4-*`、旧 `E4-1/2/3`→`E2-1/2/3`、旧 `E4-5`→`E5-1`、旧 `E4-4/6`→`E7-1/2`、旧 `R5-*`→`R3-*`）。
いずれも未着手だったため実装・PRへの影響なし。コード内コメントに残っていた旧ID（`ColorMeAdapter` の `E4-3`、
`RestController` の `E4-2`）はE2-1着手時に修正済み。
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
- [x] **fix: 管理画面をWordPress標準デザインのタブUIに修正**（2026-09-04、PR #21 / issue #20）
  `src/` にCSSが1つも無く `wp-scripts build` がCSSを出力しないため `Assets.php` の `file_exists()` ガードが常に失敗し、
  `wp-components` のコアCSSも含めて一切enqueueされていなかった。`src/style.css` を追加、タブをコア標準の
  `nav-tab-wrapper`/`nav-tab` クラスに変更、enqueue対象パスを `build/style-index.css` に修正
- [x] **fix: 受注明細の税抜単価欠損を0円に丸めず税分離フォールバックへ乗せる**（2026-09-04、PR #22 / issue #14）
  `Cast::money_or_null()` を追加し `OrderTransformer` の `unit_price_excl_tax` に適用。欠損時は `null` を透過して
  `OrderItemBuilder::split_line_amount()` の既存フォールバック（税込→税抜コピー＋`ORDER_TAX_SPLIT_UNAVAILABLE` 警告）を発火させる
- [x] **chore: GitHub Release ワークフロー + `v0.1.0` リリース**（2026-09-07、PR #31）
  `.github/workflows/release.yml`（`v*.*.*` タグpushで `composer install --no-dev` + `npm run build` → `.distignore` に従いzip化 →
  GitHub Releaseに添付）と `.distignore` を追加し、タグ `v0.1.0` で初回リリースを作成。wordpress.org 公開前に実サイト
  （非wp-env）で動作確認するための配布経路。`vendor/`（PSR-4オートローダー）はzipに同梱する。`readme.txt` 由来の
  changelog抽出は R3-3 で `readme.txt` を作るまで見送り（`generate_release_notes` で代替）。
  あわせて `.wp-env.json` にデバッグ用プラグイン（wp-mail-logging / plugin-check / debug-bar）を追加（953d8f0）
- [x] **fix: 接続設定のplatformパラメータ安全化と保存済みフィールドのUX改善**（2026-09-10、PR #33 / issue #32）
  `v0.1.0` の実サイト確認で「client_id/secretを保存しても空欄に見える」報告を受けて調査。接続系REST 6箇所
  （`save_connection`/`delete_connection`/`test_connection`/`get_authorize_url`/`handle_oauth_callback`/`exchange_code`）の
  `platform` を `get_url_params()` 経由の `platform_param()` に統一（issue #27 指摘の横展開漏れ。回帰テスト2件追加）。
  保存済み資格情報をAPIが平文で返さない設計は維持し、`ConnectionCard` に「保存済み。変更時のみ入力」の案内Noticeを追加
- [x] **fix: `CouponWriter` のクーポン制限判定をプラットフォーム非依存化**（2026-09-12、issue #15）
  `extras['group_limit_type']` というColorMe固有キー・enum値で判定しており、他ASPが別キーで同じ概念を表すと
  フェイルクローズが効かず制限付きクーポンが無制限クーポンとして保存されうる状態だった。`CanonicalCoupon` に
  三値の `has_unsupported_restrictions`（`?bool`）を最終引数として追加（外部アダプタの位置引数互換のため
  既存引数より後ろ）し、判定を各アダプタのTransformerの責務に移した。`null`（アダプタが宣言していない）も
  「不明」として保存を見送るフェイルクローズにしており、楽観的デフォルトによる回避を塞いでいる。
  警告コードは `coupon_group_limit_unsupported` → `coupon_restrictions_unsupported` に改名し、
  未宣言用に `coupon_restrictions_unknown` を新設。ColorMeの `CouponTransformer` は従来どおり
  制限付きクーポンを変換段階で除外するため v1.0 の挙動は不変（`false` を明示宣言するのみ）。
  **checksum への影響**: `to_array()` にキーが増えるため既存クーポンの checksum が変わり、
  アップグレード後の初回インポートで既取込みクーポンが1度だけ再書き込みされる（マイグレーション不要）。
  ASP側に変更が無くても全件が対象になり、`CouponWriter::prepare()` が金額・期限・説明等を一括で
  set し直すため、移行後に店舗がWoo側で手直ししたクーポンは1度だけ巻き戻る（D16の既定＝上書きの
  範囲内）。初回 dry-run では差分ゼロでも全クーポンが `updated` として並ぶ。
  なお checksum を安定させるために新フィールドを `to_array()` から外す案は採らない
  （`false`→`true` の反転を checksum 一致スキップが握り潰し、制限が付いたクーポンを
  無制限のまま残してしまうため）。
  M6-3（クーポンAPIを持つ次のアダプタ）を待たず v1.0 公開前に対応した理由は、公開後だと判定根拠の移行自体が
  外部アダプタ向けの挙動契約の変更になり、checksum 変化も利用者に及ぶため
- [x] **fix: 県コード修正（PR #44）前にインポート済みの顧客・受注の都道府県を是正する「県コード修復ツール」**（2026-09-20、issue #46）
  E2-3 PR-B（#44）で判明した ColorMe `pref_id` と JIS/Woo `JPxx` の不一致（**23 県**。当初「約20」と記載していたのを訂正）は、コード側は修正済みだが
  修正前（`v0.1.0` 〜 2026-09-15）にインポートしたデータの `state` が誤ったまま残っていた。再インポートは checksum 一致スキップで直らず、Woo 側には
  生の `pref_id` が残らない（顧客・受注請求先）ため、Tools タブに「Repair prefecture data」を追加: ASP から権威の `pref_id` を単一ID取得
  （`PlatformAdapter::fetch_order_by_remote_id()` を追加。#38 と共用可）し、現在の `state` が旧バグの出力（`JP{pref_id}`）と一致し、かつ郵便番号・番地が
  ASP と一致する場合に限り `state` のみを補正する（`Woo\Tools\PrefStateRepair`、`GET/POST /tools/repair-states`）。Scan（読取専用）→ Repair の2段階、
  冪等、所有権・スタッフ・ゴミ箱の受注はスキップ、ASP 障害時は処理済み件数と再開用 cursor を返して中断。実店舗（F1-8 の2店舗・`v0.1.0` 試用サイト）での
  実行と `v0.1.1` の要否は別途判断。実 API での確認は ColorMe 認証情報待ち（要検証#18）。詳細は `docs/03-design-decisions.md` §10.3「県コード修復ツール」
- [x] **fix: `JobManager::retry()` にプラットフォーム単位の同時実行ガードを追加**（2026-09-23、issue #54）
  E2-4（PR #53）のCodex/Copilotゲートで判明: `start_run()` は `has_active_job_for_platform()` で同一プラットフォームの
  同時実行を防ぐが、`retry()` はこのガードを経由せず失敗ジョブをrequeueしていたため、別タブ/別セッションで別runが
  進行中でも失敗ジョブのRetryが素通りし、同一ASPへの二重書き込みを招きうる状態だった。`JobRepository::has_active_job_for_platform_excluding_run()`
  を新設（対象ジョブ自身の `run_id` を判定から除外し、`start_run()` がrun開始時に作る未処理な兄弟ジョブを「進行中の別run」と
  誤検知しないようにする）し、`retry()` に適用。ガードに引っかかった場合は既存の `RunAlreadyInProgressException`/
  `run_in_progress_error()`（409）を再利用する。フロントエンドの同型ギャップ（`ImportTab.tsx` の `retryDisabled` が
  他run種別のbusyのみを見て自分の種別の`starting`を見ていない。`docs/review-backlog.md` `e2-4-export-ui-e2e/G1-codex-import-tab-same-gap`）
  も同時に解消。`cancel_run()` の同種の競合（`f1-6-import-ui/R1-X1`）は根本原因（Action Scheduler側の実行中断不可）が
  異なるため本fixのスコープには含めず、別issueのまま残した
- [x] **refactor: `PlatformAdapter` の外部互換ポリシー（D20）**（2026-09-24、issue #49）
  PR #39/#45/#48でCodex/Copilotから繰り返し指摘されていた「インターフェースへのメソッド追加が外部実装を
  fatalにしうる」問題に方針を確定。新設 `Adapters\AbstractPlatformAdapter`（空の抽象クラス）を外部
  （Pro版・サードパーティ）アダプタが継承すべき基底とし、`ColorMeAdapter`/`MockPlatformAdapter`をそれへ移行。
  v1.0.0公開前は従来どおりインターフェースへの追加・変更を許容し、公開後は既存シグネチャを変えず新メソッドは
  基底クラスに既定実装を添えて追加する、という2段階のルールを`docs/03-design-decisions.md` §2「外部互換
  ポリシー」とCLAUDE.md原則8に明記。`tests/unit/Adapters/AbstractPlatformAdapterTest`（リフレクションで
  未実装メソッド一覧とシグネチャをBASELINE定数と照合する契約テスト）でCI上強制する。PR #48の該当2スレッド
  （`fetch_order_by_remote_id()`追加への指摘）はこの決定を根拠にResolve
- [x] **fix: エクスポート価格の税込正規化とバリエーションのセール価格**（2026-09-24、issue #59 / #60）
  E2-3 PR-A のゲートで「差分範囲外・要判断」として保留していた High 2件（`docs/review-backlog.md`
  `e2-3-push-product/G1-out-of-scope-prices-include-tax` / `G2-variation-sale-price`）を v1.0 公開前に解消。
  (1) 税計算ON・税抜入力（`woocommerce_prices_include_tax=no`）の店舗で、`ProductReader` が税抜価格を税込として
  ColorMe へ渡し売価が税分だけ低くなる問題: 新設 `Woo\Support\TaxInclusivePrice` が店舗の基準所在地の税率
  （`WC_Tax::get_base_tax_rates()`）で税込へ換算する。`wc_get_price_including_tax()` は顧客ロケーションが
  空の文脈（WP-CLI・Action Scheduler）では無変換で返すため使わない。税率登録済みだが基準所在地に合致しない
  場合はフェイルクローズ（`PRICE_TAX_BASIS_UNRESOLVED`、blocking）。税計算OFF（WC の既定）は無変換で正しく、
  旧仕様の `PRICES_INCLUDE_TAX_DISABLED` は Reader では出さない（誤検知だった。換算適用時のみ情報警告
  `PRICES_CONVERTED_TO_TAX_INCLUSIVE`）。(2) バリエーションのセール価格: セール中のバリエーションにだけ
  `variants[].sale_price` を載せ、`push_variant_details()` が `option_price`＝実売価格・`option_market_price`＝
  通常価格で送る。詳細は `docs/03-design-decisions.md` §10.2「価格の税込正規化とバリエーションのセール価格」。
  インポート方向（`ProductWriter` が税込額を税抜入力の店舗へ書く鏡像）は対象外で警告のみのまま
  （`e2-2-exporter-core/G3-M-tax-basis-conversion` の残り）

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
- [x] **F1-5後続: 実機確認で判明した改善**（F1-8着手前に実施。単独で動作確認可能な単位ごとにPR）
  - [x] **定価→`regular_price`マッピング**（要検証#16確定分の実装）: `ProductTransformer`に`shop.json`の税設定
    （`tax_type`/`tax`/`reduce_tax_rate`/`tax_rounding_method`）を渡し、`price`（定価）を税込換算して`CanonicalProduct.price`、
    `sales_price_including_tax`を`sale_price`に載せ替える（定価未設定・定価≦販売価格なら従来どおり）。01 §4 / 03 §9 #16 参照
  - [x] **受注明細のバリエーション解決**: `ProductResolver::resolve_by_sku_or_remote_id()`がremote_idで親のvariable商品に
    解決した場合、`option1_value_current`/`option2_value_current`（明細の「最新の商品情報」）を親の`is_variation()`属性
    （軸の並びはoption1→option2の順、`ProductWriter::build_attributes()`と同じ規約）と全軸一致で照合し、
    一意に特定できたvariationへ解決するように変更（`OrderItemBuilder`が明細からこの2値を渡す）。一致が0件・複数件
    （値欠損・重複データ等で一意に特定できない）の場合は従来どおり未解決＝フェイルクローズのまま
    （`order_line_product_unresolved`。option名変更後の受注は`pristine_product_full_name`のみが注文時の値である点は
    未解決のまま残る既知の限界）。F1-7のリンク再構築とも連携
  - [x] **決済/配送マッピング設定**: `GET/PUT /settings/mappings/{platform}`を実装し、`MethodMap`が読む
    `cbjp_settings_{platform}`オプション（`payment_map`/`shipping_map`/`status_map`）を読み書きできるようにした
    （バックエンドのみの独立PRとして実装。UI配線はF1-6 PR-Bのスコープ）。PUTはトップレベルの3キーを
    それぞれ全置換し、省略したキーは既存値を保持する（UIが一部のマップだけ編集しても他方を消さないため）。
    値はWooゲートウェイID/配送方法インスタンスID（`flat_rate:5`等コロンを含みうる）という不透明な内部IDのため、
    `sanitize_key()`ではなく制御文字除去のみで保存する（`save_connection()`の資格情報と同じ方針）
- [x] **F1-6: インポートUI仕上げ**（エンティティ選択→dry-runプレビュー（**CSVダウンロード=D17**）→実行→進捗→結果レポート、Logsタブ。**上限到達時の残件数つきPro案内=D15/§10.3**）。
  着手前調査で「dry-run が実writerの検証ロジックを一切呼ばず警告が常に空」という前提バグが判明したため、
  **PR-A（バックエンド）とPR-B（フロントエンド）に分割**して進めた（隣接タスクのまとめ方針の応用）。
  - **PR-A（完了、PR #17・2026-09-02）**: `Woo\Writer\EntityWriter::validate()` を各writer（Term/Stock/Coupon/Customer/Product/Order）に追加し、
    `write()`と参照解決・値検証ロジックを共有（詳細は `03-design-decisions.md` §10.4「dry-runレポートCSVの実装詳細」）。
    `Woo\DryRunRepository`（validate()のみ呼び何も永続化しない）+ `cbjp_dry_run_items` テーブル + `Sync\DryRunItemRepository`
    + `Admin\DryRunReportCsv` + `GET /runs/{run_id}/report` を実装。ユニットテストのみで検証が閉じ、
    `composer lint && composer analyze && composer test:wpenv` 通過済み（589テスト）。TermWriterは`term_exists()`による
    事前衝突判定を`write()`にも統合（従来の`wp_insert_term()`エラー依存から変更。既存テスト全通過で回帰なしを確認）。
  - **PR-B（完了、PR #30・2026-09-07）**: React Import タブ（`src/tabs/ImportTab.tsx`。接続済みプラットフォーム選択、
    capability連動のエンティティ選択、Preview（dry-run・無制限）/ Run import（実書込み前の確認ダイアログ）、
    `useRunPolling` による2秒間隔の進捗ポーリング、結果サマリ、失敗ジョブのRetry・実行中のCancel、CSVレポートDL
    （全体/エンティティ単位・警告のみフィルタ）、`LimitsUpsellNotice` による上限到達時の具体数つきPro案内、
    `localStorage` へのrun_id保存によるリロード後の進捗復元）と Logs タブ（`src/tabs/LogsTab.tsx`。Job ID/レベルフィルタ・
    ページング・コンテキストJSON展開）。PR-Aの `GET /runs/{run_id}`・`GET /runs/{run_id}/report`・`GET /limits` を
    消費するのみでバックエンド変更なし。設計上の判断: CSVレポートDLはdry-runセクションのみ表示（`Importer` が
    `cbjp_dry_run_items` へ記録するのはdry-run時のみで、実移行runでは空CSVになるため）、ポーリングは通信エラー時も継続、
    Clearボタンはrunがterminalになるまで無効化（実行中のrun_idを失わないため）。wp-env + ColorMeテストショップで
    dry-run→CSV DL→実移行→冪等性→リロード復元→Logs表示をブラウザ実地確認済み（636テスト通過）。
    **持ち越し（`docs/review-backlog.md` 参照）**: cancelとページ処理完了の競合（f1-6-import-ui/R1-X1）、`POST /runs` の
    応答を取りこぼしたrun_idを発見する手段が無い（R2-X1。プラットフォーム単位のアクティブrun検索RESTが必要）、
    保持期限切れdry-runの空CSV DL（R3-X1）。F1-7/F1-8で扱うか別issueにするかは着手時に判断
- [x] **F1-7: ツール + 検証レポート**（サンプルクリーンアップ / リンク再構築（`/tools/*` REST + UI、D16）、移行後検証レポート（件数・受注合計金額の突合表示、D17））。
  2026-09-11 実装。詳細は `03-design-decisions.md` §10.3「ツールの実装詳細」/ §10.4「移行後検証レポートの実装詳細」。
  - `Woo\Tools\SampleCleanup`（`GET/POST /tools/sample-cleanup`）: mappings 起点で `_cbjp_platform` 所有の実体だけ削除し、所有権の無い行・
    email突合で採用した既存顧客（`CustomerWriter` が新規作成時にのみ書く `_cbjp_created_by_import` マーカーが無い）は unlink のみ。
    1リクエスト予算100件の `has_more` ループで、完了時に残骸 mappings と `cbjp_sample_{platform}` を削除（→次回 import で再選定=§10.2 #7）
  - `Woo\Tools\MappingRebuilder`（`POST /tools/rebuild-mappings`）: 各 Writer が Woo 側実体へ必ず書く `_cbjp_platform`+`_cbjp_remote_id`
    （受注は `_cbjp_remote_order_number`）メタを走査し checksum=null で upsert。D16 の「SKU/email 突合」は誤リンクの危険があるため採用しない
  - `Sync\VerificationReport`（`GET /runs/{run_id}/verification`）: `Importer` が totals に累積する `remote_amount`（1/100単位 int。`Support\Money`）と
    Woo 側のリンク済み実在受注の `get_total()` 合計を突合。件数は「この run で取得」vs「リンク済みで実在」、`missing`=実体を失った mapping
  - UI: Tools タブ（Preview→確認→バッチループ / Rebuild links）、Import 結果の検証レポート（全ジョブ completed のときのみ表示）
  - 実ショップ（ColorMe）での往復確認は F1-8 で行う。F1-6 の**持ち越し3件**（cancel 競合 R1-X1 / run_id 再発見 R2-X1 / 期限切れ dry-run の
    空 CSV R3-X1）は F1-7 に含めず別 issue として起票する
- [x] **F1-8: 実データE2E**（テストショップから商品100件・受注50件規模。中断→再開、再実行の冪等性、**無料版サンプル→上限解除→本移行の重複なし確認（上書きポリシー両方）=D16**、実行時間計測=要検証#6）。
  2026-09-13 実施。テストショップではなく**実店舗2件**（岡虎様=ちくわの岡虎オンラインショップ、三つ猫様=クラフト紙工房三つ猫）で実施し、当初想定より大幅に大きい実データ規模となった。
  - **岡虎様**（`shop.okatora.co.jp`）: 全エンティティを実施。実データ規模は商品113件・顧客331件・受注1230件（想定の10倍超）。
    決済/配送マッピング未設定時は受注全件が`payment_method_unmapped`/`shipping_method_unmapped`になることを確認し、`PUT /settings/mappings/colorme`
    （決済5種→`bacs`/`cod`/`postofficebank`/`payjp_card`、配送2種→代表`flat_rate:6`）を設定後に警告0件へ解消することを確認。
    サンプル移行（上限あり、商品12/顧客4/受注10/クーポン3）→一時的なmu-plugin（`cbjp/limits/*`フィルターをnullへ上書き、Pro版の上限解除を模擬）
    設置→全件本移行の順で実施し、**サンプルで作成済みの10件の受注・12件の商品・3件のクーポンが本移行で重複作成されずskipped扱いになった**（D16の冪等upsert実証）。
    最終結果: 商品113件・顧客332件（327作成+5skip）・受注1230件（1220作成+10skip）・在庫159件・クーポン3件、失敗0件。
  - **三つ猫様**（`kraft3cats.mystagingwebsite.com`）: ColorMe副管理者アカウント未取得のため、Categories/Tags/Products（7/1/117件、失敗0件）のみ実施。
    Customers/Orders/Stock/Couponsは決済/配送マッピングのID→名称確認ができないため保留。サブ管理者アカウント取得後に別途実施する。
  - **実行時間計測（要検証#6）の実測値**: 画像付き商品の取り込みは画像sideloadで大きくコストがかかる（三つ猫様: 画像付き商品50件/バッチで最大90秒/バッチ、
    117件で数分）。一方、画像の少ない岡虎様の商品113件は数十秒で完了。受注1230件の全件本移行（カーソル全走査、WP-Cron駆動）は十数分規模。
    無料版のサンプル移行（上限10件）でも受注ジョブは**サンプル10件を選ぶためだけに全1230件をカーソル走査していた**ことが判明し、
    product/customerに存在するID指定取得の高速経路（`run_sample_page`）が`order`エンティティに無い実装漏れを issue #38 として起票した。
  - **持ち越し**: (1) 中断→再開（Cancel run→再開）の明示的なテストは未実施。(2) 上書きポリシー（更新/スキップの選択式UI）の両方の動作確認は未実施
    （今回はchecksum一致による自動skipのみ観測。選択式UIの実装有無は未確認）。(3) `docs/review-backlog.md`の`tax_rounding_method=round_off`端数実測は
    実店舗の既存データでの代替確認に留まり、意図的な端数テストデータでの検証は未実施。(4) 決済/配送マッピングUI（F1-6 PR-Bスコープ）は依然未実装で、
    今回はREST直PUTで対応した。(5) 三つ猫様のCustomers/Orders/Stock/Couponsはサブ管理者アカウント取得後に実施。
  - 学びは `CLAUDE.md`（カラーミー決済/配送名の`GET /payments.json`/`GET /deliveries.json`経由取得、`window.confirm()`がブラウザ自動操作を壊す問題）に蒸留済み。
    受注サンプル選定の実装漏れは issue #38 参照

---

## Phase 2: Woo → カラーミー エクスポート（v1.0）

> 旧計画の Phase 4（Woo → ASP エクスポート）からカラーミー分を切り出し、v1.0 に前倒し（D18）。
> Exporter パイプライン・マッピングUIは ASP 非依存に作り、v2.0/v3.0 では各アダプタの `push*` 追加と
> capability 分岐（カテゴリ自動作成等）の有効化だけで成立させる。
> 前提: F1-8 完了（インポート側の実データE2Eで Canonical⇔Woo の変換が実データに耐えることを確認済み）。

- [x] **E2-1: マッピングUI**（カテゴリ: カラーミーは作成不可（`canCreateCategory=false`）のため既存カテゴリ選択のみ。自動作成の分岐点は capability 判定として用意し、実装は v2.0 E5-1 / 決済・配送・注文ステータス対応表。F1-5後続の `GET/PUT /settings/mappings/{platform}` と設定ストア `cbjp_settings_{platform}` を共用）。
  2026-09-13 実装。`PlatformAdapter`（03 §2）に `mapping_candidates()` を追加（D19。UIが選択肢を動的に描画するための自己記述スキーマで、既存の `connection_fields()` と同じ設計思想）し、`ColorMeAdapter` が `fetch_categories()`/`payments.json`/`deliveries.json`（既存の`id_name_map()`再利用）+ 固定4値の canonical ステータスで実装。Woo側候補は新設 `Woo\Support\MappingCandidates`（プラットフォーム非依存、`get_terms()`/`WC()->payment_gateways()`/`WC_Shipping_Zones`/`wc_get_order_statuses()`）が担う。設定ストアに `category_map`（Woo側カテゴリID→ASP側カテゴリID。カラーミーが作成不可のため他3キー=ASP→Wooとは逆向き）を追加し、`GET /settings/mappings/{platform}` のレスポンスへ `asp_candidates`/`woo_candidates` を同梱（両者とも取得失敗時は4キー空配列に正規化。不正な要素も同時に除外）。PUTは候補を返さない（保存操作そのものでは候補が変化しない一方、ColorMeでは候補取得のたびに`categories.json`等の追加APIコールが発生し実行中ジョブとレート制限を奪い合うため）。フロントは `ExportTab.tsx` に4テーブル（カテゴリは `can_create_category=false` の時のみ表示）を実装。
  **R1レビューで判明し修正した重大な指摘**: `wc_get_order_statuses()` が返す `checkout-draft`（WooCommerce Blocksのチェックアウト下書き）をステータス候補に含めていたが、これへマッピングすると取り込んだ受注が24時間後に日次cronで完全削除される事故になるため除外。詳細は `docs/reviews/feat/e2-1-mapping-ui/R1.md`
  **実機確認**: 実店舗のColorMe OAuth接続は本セッションでは確立できなかった（developer.shop-pro.jpへのブラウザセッションが無く、ポート変更（8895）に伴うリダイレクトURI再登録とログインが必要。詳細は `docs/reviews/feat/e2-1-mapping-ui/dev-cycle.md` 参照）ため、wp-env上に一時的なモックアダプタ（mu-plugin、コミットせず削除済み）を用意し、REST応答・候補描画・保存・永続化の一連の流れをブラウザで確認した。実際のColorMe候補データでの確認はE2-3（push*実装、要検証#5でテストショップ接続が必要）まで持ち越し。
- [x] **E2-2: Exporter パイプライン**（Woo→Canonical読出、SKU/email突合upsert、dry-run。**無料版はインポートと同基準のサンプル上限（Woo側の最新受注10件起点）を適用=D15。実行前の本番書込み警告=D17**。`RestController` の `type=export` 501 を解除）
  **PR-A実装済み（コア配線 + ProductReader）**: `Sync\Exporter`/`Sync\PlatformWriter`/`Sync\WooReader`
  （`Importer`/`WooWriter`のASP向け対称形）を新設し、`JobManager`に`type=export`/`dry_run_export`
  分岐を追加。`Woo\Reader\ProductReader`（Woo→CanonicalProduct、`category_map`でカテゴリ解決）、
  `Woo\Export\AdapterPlatformWriter`/`DryRunPlatformWriter`（`push_product()`へディスパッチ/
  dry-run）、`Sync\ExportSampleSelector`（Woo側受注起点のサンプル選定。§10.2）を実装。
  「SKU/email突合」は`cbjp_mappings`のみで判定（ASP側への投機的検索はD16方針により不採用）。
  詳細は `docs/03-design-decisions.md` §10.2「エクスポート方向の実装」参照。
  **PR-B実装済み（CustomerReader/OrderReader/StockReader/CouponReader）**: 2026-09-14実装。
  `JobManager::EXPORT_ENTITIES_WITH_READER`に4エンティティを追加し、`filter_and_order_export_entities()`
  にcapabilityゲート（customer: `can_update_customer`、order: `can_create_order`、coupon:
  `has_coupons && can_create_coupon`）を復活させた。`AdapterPlatformWriter::write()`も4エンティティを
  `push_customer()`/`push_order()`/`push_stock()`/`push_coupon()`へディスパッチするよう拡張
  （PR-Aでは`product`のみだった）。`process_export_page()`のサンプリング判定を`product`の上限基準に
  修正（`stock`自身の上限は`null`のため、以前の実装だと無料版でも全在庫が対象になっていた）。
  `coupon`は受注サンプルに紐づかない独立上限（`LimitPolicy`のみ、importのcoupon処理と同型）。
  住所・決済/配送方法・受注ステータスはWooネイティブの生値のまま運び、ASP固有スキームへの変換は
  E2-3の`push_*()`実装へ委ねる（D19と同じ原則）。ColorMeの`push_*()`はE2-3まで
  `UnsupportedOperationException`のため、実行(非dry-run)のexportは全件skipped/warnedで完了するのが
  現状の期待動作（配線の正しさはモックアダプタで検証済み）。詳細は `docs/03-design-decisions.md`
  §10.2「エクスポート方向の実装（E2-2 PR-B）」参照。
- [x] **E2-3: ColorMe push\***（商品→顧客→受注→在庫。**要検証#5を確定してから受注実装**）
  - **PR-A（`push_product()`のみ、完了、PR #43・2026-09-15）**: 画像は `canPushImages`（`shop.json` の
    `contract_plan` 依存。03 §9 #1）が true なら `POST /v1/products/{product_id}/images`、false/403 なら
    画像URL一覧CSV出力フローへ切替する設計だが、本PRではプレミアムプラン時のみ画像push実装、
    非プレミアム時は`PRODUCT_IMAGES_NOT_PUSHED`警告に留め、CSV代替フローは未実装（要フォロー）。
    `PushResult::$variant_remote_ids`（E2-2 R1申し送り）と部分完了の耐久的契約
    （`is_retryable_failure()`/`record_failure()`/`append_failure_warning()`。E2-2 G3申し送り）を実装。
    詳細は `docs/03-design-decisions.md` §10.2「E2-3 PR-A」、`docs/reviews/feat/e2-3-push-product/`。
  - **PR-B（`push_customer()`のみ、完了、2026-09-15）**: 新規作成必須項目（`pref_id`/`postal`/
    `address1`/`tel`）をWoo顧客の請求先情報から解決できない場合はAPIを呼ばずスキップ
    （`WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING`）。住所スキーム変換は`Woo\Support\AddressMapper`
    に`state_code()`の逆関数`pref_id_from_state()`を追加して行う。詳細は
    `docs/03-design-decisions.md` §10.2「E2-3 PR-B」。
  - **PR-C（`push_order()`のみ、完了、2026-09-15）**: 要検証#5をswagger精査で確定（プレミアム
    プラン限定・必須は`details`+`payment_id`のみ・`sale_deliveries`は配送不要商品を除き必須）。
    明細単価を復元できない場合（ショップの`tax_type`不明、または合計が数量で割り切れない）は
    `price`を省略せず**受注全体をpushしない**（`ORDER_LINE_PRICE_UNRESOLVED`。`price`省略時に
    カラーミーのカタログ価格が適用される仕様を当てにする設計は、恒久的な金額の食い違いを
    招くためgate review中に撤回した）。D19の申し送り（`payment_map`/
    `shipping_map`逆引きの曖昧性）は`Woo\Support\MethodMap::asp_payment_id()`/`asp_delivery_id()`
    （Woo側IDに対応するASP側IDがちょうど1件の場合のみ解決、0件・複数一致は未解決として
    フェイルクローズ）で解決。`push_order()`は`$remote_id`（既存remote_id）を受け取るよう
    `PlatformAdapter`のシグネチャを統一し、既にエクスポート済みの受注はAPIを呼ばずスキップする
    （ColorMeの`PUT /sales/{id}`が明細・決済/配送方法の更新を実質サポートしないため、再POSTでの
    重複作成を防ぐ）。受注ステータス（paid/delivered/cancelled）の事後同期は対象外（既知の制限）。
    詳細は `docs/03-design-decisions.md` §10.2「E2-3 PR-C」。
  - **PR-D（`push_stock()`のみ、完了、issue #47・2026-09-23）**: 在庫専用の書込みエンドポイントが
    無い（`GET /v1/stocks`はGETのみ）ため、単純商品は`PUT /v1/products/{id}`
    （`stock_managed`+`stocks`）を1リクエスト、バリエーションは商品側`stock_managed:true`の
    明示PUT→`PUT /v1/products/{id}/variants/{id}`（`stocks`のみ）の2リクエストを送る
    （G1ゲート、Codex指摘。要検証#19）。`CanonicalStock::$quantity=null`（在庫管理外）は
    単純商品では`stock_managed:false`で表現できるが、バリエーション更新スキーマには相当する
    フィールドが無いため、その場合はAPIを呼ばずフェイルクローズしてスキップする
    （`WarningCode::STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE`）。詳細は
    `docs/03-design-decisions.md` §10.2「E2-3 PR-D」。
- [x] **E2-4: エクスポートUI + 往復E2E**（Export タブ（エンティティ選択→dry-run→本番書込み警告→実行→進捗→結果レポート）。テストショップへの ColorMe→Woo→ColorMe 往復移行でデータ欠損・冪等性を確認）
  2026-09-23実装。`src/tabs/ExportTab.tsx`に既存のマッピング設定カードへ実行フローを追加。
  `availableExportEntities()`（`JobManager::filter_and_order_export_entities()`と同条件を
  ミラー。product/stockは常時、customer/order/couponはcapabilityでゲート）・`ImportTab.tsx`と
  同型の`dryRunExportState`/`exportState`（localStorage永続化・Retry/Cancel・`useRunPolling`/
  `RunProgress`/`LimitsUpsellNotice`をそのまま再利用）を実装。`GET /runs/{id}/verification`は
  `type=import`専用（400になる）ため`VerificationReport`は使わない。
  **本番書込み警告（D17）はImportTabの`window.confirm()`ではなく常時表示の`Notice`+
  `CheckboxControl`によるゲート方式にした**（`.claude/rules/frontend.md`がネイティブ
  `window.confirm()`はClaude in Chrome等のブラウザ自動操作をフリーズさせると指摘済みで、
  本タスク自体が下記の実機E2Eをブラウザ自動操作で行う必要があったため。チェック時
  `POST /runs`へ`acknowledge_production_write: true`を送る）。
  `docs/review-backlog.md`の`e2-2-exporter-pr-b/R1-L6`（capabilityゲート）・
  `e2-2-exporter-core/R1-M8`（実行export全skip/warnedの無警告）を解消（後者はexport結果カードに
  `created+updated===0 && warned===processed`のcompletedジョブを検出する警告バナーを追加。
  `warned>0`だとchecksum一致skipに残留する非ブロッキング警告だけで誤検出しうるため、R1レビュー
  指摘を受けて絞った）。
  **実機E2E（ColorMeテストショップ、2026-09-23）**: 開発者ポータルの既存プライベートアプリ
  （`colorme.env`・アシスタントのローカルmemoryが指す資格情報）が別ログインに紐づいていたため、
  同一セッション内で新規アプリ+新規テストショップを作成し直して接続。同ショップは非プレミアム
  プラン（`can_create_order=false`/`can_create_coupon=false`）のため、受注・クーポンのexportは
  このE2Eでは検証できず（Export UI側でチェックボックス自体が正しく非表示になることは確認済み）、
  **製品・顧客・在庫のみで検証**した:
  1. dry-run export（全量走査）→ Preview export results・CSVレポートDLが正しく機能
  2. 実export → 対象7件中2件のみ作成。原因は無料版上限（product=50、未到達）ではなく
     `ExportSampleSelector`の受注起点サンプル選定が拾った3候補のうち1件が元々exportできない
     商品（variable商品で全バリエーション非公開）だったため。`LimitsUpsellNotice`の
     「remaining N require Pro」表示はこのケースを上限到達と誤って表現しており、この表示
     文言自体の不正確さを`docs/review-backlog.md`の
     `e2-4-export-ui-e2e/limits-upsell-message-misleading`に記録した（共有コンポーネントの
     既存の問題で本PRの差分範囲外）。ColorMe側に商品2件・顧客1件が実際に作成されたことを
     API直叩きで確認
  3. Importタブから再取込み（`window.confirm()`を避けるため`rest_do_request()`で直接起動）→
     mappingにより既存のWoo商品/顧客を**重複作成せず更新**（往復でのデータ増殖なし）。
     価格・在庫管理フラグ・郵便番号/県ID(pref_id)/電話番号は正しく往復した。
     **既知の制限を実地で再現**: ColorMeの`name`は単一文字列（「姓 名」の想定）だが、
     ColorMe由来ではないネイティブWoo顧客（`_cbjp_full_name`メタ無し）をexportすると
     `first_name . ' ' . last_name`（Western順）で組み立てた文字列がColorMeへ送られ、
     再importの`split_name()`が「姓 名」前提で分割するため姓名が入れ替わる
     （`Woo\Reader\CustomerReader::name()`のdocblockに既知の制限として記載済み。今回の
     テスト顧客がまさにこのケースで、修正は本タスクの範囲外）
  4. 再exportで冪等性を確認: 顧客・在庫はchecksum一致で`Skipped`。商品は
     カテゴリマッピング未設定（Uncategorized→ColorMeカテゴリ未指定）による
     `category_map_unresolved`警告が`WarningCode::indicates_unresolved_reference()`に
     該当し続けるため、仕様どおりchecksumをキャッシュせず`Updated`を繰り返すが、
     ColorMe側の商品数はAPI確認で2件のまま**重複ゼロ**（既存remote_idへのPUTのみ）。
  詳細は `docs/03-design-decisions.md` §10.2「E2-4」参照。

**Phase 2 完了チェック**: カラーミーのテストショップに対して dry-run → サンプルエクスポート → 再エクスポート（checksum一致skip・重複ゼロ）が通ること。**達成**（2026-09-23実機E2E。重複ゼロは確認、checksum一致skipは顧客/在庫で確認・商品はカテゴリマッピング未設定により毎回Updatedになるが既存remote_idへの上書きのみで重複は作らない。詳細は上記E2-4参照）。

---

## Phase 3: v1.0 仕上げ・公開

- [ ] **R3-1: 全件E2Eリハーサル**（カラーミーのテストショップで実データ移行。インポート→エクスポートの往復でデータ欠損確認。**無料版サンプル→上限解除→本移行の重複なし確認（上書きポリシー両方）=D16** を F1-8 の結果と合わせて最終確認。**あわせて、`tax_type=excluded` の店舗でセール中バリエーションの `option_market_price`（定価）の税基準が `option_price` と同じか実機確認する**〔`docs/03` §10.2「価格の税込正規化とバリエーションのセール価格」の要検証。PR #61 Copilot G1-1〕）
- [ ] **R3-2: i18n**（POT生成、languages/ja.po 翻訳、make-json。参考スキル: wp-i18n）
- [ ] **R3-3: readme.txt + アセット + 説明文のv1.0化**（スクリーンショット、商標表記: WooCommerce is a trademark of Automattic / ASP名は本文でのみ言及。**プラグインヘッダーと `composer.json` の Description を「Color Me Shop」のみに改める**（現状は3ASP併記。03 §7）。BASE/MakeShop の対応予定を readme に載せるかは公開時に判断）
- [ ] **R3-4: wordpress.org 申請**（スラッグ `cart-bridge-jp`、Plugin Check通過、バージョン 1.0.0。参考スキル: wp-org-release。**公開時に `AbstractPlatformAdapterTest` を2箇所凍結する（D20・issue #49）**: (1) `v1_method_names()` の実装をその時点の `array_keys( self::BASELINE )` を書き写したリテラル配列に置き換える（`BASELINE`との動的連動をやめる。これを忘れると公開後に追加したメソッドの既定実装削除が検出できなくなる）。(2) これ以降 `PlatformAdapter` の既存シグネチャ変更は禁止、新メソッドは `AbstractPlatformAdapter` に既定実装を添えて追加する運用に切り替える。**凍結前に `docs/review-backlog.md` の `PlatformAdapter` 契約拡張前提の保留項目（`e2-3-push-*/G1-duplicate-on-retry`・`fix-46-pref-state-repair/L-unavailable-not-split`）の対応要否を判断する**（03 §2 D20 規則7））
- [ ] **R3-5: アンインストールオプションUI + セキュリティ最終監査**（wp-security-check スキル）

> **要判断（v1.0公開前）**: 無料版の上限到達時に表示する Pro 案内（03 §10.3）の導線先として、v1.0 公開と同時に Pro 版を購入可能にするか。
> Pro 版アドオンは別リポジトリ（本ファイル末尾）で、本リポジトリ側の拡張ポイントは Phase 0 で提供済み。

---

## Phase 4: BASE → Woo インポート（v2.0）

> 前提: BASE Developersアプリ登録済み・テストショップあり（D2）。詳細は `04-plan-base.md`。
> BASE固有の制約: 顧客一覧API・注文作成API・クーポンAPIなし。トークンは1時間期限+リフレッシュ30日ローテーション（D13。TokenStore 側の構造化ペイロード・排他ロックは Phase 0 で実装済み）。
> **アーキテクチャ検証**: プラットフォーム固有分岐をアダプタ外（Importer/JobManager本体）に持ち込まずに成立させること（旧計画で MakeShop が担っていた検証観点を引き継ぐ）。ただしプラットフォーム非依存のコア拡張点の追加は許容する（B4-5の顧客永続化フック、E5-1のリトライ遅延拡張点。D18）。
> 旧タスクID `B3-*` を `B4-*` に採番し直した（内容は同じ）。
> **OAuth中継サーバー（案B）**: v1.0 は現行のBYOアプリ方式（利用者が自分でアプリ登録）で公開し、開発者ホスト型の中継サーバーは v2.0 の検討事項とする（2026-09-06）。検討内容は `docs/20-memo-hosted-oauth-relay.md`。採否は **B4-7 を B4-0 の直後に実施**して決め、採用なら B4-1 / B4-3 と Phase 5 に hosted モードのタスクを展開する（IDは追加順で採番しているため番号と実施順は一致しない）。

- [ ] **B4-0: フィクスチャ収集 + 仕様実測**
  - テストショップにサンプルデータ（バリエーション商品・複数カテゴリ商品・各決済の受注・一部発送の受注）を登録
  - items / items/detail / categories / item_categories / orders / orders/detail / users/me の実レスポンスを `tests/fixtures/base/` に保存
  - **要検証#9（redirect_uriのhttps要否）/#10（発送ステータス集約規則）/#11（エラー形式・レート制限挙動）/#12（費用・スコープ承認）と、BASE分の#14/#15（受注ソート・商品ID指定取得）をここで確定** → 03 §9 と Capabilities を更新
- [ ] **B4-7: OAuth中継サーバー（案B「かんたん接続」）の採否判断**（B4-0 の直後に実施。`docs/20-memo-hosted-oauth-relay.md` §9 の再確認チェックリスト → §7 の未確定事項（同一プライベートアプリの複数ショップ認可の実測、カラーミー／BASE への規約問い合わせ=付録A、Pressable の実測）を確定 → 採否を同メモ §1.3 と 03 の設計判断（D19以降）に記録。採用時は同メモ §8 の R1〜R4 を Phase 4〜5 のタスクとして追加し、B4-1 / B4-3 を hosted モード込みで実装する。不採用時は同メモを「見送り」に更新して閉じる）
- [ ] **B4-1: BaseOAuth**（認可URL生成、callback REST（カラーミーと共通基盤）、**リフレッシュ+ローテーション+排他ロック**、TokenStore統合。B4-7 で案B採用ならリフレッシュは中継の `/base/refresh` 経由も実装）+ ユニットテスト
- [ ] **B4-2: BaseClient**（GET/POST、**HTTP 400のレート制限コード判別**（`HttpClient` の `$rate_limit_detector` を使用）、期限切れ時の自動リフレッシュ、RateLimiter統合）+ ユニットテスト
- [ ] **B4-3: 接続ウィザードUI**（client_id/secret入力 → 認可 → users/me接続テスト。code手動貼付フォールバック。B4-7 で案B採用なら「かんたん接続」を既定にし、BYO方式は「上級者向け」として残す）
- [ ] **B4-4: Transformer**（Product/Order/Category + **CustomerExtractor**（受注購入者のemail名寄せ=D12）。フィクスチャベースのユニットテスト）
- [ ] **B4-5: BaseAdapter.fetch\* + Importer結合**（カーソル=offset、受注は一覧→詳細の2段取得、dry-run動作確認。`Page::$total` は変換層で行の除外・展開がありうるため 1:1 を保証できなければ null）
- [ ] **B4-6: 実ショップE2E**（商品・受注・顧客抽出の冪等性確認）

---

## Phase 5: Woo → BASE エクスポート + v2.0 公開

- [ ] **E5-1: BASE push\***（カテゴリ自動作成（E2-1 のマッピングUIに `canCreateCategory=true` 分岐を実装、3階層まで・4階層以上は平坦化+警告）→商品upsert→画像add_image(URL方式・**要検証#13**)→在庫edit_stock。**1日1,000件制限の分割実行**（`paused`→翌日再エンキュー）、バリエーション1軸化・絵文字除去のdry-run警告。受注・顧客は対象外（capabilityでUI非表示））。
  **既知の設計課題**: 現状の `Sync\JobManager` は `RateLimitExhaustedException` を固定 `PAUSED_RESUME_DELAY_SECONDS`（60秒）後に再試行する実装のため、このままでは1日上限到達時に翌日リセットまで待たず1分おきに再試行し続ける。「翌日再エンキュー」を実現するには、E2-2（Exporterパイプライン）または本タスクで再試行遅延を可変にする拡張点（例外側で希望の再試行時刻を指定できるようにする等）をJobManagerに追加する必要がある（D18が許容するプラットフォーム非依存のコア拡張点。プラットフォーム固有分岐はアダプタ外に書かない原則自体は維持する）
- [ ] **R5-1: v2.0 公開**（BASE往復E2Eに加え、E5-1でマッピングUI・`JobManager`のリトライ挙動を変更しているため**カラーミーの往復E2Eも再実行**（既存プラットフォームの回帰確認）。readme.txt / プラグインヘッダー / `composer.json` の Description に BASE を追記、**i18n再生成**（ja.po追補 + POT再生成 + `make-json`。R3-2と同じ手順）、**セキュリティ監査**（BASE OAuth・トークンリフレッシュ・`BaseClient`・接続設定UIの新規コードを対象。wp-security-checkスキル）、バージョン 2.0.0、wordpress.org 更新）

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
- [ ] **R7-1: v3.0 公開**（MakeShop往復E2Eに加え、**カラーミー・BASEの往復E2Eも再実行**（既存プラットフォームの回帰確認）。readme.txt / プラグインヘッダー / `composer.json` の Description に MakeShop を追記、**i18n再生成**（ja.po追補 + POT再生成 + `make-json`）、**セキュリティ監査**（MakeShop認証・`GraphQLClient`・接続設定UIの新規コードを対象。wp-security-checkスキル）、バージョン 3.0.0、wordpress.org 更新）

---

## Pro版アドオン（別リポジトリ・フェーズ番号なし）

> 本リポジトリのスコープ外（無料版に Pro 固有コードを含めない）。無料版側は `cbjp/limits/*` フィルターと
> AdapterRegistry の拡張ポイントを提供するのみ（P0-6 / P0-5 に含む）。継続同期は販売しない（D14）。
> 上限解除はプラットフォーム非依存のため、v2.0/v3.0 のアダプタ追加で Pro 側の変更は不要。

- Pro プラグイン: `cbjp/limits/*` による上限解除、301リダイレクトCSV生成（D17）
- ライセンス統合: WooCommerce API Manager クライアント（アクティベーション・アップデート取得）
- 販売サイト側: WooCommerce API Manager 導入、**有効期限の起点を初回アクティベーション時にするカスタマイズ**（D14/03 §10.1）、適格請求書対応
