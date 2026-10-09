# 実装タスク（WBS）

最終更新: 2026-10-09

本ファイルが実装タスクの唯一の管理台帳。各タスクは Opusplan の1セッション（plan → 実装 → 検証）で
完結する粒度に分割してある。

## リリース計画（D18・2026-09-05改訂）

| バージョン | 対応プラットフォーム | フェーズ | 状態 |
|---|---|---|---|
| **v1.0** | カラーミーショップ（インポート＋エクスポート） | Phase 0〜3 | Phase 1 完了（F1-8 実店舗2件でのインポート実データE2E完了、持ち越し事項あり。F1-6 完了時点を `v0.1.0` として GitHub Release で実サイト検証中）。Phase 2 完了: E2-1〜E2-4 完了（`push_product`/`push_customer`/`push_order`/`push_stock`、#43〜#45・#47、Export タブ実行フロー）。Phase 3: R3-0a〜R3-0p 完了、R3-1（全件 E2E リハーサル）実施済み。リハーサルで見つかった修正 R3-1a〜R3-1e（#98〜#102・#78）は完了（PR #104〜#107）で、テストショップでの再リハーサル（まとめて 1 回）も 2026-10-08 に済ませた。R3-2（i18n・日本語訳の同梱、PR #109）・R3-3（readme・スクリーンショット・説明文の v1.0 化）完了。残りは R3-6（無料版と Pro の境目の切り替え。D27。R3-4 の前に行う）・R3-4・R3-5 |
| **v2.0** | + BASE（インポート＋エクスポート※）＋ OAuth中継サーバー（案B「かんたん接続」）の採否判断（B4-7） | Phase 4〜5 | 未着手（v1.0 公開後） |
| **v3.0** | + MakeShop（インポート＋エクスポート） | Phase 6〜7 | 未着手（v2.0 公開後） |
| Pro版アドオン | 顧客・受注・クーポンの移行（ライセンスが無い間は試用で各 100 件まで、ライセンスで無制限）・パスワード設定メール・301 リダイレクト CSV（D27・D28。~~無料版上限の解除~~） | — | 別リポジトリ |

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
- [ ] **feat: 県コード修復ツールで未修復の顧客・受注を一覧表示し、郵便番号から推定した県を候補として示す**（issue #71、`v0.1.1` 想定）
  修復ツール（issue #46）は結果を区分ごとの件数でしか返さず、自動修復できなかった `unverified`/`unavailable` の対象を店舗が特定・手修正できない。
  県が誤ったままの顧客はチェックアウトの既定住所・県単位の送料・送り状に影響する（未発送の受注は誤配送リスク）。Tools タブに対象一覧（編集画面リンク付き）を追加し、
  郵便番号から推定した県を候補として表示する（自動では書き込まない。複数県にまたがる範囲は断定しない）。`review-backlog` の
  `fix-46-pref-state-repair/L-unavailable-not-split`（`PlatformAdapter` 契約拡張）はこの代替として見送る。対象は `v0.1.0`〜2026-09-15 にインポートしたサイトのみ

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
  2026-09-13 実施。テストショップではなく**実店舗2件**（ちくわ実店舗・クラフト実店舗）で実施し、当初想定より大幅に大きい実データ規模となった。
  - **ちくわ実店舗**: 全エンティティを実施。
    決済/配送マッピング未設定時は受注全件が`payment_method_unmapped`/`shipping_method_unmapped`になることを確認し、`PUT /settings/mappings/colorme`
    （決済→`bacs`/`cod`/`postofficebank`/`payjp_card`、配送→代表`flat_rate:6`）を設定後に警告0件へ解消することを確認。
    サンプル移行（上限あり）→一時的なmu-plugin（`cbjp/limits/*`フィルターをnullへ上書き、Pro版の上限解除を模擬）
    設置→全件本移行の順で実施し、**サンプルで作成済みの受注・商品・クーポンが本移行で重複作成されずskipped扱いになった**（D16の冪等upsert実証）。
    最終結果: 全エンティティで失敗0件（サンプルで作成済みの分はskip）。
  - **クラフト実店舗**: ColorMe副管理者アカウント未取得のため、Categories/Tags/Products（失敗0件）のみ実施。
    Customers/Orders/Stock/Couponsは決済/配送マッピングのID→名称確認ができないため保留。サブ管理者アカウント取得後に別途実施する。
  - **実行時間計測（要検証#6）の実測値**: 画像付き商品の取り込みは画像sideloadで大きくコストがかかる（クラフト実店舗: 画像付き商品50件/バッチで最大90秒/バッチ、
    全件で数分）。一方、画像の少ないちくわ実店舗の商品は数十秒で完了。受注の全件本移行（カーソル全走査、WP-Cron駆動）は十数分規模。
    無料版のサンプル移行（上限10件）でも受注ジョブは**サンプル10件を選ぶためだけに全件をカーソル走査していた**ことが判明し、
    product/customerに存在するID指定取得の高速経路（`run_sample_page`）が`order`エンティティに無い実装漏れを issue #38 として起票した。
  - **持ち越し**: (1) 中断→再開（Cancel run→再開）の明示的なテストは未実施。(2) 上書きポリシー（更新/スキップの選択式UI）の両方の動作確認は未実施
    （今回はchecksum一致による自動skipのみ観測。選択式UIの実装有無は未確認）。(3) `docs/review-backlog.md`の`tax_rounding_method=round_off`端数実測は
    実店舗の既存データでの代替確認に留まり、意図的な端数テストデータでの検証は未実施。(4) 決済/配送マッピングUI（F1-6 PR-Bスコープ）は依然未実装で、
    今回はREST直PUTで対応した。(5) クラフト実店舗のCustomers/Orders/Stock/Couponsはサブ管理者アカウント取得後に実施。
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

- [x] **R3-0a: 作成後の中断による重複作成を防ぐ（D21-A）**（issue #72）: 新設 `PartialPushException` で、作成確定後に中断した商品の remote_id を `Exporter` まで運び、checksum=null で mapping を書く（再開時は PUT）。`ColorMeAdapter::push_product()` の作成後ブロックで再スローしている `RateLimitExhaustedException` を包む。単独 PR
  **実装サマリ（2026-09-26）**: `Adapters\PartialPushException`（`remote_id()` と、原因を `getPrevious()` に持つ）を新設し、`PlatformAdapter::push_product()` の docblock と `AbstractPlatformAdapter` に「作成が確定した後の例外は包んで投げる」契約を明記した（シグネチャは不変。D20 の `BASELINE` も不変）。`ColorMeAdapter::push_product()` は商品本体の成功後の処理（追いPUT・バリエーション・画像）を `finish_product_push()` へ切り出し、**作成経路のときだけ**その呼び出し全体を1つの `try/catch (Throwable)` で包む（6か所の個別 catch には触れず、`try` の外にあった `wp_remote_get()` 等の例外も拾う）。`Sync\Exporter::process_items()` は `PartialPushException` を `PushResult( remote_id, created|updated, [ PUSH_INTERRUPTED_AFTER_CREATE ] )` に読み替えて通常の書込み経路へ流し（`delete_one()`・`upsert()`・`indicates_unresolved_reference()` による checksum=null・totals を再利用）、原因が `RateLimitExhaustedException` のときは**そのアイテムの mapping を書いた後**に再スローする（`JobManager` が paused にし、再開時は同じ商品への PUT になる）。無料版の枠は戻さない。
  設計判断: 更新経路は包まない／remote_id が空の `PartialPushException` は本来の原因を投げ直す（R3-0b では「印を残す」行として扱う）。詳細は `docs/03` §10.2 D21-A「実装（R3-0a、issue #72）」。
  検証: 追加テスト 15 件（`ColorMeAdapterTest` 8 件〔3経路の `RateLimitExhaustedException`〔追いPUT・バリエーション詳細/オプション作成/バリエーションPUT・画像アップロード〕、画像取得の予期しない例外、更新経路は包まない、`Exporter` 結合で再開時に POST が増えず PUT になる〕、`ExporterTest` 6 件、`WarningCodeTest` 1 件）。ミューテーション 7 種（包む処理・`Exporter` の catch・`indicates_unresolved_reference()` への登録・再スローの位置〔mapping 書込みの前／再スローなし〕・空 remote_id の分岐・更新経路を包む）をそれぞれ一時的に入れて、対応するテストが落ちることを確認した（空 remote_id の分岐は集計が同じになるため、`error`／`warning` のログレベルで区別している）。実 API では意図的に起こせないため実機確認はしていない（R3-1 のモック確認に含む）。
- [x] **R3-0b: 作成結果が不明な実体を自動で再送しない（D21-B）**（issue #73、R3-0a の後）: `cbjp_push_intents`（送信中の印）、`Exporter` のライフサイクル（送信していない／拒否が確定したときだけ消す）、`PUSH_OUTCOME_UNCONFIRMED` によるブロック、`LimitPolicy` への算入、解除 REST（`not_created`/`link`）と Export タブの一覧 UI。バックエンドだけでは解除手段が無く動作確認できる振る舞いにならないため、REST・UI まで1 PR にまとめる（大きすぎる場合はバックエンド＋REST と UI の2 PR に分け、同じリリースに入れる）
  **R3-0a からの申し送り**: (1) `Sync\Exporter` は現状、remote_id が空の `PartialPushException`（契約違反）を本来の原因の投げ直しで処理している。印（push intent）を実装するときは、この経路を結果表の「その他の例外」行（印を残す）として扱う（`docs/03` §10.2 D21-A「実装（R3-0a）」の差2）。(2) 「`push_*()` 共通の契約（D21-A）」の参照を `push_customer`/`push_order`/`push_coupon` の docblock にも足す（backlog `fix-72-partial-push/R1-L4`）
  **実装サマリ**: バックエンド＋REST（`cbjp_push_intents`／`Sync\PushIntentRepository`／`Exporter`・`LimitPolicy`・`SampleCleanup` 統合／`GET・POST /cbjp/v1/push-intents/{platform}[/{id}/resolve]`）を PR #79（PR 1/2、2026-09-27 マージ）で実装。Export タブの解除 UI（PR 2/2）を本 PR で追加した: `PushIntentsPanel`（未解決 intent があれば常時表示の `Notice` + 表。行ごとに「未作成として解除」と remote_id 入力＋「リンクして解除」。`window.confirm()` は使わない）と、`.claude/skills/verify-with-mock-adapter` のモックアダプタへの push 検証切替（`cbjp_verify_seed.push`）。実装時の乖離4点・実機確認結果は `docs/03` §10.2 D21-B「実装（R3-0b、issue #73）」参照。
- [x] **R3-0c: 在庫管理が混在する variable 商品のエクスポートを止める（D22）**（issue #52）: Reader が `VARIATION_STOCK_MANAGEMENT_MIXED` を積み、`Exporter` が `Capabilities::$supports_per_variant_stock_management`（末尾に既定 `false` で追加。ColorMe は `false`）で商品と在庫行を止める
- [x] **R3-0d: 「Any」バリエーションを含む商品と受注のエクスポートを止める（D23）**（issue #74）: 最初に wp-env で実測。`VARIATION_ANY_ATTRIBUTE_UNSUPPORTED`（blocking）と `OrderReader::variation_option_values()` の解決不能判定。R3-0c と同じ `ProductReader`・`WarningCode` を触るため1 PR にまとめてよい
  **実装サマリ（R3-0c + R3-0d を 1 PR）**: 「Any」の定義（`VariationAxisResolver::has_any_attribute()`）と在庫の混在判定（`StockDerivation::has_mixed_variation_management()`）を
  それぞれ 1 箇所にして `ProductReader`/`StockReader`/`OrderReader` が共有する。判定の母集団は「公開バリエーションすべて」（価格不正で除外されるものも数える。商品行と在庫行の判定を揃えるため）。
  `VARIATION_ANY_ATTRIBUTE_UNSUPPORTED` は `indicates_export_blocking()` に登録（プラットフォーム非依存）、`VARIATION_STOCK_MANAGEMENT_MIXED` は登録せず
  `Exporter` が `supports_per_variant_stock_management`（`Capabilities` の末尾・既定 `false`。ColorMe は `false`）で止める。
  実測（Any は空文字列で保存・受注明細メタに選択値が残る・修正前は Any 明細が無警告で解決済みになる）と mock アダプタでの実機確認結果は `docs/03` D22/D23 の「実測結果」「実装」参照。
  **実 ColorMe API での確認（2026-09-28、テストショップ `ttka3lg60f`・非プレミアム）**: 商品・在庫の dry-run／実 export／「エクスポート済みの商品が後から混在→止まり ColorMe は不変」／
  「揃える・具体値に直すと通る」を確認済み（`docs/03` D22 実装 6.）。受注の Any 明細は非プレミアムのため mock までで、実 API では未確認。swagger の副作用（全バリエーション未設定で 1 件だけ在庫を送ると他が 0 になる）も実測で確認済み（`docs/03` D22 実装 7.。バリエーション単位の `null` は 422 で送れない）。解消した backlog: `e2-3-push-stock/G1-1`・`e2-3-push-product/R1-L3`・`e2-2-exporter-core/R1-L6`・`e2-3-push-order/G2-wildcard-variation-option-values`。
- [x] **R3-0e: ColorMe 受注の一覧取得で基盤取得の失敗を握りつぶさない**（issue #69）: `order_transformer()` を行単位の catch の外で解決する（単一ID取得と同じ形）。判断事項なし
  **実装サマリ**: `fetch_orders()` と `fetch_latest_orders()`（初回取得・探索窓を広げるループの計3箇所）で `transform_rows()` へ渡すクロージャの内側にあった `$this->order_transformer()` 呼び出しを、`transform_rows()` 呼び出しの前に解決する形へ変更（`fetch_product_by_remote_id()`/`fetch_products()` と同じ形）。`fetch_latest_orders()` は関数冒頭で1回だけ解決し初回・ループの両クロージャで共有する。HTTPモックのテスト2件（`payments.json` 401 で `ApiException` が伝播することを確認）を追加し、`mutate-check.sh` で両メソッドとも修正を戻すと対応テストが落ちることを確認済み
- [x] **R3-0f: ランダム順で稀に落ちる `ProductWriterTest` を安定させる**（issue #63）: テスト自身が `default_product_cat` の前提を明示する（期待どおり空になる値は実測で決める）。修正後にランダム順で複数 seed を確認。以降の PR の前に入れる
  **実装サマリ（2026-09-26。`tests/` のみの変更で `includes/` は無変更）**: 原因を実測で確定した。WP テストスイートの`tear_down_after_class()` → `_delete_all_data()` は各クラスの終了時に term_id=1 以外の全ターム等を削除して COMMIT する（テストごとの ROLLBACK では戻らない。**option は残す**）ため、bootstrap の `WC_Install::install()` が作る「Uncategorized」（`default_product_cat` の値は term_taxonomy_id。この環境では 15）が有効なのは**プロセス最初のテストクラスの間だけ**で、2 クラス目以降は option が消えたタームを指したままになる（`WC_Product_Data_Store_CPT::update_terms()` の自動付与は、存在しない int ID を `wp_set_post_terms()` が読み飛ばして no-op）。対象テストは `[]` を期待するので、`ProductWriterTest` が先頭クラスになったときだけ `[15]` で落ちていた（ランダム順で稀。**単独実行では毎回落ちる**。CI のデフォルト順は先頭が `AbstractPlatformAdapterTest` なので常に通る）。全 60 クラスを 1 つずつ単独実行（＝各クラスが先頭）した洗い出しで、同じ落とし穴を持つテストはこの 1 件のみだった。
  修正はテスト自身が `update_option( 'default_product_cat', 0 )` で自動付与を無効化する（`0`・空文字・option 無し・存在しない ID は term 15 の有無どちらでも `[]` と実測。ただし `[]` のアサーション自体は、存在しない ID を `wp_set_post_terms()` が読み飛ばすため削除済み ID を未解決扱いにしたかを判別できず、それは警告と `fully_resolved` のアサーションが担う）。`WooTestCase::set_up()` での全体固定は、自動付与を前提にする `ProductReaderTest` 等の環境を変えるため不採用。
  検証: 単独・別クラスの後・クラス全体の単独実行で PASS／`update_option` 行を外すと単独実行で再び FAIL、`ProductWriter::resolve_refs()` の `get_term()` 存在チェックを外すと警告アサーションで FAIL（ミューテーション）／絞り込みランダム順 seed 1〜12 は修正前に `ProductWriterTest` が先頭の 6 seed が FAIL・修正後は全て PASS／全体ランダム順 seed 987・1〜10 の 11 回（1113 件）が全て PASS／全 60 クラス単独実行が 60/60 PASS（修正前は 59/1）。`docs/review-backlog.md` の `fix-59-export-price-tax/R1-X1` を解消し、知見を CLAUDE.md「テスト方針」と `.claude/rules/woocommerce-api.md` に追記した。
- [x] **R3-0g: 受注のサンプルインポートを ID 指定取得にする**（issue #38）: `Importer::run_sample_page()` に `order` を追加し `fetch_order_by_remote_id()`（#46 で追加済み・日付窓の影響なし）で取得。サンプル実行でカーソル走査（`fetch_orders`）が呼ばれないことを回帰テストで確認
  **実装サマリ（2026-09-29）**: `Importer::run_sample_page()` の match に `'order' => $adapter->fetch_order_by_remote_id(...)` を追加し、`JobManager::SAMPLE_ID_FETCH_ENTITIES` に `order` を追加。`process_page()` のサンプルID解決を `match` にして `order` は `SampleSet::$order_remote_ids`（`SampleSelector` は元々選定・永続化していたが、読む箇所が無いフィールドだった。エクスポート方向の受注サンプルは別クラス `ExportSampleSelector`/`ExportSampleSet::$order_ids`〔Woo側int ID〕を使っており無関係）を使う。`LimitPolicy::DEFAULT_LIMITS['order']`（10）と `SampleSelector::SAMPLE_ORDER_LIMIT`（10）は元々一致しており上限側の変更は無し。
  `ids` 一括取得（要検証#18、日付窓が未検証）ではなく単一取得 `fetch_order_by_remote_id()` のループを採用（`docs/10-tasks.md` の対応方針どおり）。
  **信頼境界（原則8）の追補**: `order_remote_ids` が読まれていなかった間は無害だったが、消費されるようになった今 `SampleSelector` を防御的にした。`fetch_latest_orders($limit)` の「最新$limit件」はドキュメント上の契約でしかなく外部アダプタが超過件数・重複を返す可能性を排除できないため、product/customer と同じ形（連想配列で重複排除＋`array_slice`で上限）を `order_remote_ids` にも適用（`MockPlatformAdapter` に `latest_orders_override` を追加してテスト）。
  回帰テスト: `MockPlatformAdapter` に `fetch_orders()`（カーソル走査）の呼び出し回数カウンタ `$fetch_orders_calls` を追加し、`JobManagerTest::test_free_tier_caps_order_import_at_the_default_limit()` で「カーソル走査0回・`fetch_order_by_remote_id()` でサンプル10件を個別取得」を検証。`SampleSelectorTest` に契約違反アダプタ（`latest_orders_override` で $limit 超過・重複ありを返す）でも上限件数のユニークなIDに収まることを検証するテストを追加。両者とも `mutate-check.sh` で退行時に CAUGHT になることを確認済み。
- [x] **R3-0j: プレミアムプラン限定機能をベータ表示にし、既定オフにする（D24）**（issue #75）: `Capabilities` に `beta_features`（末尾に既定 `[]`）を追加し、ColorMe は `order_export`・`image_push` を宣言。Export タブで受注のチェックボックスを既定未選択にして「Beta」と説明を表示。「商品画像をアップロードする（Beta）」の選択肢を追加し既定オフ（実際に送るかを決める `ColorMeAdapter::should_push_images()` を「プレミアム かつ 設定オン」に。能力 `can_push_images` はプランだけで決まるまま。当初案〔能力メソッド自体を変える〕からの変更は下の実装サマリ参照）。実テストはせずモックで確認。UI 文言が増えるため R3-2（i18n）より前に入れる
  **実装サマリ（2026-09-29）**: `Capabilities::$beta_features`（末尾・既定 `[]`。`to_array()` が正規化）・`Support\ExportOptions`（`cbjp_export_options_{platform}`。厳密な `true` だけがオン）・
  REST `GET/PUT /settings/export-options/{platform}`（`is_bool()`・能力のある platform だけオン可・進行中 run は 409）・`ColorMeAdapter::should_push_images()`（プレミアム かつ 設定オン）・Export タブの Beta 表示と既定オフ。
  **D24 の当初案から 2 点変えた**（`docs/03` D24 実装参照）: (1) `capabilities()->can_push_images` はプランだけで決まる能力のままにし（設定で変えると UI がオンにする手段を失う）、実際に送るかは別メソッド `should_push_images()`。
  (2) 「画像オフで export 済みの商品が、オンにしても再送されない」制限は、オンの間だけ商品の checksum に印を混ぜて解消した（説明文で案内するだけにはしなかった）。
  mock アダプタ・モック HTTP で確認（実 API は未確認＝D24 の方針。`docs/03` D24 実装 7.）。`R3-0i` の (3) のプラットフォーム単位ロックは `PUT /settings/export-options` も囲む対象にする。
  `verify-with-mock-adapter` のテンプレートに `cbjp_verify_seed.capabilities` を追加し、SKILL.md に `push`・Studio との IPv6 ポート競合・checksum 検証の落とし穴を追記した。
- [x] **R3-0h: Pro 案内の件数を正確にし、Pro への言及を購入 URL の有無で切り替える**（issue #55）: totals に `unchanged` を追加し「移行できるが未移行」と「どの版でも移行できない」を分けて表示。フィルター `cbjp/limits/pro_url`（既定 `''`）を新設し `/limits` の `pro_url` で渡す。空なら Pro に触れない。詳細は `docs/03` §10.3「アップセル表示」
  **実装サマリ（2026-09-29）**: `JobRepository::empty_totals()`・`Importer`/`Exporter` に `unchanged`（`skipped` の内訳。checksum 一致スキップだけ）、
  `LimitPolicy::pro_url()`（http/https かつ host のある URL だけ `esc_url_raw()`、他は `''`）と `/limits` の `pro_url`・`platform` の `args` スキーマ。
  フロントは計算を純粋関数 `src/components/upsell-breakdown.ts` に切り出し（`dryRunEntityTotals()`/`buildUpsellLineData()`/`sanitizeProUrl()`）、
  `LimitsUpsellNotice` は見出しだけで Pro に触れる。**計画時の決定**: 在庫・レビューは内訳を出さない（dry-run が商品未移行の在庫を全件スキップするため）、
  「未移行」0 件なら行を出さない。JS 単体テスト基盤（`@jest/globals`・`npm run test:js`・CI／`quality.sh`）を追加した。
  WP 7.1 コアの `ExternalLink` が `rel` を付けないため明示した。実機確認（mock・Export タブ）と差分の詳細は `docs/03` §10.3「アップセル表示」の「実装（R3-0h）」。
  検証: PHPUnit 追加 27 件（`ImporterTest` 2・`ExporterTest` 2・`JobManagerTest` 1＋既存 1 件に assert 追加・`LimitPolicyTest` 18〔データセット込み〕・`RestControllerTest` 4）、
  Jest 33 件。`mutate-check.sh` で PHP 8 種（`unchanged` の加算 2・blocking を数える・上限スキップを数える・scheme／host／型の判定・`args`）と JS 11 種がすべて CAUGHT。
  review-loop R1（独立レビュー）の Medium 1 件（商品を移行し終えた後も在庫の行が出る）を修正。PR #87 の G1 で、新しい dry-run の一部のジョブが失敗・キャンセルしたときに前回の件数が残る問題（Copilot）を修正（`withoutDryRunTotals()`）
- [x] **R3-0m: 受注インポートの決済/配送マッピングを Import 側で設定・確認できるようにする（プレビュー警告の解消）**（2026-09-30 決定。**最優先: R3-0i / R3-0k より先に着手する**。issue は未起票。ブランチ `feat/r3-0m-mappings-tab`）
  **経緯**: 実店舗（F1-8 のちくわ実店舗）の受注を main（2abb1db）のビルドで dry-run したところ、**全件**に `payment_method_unmapped` と `shipping_method_unmapped` が付いた
  （`dist/cart-bridge-jp-dry-run-a3ef31b3-….csv`。`dist/` は未コミット）。残りの警告（数量 0 明細・税合計不完全・商品/顧客の未解決参照・管理者アカウント）は R3-0n で扱う。
  **原因**: 設定ストア `cbjp_settings_{platform}`（`payment_map`/`shipping_map`/`status_map`）はインポート（`Woo\Writer\OrderWriter` → `Woo\Support\MethodMap`）と
  エクスポート（`ColorMeAdapter::push_order()`）で既に共有されており、F1-8 では REST 直 PUT で設定すると警告 0 件になることを確認済み。しかし設定 UI（E2-1「Mapping settings」）は
  **Export タブにしか無く**、Import タブには案内も事前チェックも無い。dry-run CSV の `note` 列も空（`WarningCode::indicates_mapping_required()` が `CATEGORY_MAP_UNRESOLVED` しか対象に
  していない）ため、店舗オーナーは「マッピング未設定」という原因にも設定場所にも辿り着けない（F1-8 持ち越し (4) の未解消分）。未マッピングでも受注自体は作成される
  （決済は `payment_method=''` で ASP 側名称をタイトルに保持、配送行は `method_id=''` で ASP 側名称をタイトルに保持）が、Woo 側の決済/配送データが欠けたまま全件が警告になる。
  **方針（既存の REST `GET/PUT /settings/mappings/{platform}` と E2-1 の UI を再利用する。バックエンドの追加は 3. のみ）**:
  1. `ExportTab.tsx` の `MappingSection` と取得/編集/保存ロジック（GET と PUT が同じ `platformGenerationRef` の世代カウンタを共有する形。`.claude/rules/frontend.md`）を
     共有コンポーネント `src/components/MappingSettings.tsx` へ切り出し、`platform`・表示するマップ種別・`disabled` を props で受ける。**新設の Mappings タブ**
     （`src/tabs/MappingsTab.tsx`、`#/mappings`。タブ順は Connections / Mappings / Import / Export / Logs / Tools）がこれを使って 4 種
     （category は `can_create_category=false` のときだけ）を表示し、Export タブの「Mapping settings」カードは Mappings タブへ移す（Export タブには Mappings タブへの
     リンク付きの短い案内だけ残す。CSS クラスは `cbjp-export__mapping-*` → `cbjp-mappings__*` に改名）。Mappings タブは run の進行状態を持たないため、run 中の保存を
     無効化する Export タブの挙動は引き継がない（マッピングは `MethodMap` が参照のたびに読むので変更は次ページの処理から効き、未マッピング側＝フェイルクローズに倒れるだけで
     危険はない。R3-0i (2) の `GET /runs?platform=` が入ったら無効化を足す）
  2. **事前チェック**: Import タブで `order` が選択されているとき、`GET /settings/mappings/{platform}` を 1 回呼び（保存 UI は持たない。ColorMe 側は
     `categories.json`/`payments.json`/`deliveries.json` の 3 コール。絞り込みパラメータは足さない）、候補と保存済みマップから「未マッピングの決済方法 n/m・配送方法 n/m」を
     計算して `Notice`（warning）で「受注は取り込まれるが決済/配送方法が空のまま作成され警告になる」と案内し、**Mappings タブへのリンク（`#/mappings`）**を付ける。
     **Preview / Run import は止めない**（案内のみ。未マッピングでも取り込める現行設計を維持し、フェイルクローズは書込み側で担保済み）。計算は純粋関数
     （`src/components/mapping-status.ts`）に切り出して Jest でテストする。候補取得に失敗したとき（未接続・レート制限）は誤警告を出さない。`status_map` は未設定でも
     canonical の既定に落ちて警告にならないため件数に含めない
  3. `WarningCode::indicates_mapping_required()` に `PAYMENT_METHOD_UNMAPPED`/`SHIPPING_METHOD_UNMAPPED` を追加し、CSV の `note` を `mapping_required` にする
     （`indicates_pending_import()` は `! indicates_mapping_required()` で除外するので副作用なし。両コードは `indicates_unresolved_reference()` の対象外のため
     checksum キャッシュも不変）。R3-0k のカタログ文言（対処＝Mappings タブのマッピング設定）と揃える。
     **R1 で変更（2026-09-30、ユーザー承認）**: 両コードを `indicates_unresolved_reference()` にも加え、未マッピングの受注は checksum を保存しないようにした
     （下の実装サマリ）
  4. 文言・ドキュメント: `MethodMap` のクラス docblock（「現状は未実装のため常に空配列」。backlog `e2-1-mapping-ui/R1-X1`）と Export タブの説明文を実態に合わせる。
     `docs/00` §5 の UI 構成に「マッピング」タブを追加（2〈インポート〉/ 3〈エクスポート〉の記述も更新）し、`docs/03` §6「React アプリ」のタブ一覧を
     Connections / Mappings / Import / Export / Logs / Tools に更新する。`verify-with-mock-adapter` の SKILL.md にある Export タブでの設定手順も Mappings タブに読み替える
  **決定（2026-09-30、ユーザー確認済み）**: (1) 今回の dry-run を実行したサイトでは Export タブのマッピングが未設定だった（＝バグではなく導線の欠落）。
  (a) 配置は共有コンポーネントを使った**専用の Mappings タブ**（当初案の「Import/Export 両タブに表示」ではない）。(b) 事前チェックは**案内のみ**（Run import は止めない）。
  **要検証**: ColorMe の `payments.json`/`deliveries.json` に廃止（無効化）済みの決済/配送方法が含まれるか。含まれない場合、旧受注が参照する廃止 ID は候補に無く UI から
  マッピングできない（backlog `e2-1-mapping-ui/R2-L3` の source 側孤立と同根）。その場合は直近 dry-run の警告 detail（`cbjp_dry_run_items`）から未マッピング ID を集計して
  行を足す REST を v1.0 に入れるかを判断する（今回の店舗は決済・配送の方法すべてが現行の候補にあり F1-8 で全件解消できたため、結果次第で v1.0 では見送り可）。
  **自動マッピング（名称からの推定）は行わない**（楽観的既定はアーキテクチャ原則 9 に反する。候補として提案表示する案は v1.1 以降）
  **検証**: PHPUnit（`WarningCodeTest`/`DryRunReportCsvTest`）・Jest（`mapping-status`）・`tsc`/`npm run build`。`verify-with-mock-adapter` で Mappings タブの表示・保存の永続化
  （`cbjp_settings_{platform}`）、Import タブの事前チェックの件数表示とリンク、Export タブからカードが消えて案内だけ残ることを確認（mock の `mapping_candidates()` がテンプレートに無ければ足す）。実 API（テストショップ `ttka3lg60f`）で
  候補の描画と、受注 dry-run の当該 2 警告が設定後に 0 件になることを確認する。実店舗の受注の再 dry-run は店舗側で実施する
  **完了条件**: Mappings タブで決済/配送マッピングを設定でき、Import タブの案内から辿り着ける。設定後の dry-run で当該 2 警告が消える。CSV の `note` が `mapping_required` になる。
  `composer lint && composer analyze && composer test:wpenv` と `npm run lint && npm run build && npm run test:js` が通る
  **実装サマリ（2026-09-30）**: 方針 1〜4 を計画どおり実装。共有コンポーネント `src/components/MappingSettings.tsx`（GET/PUT で世代カウンタを共有）・
  `src/tabs/MappingsTab.tsx`・Import の `src/components/OrderMappingNotice.tsx`（受注選択中に platform ごと 1 回だけ取得。案内のみ）・純粋関数
  `src/components/mapping-status.ts`（設定先が現在の Woo 候補に無い値も未マッピングに数える）。Export タブは案内とリンクだけにし、世代カウンタを
  進める役を画像設定の取得 effect へ移した（消したマッピング取得 effect が唯一の進め役だった）。台帳に無い小さな追加として、行の読み上げラベルと
  `<thead>`（backlog `e2-1-mapping-ui/R1-L5`）と、対応先の候補が 0 件のときの案内を入れた。backlog `e2-1-mapping-ui/R1-X1`・`R2-L4` も解消。
  **checksum の扱い（review-loop R1 で変更、ユーザー承認）**: 未マッピングのまま本取込みした受注は、参照が解決済みなら checksum が保存され、
  後から設定しても checksum 一致で飛ばされて直らず、再 dry-run では警告だけが消えていた（独立レビューの指摘）。`PAYMENT_METHOD_UNMAPPED`/
  `SHIPPING_METHOD_UNMAPPED` を `indicates_unresolved_reference()` に加え、該当受注は checksum を保存せず次回の取込みで付け直す（代償: 未マッピングのまま
  運用すると該当受注は毎回再処理される。エクスポートは未マッピングの受注を送らないので影響しない）。この変更より前に取り込んで checksum が保存済みの
  受注は直らない（Tools のサンプル削除→再取込み）。案内文と Mappings タブの説明に「インポート前に設定する。未マッピングで取り込んだ受注は次の取込みで更新される」を足した。
  **要検証の扱い（2026-09-30 決定）**: 削除済みの決済/配送方法が候補 API に出るかは未実測のまま `docs/03` §6 に記録し、v1.0 では見送り。
  **検証**: PHPUnit 追加 9 件（`WarningCodeTest` 5〈データセット 3 件込み〉・`DryRunReportCsvTest` 1・`OrderWriterTest` 2・`ImporterTest` 1〈実 Writer で取込み→設定→再取込み〉）、Jest 15 件。`mutate-check.sh` で PHP 4 種（2 コードそれぞれを `indicates_mapping_required()` と `indicates_unresolved_reference()` から外す）と
  JS 4 種（Woo 候補の実在判定・自前キー判定・重複 ID・配送だけの未マッピング）がすべて CAUGHT（自前キー判定は最初 NOT CAUGHT で、継承した文字列値のテストを足した）。
  mock（`mockv`）で、未マッピングの dry-run に 2 警告と `mapping_required` → 管理画面で決済を 1 件保存（`cbjp_settings_mockv` に永続化）→ Import の案内が
  「決済 1/2・配送 1/1」→ 一時的な配送ゾーンを作り残りを設定 → 再 dry-run で 2 警告が 0 件・案内が消えることを確認し、撤去して検証前の状態に戻した。
  実 API（テストショップ）では候補の取得と描画（決済 1・配送 1・カテゴリ 1）と Import の案内「決済 1/1・配送 1/1」を確認した。**テストショップは受注 0 件**
  （非プレミアムのため API で受注も作れない）で、実 API の dry-run で警告が消えることは確かめられていない。実店舗の受注の再 dry-run は店舗側で実施する
- [x] **R3-0n: 受注 dry-run の残りの警告（数量 0 明細・税合計不完全・未解決参照）の原因確定と扱い**（R3-0m の後。issue は未起票。ブランチ `feat/r3-0n-order-dry-run-warnings`）: R3-0m と同じ CSV の残り。
  いずれも ColorMe 側の実データに起因するため、実データの確認（要検証）を先に行い、フィクスチャは匿名化して追加する。実データ（ちくわ実店舗の該当受注）の取得は
  ユーザー承認済み（2026-09-30）。取得環境（dry-run を実行したサイトの WP-CLI で生 JSON を取るか、wp-env を同じ店舗に接続するか）は着手時に決める。
  1. `order_line_quantity_invalid` と `order_line_tax_inconsistent` は 1:1 で対。`sale.details[].product_num` が 0（または負）の明細を
     `Woo\Writer\OrderItemBuilder` が数量 1 に倒し、`subtotal`（0 円）＜ 税抜単価 × 1 で税額が負になって 2 つ目の警告が付く（**2 つ目は 1 つ目のフォールバックの副作用**）。
     該当受注の生 JSON を取得して明細の正体（ColorMe 管理画面で数量 0 に編集された行か）を確認する。方針案: `product_num=0` かつ `subtotal_price=0` の明細は数量 0・金額 0 の
     まま取り込み、情報警告に落とす（`WC_Order_Item_Product::set_quantity(0)` の可否は wp-env で実測する。D10 #3「明細を消さない」は維持）
  2. `order_tax_total_incomplete`: `sale.totals`（nullable）が欠損し `sale.tax`（商品分のみ）へフォールバックした受注。欠損する条件（古い受注か）を実データで確認する。
     情報警告のまま、R3-0k のカタログで「送料分の税が含まれない可能性」と説明する
  3. `order_line_product_unresolved` / `order_customer_unresolved`: 該当 ID が ColorMe 側で削除済みか、インポートの除外条件
     （顧客は `mail` 欠損で除外）に該当するかを確認する。恒久的に解決しないなら `note` の `reference_pending_import`（先にインポートすれば消える）が誤案内になるため、
     削除済みを区別する扱いを検討する
  4. `customer_account_protected`: 管理者・スタッフのアカウントと同じメールのため仕様どおりゲスト受注にする。対応なし（R3-0k で文言）

  **原因の確定（2026-09-30、実データ）**: dry-run したサイトで読取専用の取得スクリプト（GET のみ・トークンを出さない・個人情報は取得時に伏せ字。
  `dist/` に置いた未コミットのもの）を SSH＋WP-CLI で実行し、該当する受注・商品・顧客を取得した（ホスティングの管理画面のコマンド実行は出力を返さず、
  `ABSPATH` は読み取り専用だったので、出力先を引数でホームへ向けた）。1. 数量 0 の行はすべて `product_num=0`・`subtotal_price=0`（単価は残る）で、該当受注はキャンセル受注
  （ColorMe はキャンセルで全明細を数量 0・受注合計 0 にする）か、一部の明細を外した受注。2. `totals` は 2019-09-09 以前の受注で null（2019-09-12 以降は全件にある）。
  該当受注はすべて送料があり、`sale.tax` は商品分のみなので警告は正しい。3. 未解決の顧客と、未解決の商品の大半は ColorMe で削除済み（404）。残りの商品（2018 年の受注）は
  ColorMe にもローカルにも実在する variable 商品で、受注後にオプションの軸が 1 → 2 に増えたため軸の数が合わず特定できない。どれも「先にインポートすれば消える」ではなかった。
  4. `customer_account_protected` の顧客はスタッフのアカウントと同じメールで、該当受注はいずれもキャンセル済みのテスト受注。要検証#18（`sales.json?ids=` が直近 7 日の制限を上書きするか）も同時に測り、上書きしないことを確認した
  （`docs/03` 要検証#18・#20〜#22）。
  **実装サマリ（ユーザー決定 2026-09-30）**: (1) `Woo\Writer\OrderItemBuilder` は数量と明細合計がどちらも数値として厳密に 0 の明細を、数量 0・金額 0 のまま**警告なし**で
  残す（税の分割もしない）。欠損・負数・小数・「数量 0 だが金額あり」は従来どおり数量 1＋`order_line_quantity_invalid`。wp-env で `set_quantity(0)` が保存・再読込で 0 のまま、
  受注合計・税も崩れないことを実測。エクスポート方向は変えない（数量 0 は引き続き blocking。`docs/03` D10「数量0の明細」）。(2) 新コード `order_line_variation_unmatched`（取込み済みの
  variable 商品で variation を特定できない。`ProductResolver::maps_to_variable_product()` で `order_line_product_unresolved` と分ける。エクスポート方向の既存
  `order_line_variation_unresolved` とは別名にした）。checksum はキャッシュしない（variation が後から入れば解決しうる）が、CSV の注記は付けない。
  (3) dry-run CSV の `note` に `reference_unresolved`（未インポート、またはASP側で削除済み・対象外）を加え、`order_line_product_unresolved`/`order_customer_unresolved` に付ける
  （`WarningCode::indicates_order_reference_unresolved()`。`reference_pending_import` から外した。checksum の扱いは変えない）。(4) 税合計の不完全はコード変更なし（送料 0 の受注が無く、
  絞り込みは効かない）。ASP へ実在確認して削除済みを別コードにする案は見送り（アダプタ・Canonical の拡張と API 呼び出しの増加。backlog `r3-0n/idea-remote-existence-check`）。
  **検証**: PHPUnit 追加 20 件（`OrderWriterTest` 14〈データセット 11 件込み〉・`OrderTransformerTest` 2・`WarningCodeTest` 3・`DryRunReportCsvTest` 1。既存 3 件の期待値を新コードへ更新）、
  匿名化した実受注のフィクスチャ 2 件（`sale_zero_quantity_line_detail.json`・`sale_without_totals_detail.json`）。`mutate-check.sh` で 12 種（数量 0 判定の両条件・小数の除外・
  税の分割の省略・コードの出し分け・`maps_to_variable_product()` の両側・note の分岐・retry 判定）がすべて CAUGHT。mock（`mockv`。設置したコピーだけ実受注 JSON を本物の
  `OrderTransformer` に通すよう書き換え）で受注の dry-run を回し、数量 0 の明細に警告が無いこと・商品/顧客の未解決が `reference_unresolved`・variation の不一致が新コード（注記なし）・
  `reference_pending_import` が 1 行も無いことを CSV の行で確認し、撤去して検証前の状態に戻した。実店舗の再 dry-run は店舗側で実施する（R3-0m と合わせて）。
  **PR #90 G1**: ColorMe の変換層が `product_num` の小数を切り捨て、欠損した `subtotal_price` を `'0'` にしていたため、数量0の判定より前に不正が失われていた
  （Copilot）。小計は `Cast::money_or_null()` で null のまま運び、整数でない `product_num` は受注ごと弾く（`Cast::to_exact_int_or_null()`）。文字列の0判定は float へ変換
  しない（`'1e-400'` のアンダーフロー。Codex）。テスト 12 件（データセット込み）を追加し、ミューテーション 3 種が CAUGHT。
  **G2**: G1 で小計に使った `Cast::money_or_null()` も小数・指数表記を切り捨てていた（両 bot が同じ指摘）ので、整数でない値を null にする
  `Cast::exact_money_or_null()` に替えた（テスト 5 件追加）。**G3**: JSON の `1e-400` は `json_decode()` で `float(0)` になるため、この2フィールドは float を
  受けない（Copilot。JSON 文字列を実際に復号して通すテストを含め 5 件追加）。Codex は G3 で収束、Copilot は依頼上限の 3 回
- [x] **R3-0i: 進行中 run の発見と、プラットフォーム単位の同時実行ロック**（issue #70・#57）: (1) 409（`run_in_progress`）の応答に進行中の run_id と種別を含め、UI はその run の進捗・キャンセルへ切り替える。(2) `GET /runs?platform=`（`args` でスキーマ検証。`/runs/active` は既存の `/runs/(?P<run_id>…)` に一致するため不可）でタブ表示時に照会。(3) `start_run`/`retry`/各種ツール（R3-0j の `PUT /settings/export-options` を含む）の「判定→状態変更」を、core の `WP_Upgrader::create_lock()` と同じ options への一意 INSERT による短時間ロックで囲む（`GET_LOCK()` は Galera・一部 DB プロキシで期待どおり動かないため不採用）。(4) ジョブの状態更新を「期待する状態のときだけ」の条件付き UPDATE にし、キャンセル直後の `completed` 上書き（`f1-6-import-ui/R1-X1`）を塞ぐ。v1.0 に含める（2026-09-26 決定）。大きければ (1)(2) と (3)(4) の2 PR に分ける
  **(1)(2) 実装サマリ（PR 1/2、issue #70。ブランチ `feat/r3-0i-active-run-discovery`）**: `GET /runs?platform=&status=active`（`JobRepository::find_active_runs_for_platform()`。
  run ごとに全ジョブから `status`〔running > paused > pending〕・`entities`・`has_failed_job`・`created_at`/`updated_at` を集約。`args` は GET 側だけ）と、409 `cbjp_run_in_progress` の
  `data.active_runs`（呼び出し元 7 箇所すべて。retry は自分の run を除外）。UI は全タブ（Import / Export / Tools / Mappings）が一覧を照会し、Import/Export は自分の種別の run を
  セクションに取り込み（`decideAdoption()`）、別タブの種別の run は案内（担当タブへのリンク＋キャンセル）してボタンを止める。Mappings の保存の無効化（R3-0m の宿題）も入れた。
  Import/Export はハッシュの `?platform=` を受け取る。ユーザー決定（2026-10-02）: 4 タブすべて／別タブの run は案内＋リンク（案内にキャンセルも置く）。
  詳細・実機確認・残る制限は `docs/03` §6「進行中 run の発見」。
  **(3)(4) 実装サマリ（PR 2/2、issue #57。ブランチ `feat/r3-0i-platform-lock`）**: `Support\PlatformLock`（core の `WP_Upgrader::create_lock()` と同じ options への一意 `INSERT IGNORE`。
  値 `"{期限}|{UUID}"` をハンドルにした比較付きの解放、期限切れ・壊れた値の CAS 回収、区間ごとの TTL〔60 秒／3,600 秒〕、shutdown での解放）で、`JobManager::start_run()`・`retry()` と
  REST の `run_sample_cleanup`・`rebuild_mappings`・`repair_states`（Scan 含む）・`save_export_options`・`resolve_push_intent` の「判定 → 状態変更」を囲む（`RestController::run_exclusively()`）。
  判定は `JobRepository::is_platform_busy()`＝進行中のジョブ＋処理中の Action Scheduler アクション（キャンセルした run がページを書き終えるまでも塞ぐ。ユーザー決定 2026-10-03）。
  状態は `JobRepository::transition()`（期待する状態のときだけ）で変え、`mark_failed()` は未終了のときだけ、`cancel_run()` は 1 文の条件付き UPDATE。キャンセル直後の `completed`/`paused`/`failed` の
  上書き・二重 Retry の二重エンキュー・次のジョブの二重起動を塞いだ。ロックを取れない・処理中のアクションがあるときも 409 `cbjp_run_in_progress`（一覧が空なら「少し待って再試行」の文言）。
  フロントエンドの変更なし。詳細は `docs/03` §5「同時実行のロックと条件付きの状態遷移」。
  **検証**: PHPUnit 追加 77 件（データセット込み。`PlatformLockTest`・`JobManagerConcurrencyTest`・`RestControllerLockTest`・`JobRepositoryTest` の追加分。割り込みは `query` フィルターで UPDATE の直前に
  別の要求の操作を差し込んで作る）。`mutate-check.sh` で 37 種（ロックの取得・CAS・比較付き解放・finally・shutdown、各遷移の条件、処理中のアクションの判定、各 REST の囲み・catch・文言・副作用の有無。review-loop の修正分 4 を含む）がすべて CAUGHT。review-loop R1 で、取得直後の `TTL_LONG` のロックを時計のずれで奪える欠陥（High）を時計のずれの余裕 300 秒で、ジョブの作成途中のキャンセルで run が pending のまま残る欠陥を run の残りのキャンセルで直した。
  PR #96 G1（Copilot）: 県コード修復の 1 バッチがロックの期限を超えうる（照会の待ちが積み重なる）ため、120 秒を過ぎたら新しい行に取りかからず cursor を返す（`PrefStateRepair::TIME_BUDGET_SECONDS`）。
  G2（Copilot）: 受注の照会は HTTP が最大 3 本（HTTP 1 本は最悪約 540 秒）で 1 行でも 900 秒を超えうるため、`TTL_LONG` を 3,600 秒（core の upgrader と同じ）にし、見積もりの前提を不変条件テストで固定。Codex は G1 で収束、Copilot は 3 回目で新規指摘なし
  wp-env の dev サイト（mock `mockv`）で `wp eval-file` を 2 プロセス同時に走らせ、ロックを持って止まった `start_run` の間にもう一方の `start_run`・`POST /runs`・クリーンアップが 409 になり run が 1 本だけ
  できること、ページの処理中（アクションを in-progress にして止めた）にキャンセルすると処理後もジョブが `cancelled` のままで、その間の `POST /runs`・再構築は 409、処理後は開始できることを確認して撤去した
- [x] **R3-0o: 受注のインポートで新規作成を最終ステータスで保存し、状態変化フックを発火させない**（issue #91。2026-10-01。ブランチ `fix/91-import-order-final-status`）
  **経緯**: R3-0n の版で実店舗の受注をインポートしたところ、プレビューは作成予定があるのに本番は「作成 0」で警告だけが付き、WooCommerce の受注一覧は「支払い待ち」に件数が出るのに一覧が空だった。
  読み取り専用の診断スクリプト（SSH＋WP-CLI。`dist/`、未コミット）で原因を確定した: `OrderWriter::write()` が `wc_create_order( pending )` で作ってから ColorMe のステータスへ `set_status()` していたため、
  保存時に「支払い待ち→完了」等の状態変化として `woocommerce_order_status_*` が発火し、他プラグインの完了時処理が取り込んだ全受注で動いていた（請求書プラグインの PDF 作成とメール送信〈メールは `SideEffectGuard` の
  `pre_wp_mail` で止まり未送信。ただし `wp_mail` フィルターで記録するメールログには残る〉、決済プラグインの売上確定）。請求書プラグインが商品の無い明細で `Error` を投げるため、ColorMe で削除済みの商品などの明細を持つ
  受注は作っては削除され、削除時にメモリ上の「完了」で件数キャッシュ（`OrderCountCacheService`）を減らしたため「支払い待ち」が増え「完了」が減った表示になっていた（DB の件数は正しく、支払い待ちは 0）。
  **実装サマリ**: 新規は `new WC_Order()` を組み立てて 1 回だけ保存し、`set_status()` の間だけ `woocommerce_default_order_status` を最終ステータスにして状態変化を記録させない（状態変化のフックは発火せず、保存の前後・`woocommerce_new_order`・明細の作成だけ。
  件数キャッシュは最終ステータスに加算）。保存の途中の失敗では作られた行を同じステータスのまま削除する。新規の completed では `wc_paying_customer()`（顧客の購入実績）を明示的に呼ぶ
  （状態変化を起こさないことで動かなくなる本体の他の処理は `docs/03`「受注の新規作成と状態変化フック」に列挙）。
  `wc_create_order()` が記録していた顧客 IP・ユーザーエージェント（インポート実行環境の値）は残らなくなった。**既存受注の更新で状態が変わる場合は従来どおり**（ユーザー判断。backlog `fix-91/update-status-hooks`）。
  review-loop R1（独立レビュー）で追加: 1 回だけの保存は `woocommerce_update_order` を起こさず、即時取込みモードの店舗では Analytics から抜けるため `woocommerce_schedule_import` で予約する／
  作成後の後処理（Analytics の予約・購入実績）の失敗では受注を消さずログに残す（消すと mapping が無いまま次回に重複作成）／`save()` が例外を握りつぶして ID 0 を返したら `ORDER_CREATE_FAILED` で見送る。
  PR #92 G1（両 bot）: 件数キャッシュの加算（優先度 10）より前の `Error` で受注を消すと 1 件少なくなるため、後始末の削除の後に公開クラスの `OrderCountCache::flush()` で数え直させる。
  PR #92 G2（Codex）: 他プラグインの `woocommerce_new_order` が `Exception` を投げると `save()` は握りつぶして ID を返すが明細は保存されない（以前は 2 回目の保存が保存していた）。
  新規作成では追加した明細に ID が付いたかを確かめ、欠けていれば件数キャッシュを捨ててもう 1 回保存し、それでも欠ければ受注を消して例外にする（明細の欠けた受注を mapping ごと確定させない）。
  **検証**: PHPUnit 追加 16 件（データセット込み。加算前の失敗で件数キャッシュが一致・`woocommerce_new_order` の `Exception` でも明細が残る・明細を保存できない受注は消して例外、Analytics の予約・後処理の失敗で受注が残る・stale ID からの作り直しでも状態変化なし・ID 0 で `ORDER_CREATE_FAILED`、新規作成で状態変化フックが 0 回〈completed/processing/cancelled/pending〉・完了時に例外を投げるプラグインがあっても商品の無い明細の受注が作成される・
  明細の保存で `Error` が出たら受注を残さず件数キャッシュが DB と一致・`paying_customer` は completed のときだけ・更新は従来どおり状態変化）。`mutate-check.sh` で 13 種（フィルター・`wc_create_order()` への差し戻し・
  `wc_paying_customer()`・その条件・失敗時の削除・Analytics の予約・後処理の失敗の握りつぶし・ID 0 の見送り・失敗時の件数キャッシュの破棄・
  明細の確認・2 回目の保存・その後の件数キャッシュの破棄・再確認）がすべて CAUGHT。mock（`mockv`）で他プラグイン役のフックを足して**本番インポート**を回し、商品の無い明細を持つ完了の受注が作成され、状態変化フックが 0 回、件数キャッシュが
  DB と一致することを確認して撤去した。実店舗では修正版で再インポートし、作成されなかった受注が作成されることを店舗側で確認する（事前に `wp cache flush` で件数表示を直す）
- [x] **R3-0p: run の実行中は接続を解除させない**（backlog `r3-0i-platform-lock/plan-X1` の B 案。2026-10-04 ユーザー決定。issue は未起票。ブランチ `feat/r3-0p-guard-disconnect`）
  **経緯**: `DELETE /connections/{platform}`（Connections タブの Disconnect / Clear saved credentials。確認なしの 1 クリック）は同時実行の判定もプラットフォームのロックも通らず、
  run の実行中に押すと、export は送信の失敗を 1 件ずつ skipped にして completed になり（何も送っていないのに「完了」）、import は次のリクエストで処理するページから失敗して後続のジョブが
  pending のままプラットフォームを塞ぎ、別のショップで認可し直すと、止まっていない run（paused 等）や Retry した run の続きがそのショップに対して走っていた（R3-0i (3)(4) の計画時の設計レビューで発見）。
  **実装サマリ**: `RestController::delete_connection()` の削除を `run_exclusively()`（`is_platform_busy()`＋`PlatformLock`、`TTL_SHORT`）で囲んだ。`run_exclusively()`/`run_in_progress_error()` に
  省略できる `$run_message` を足し、一覧に run があるときだけ切断用の文言「A run on this platform has not finished yet. Cancel it on the Tools tab (or the Import or Export tab) first, then try again.」にする
  （一覧が空〈ロックの保持中・キャンセルした run の書き終わり待ち〉は既存の「Try again in a moment.」。コード・`data.active_runs` は他のルートと同じ）。案内先に Tools タブを先に挙げるのは、
  Import/Export タブが接続済みのプラットフォームしか並べず、要再接続（切断が唯一の復旧手段）で行き止まりになるため（review-loop R1-1）。フロントエンドは無変更
  （`ConnectionCard` はサーバーの文言をそのまま出す）。再接続側（C 案）と接続先ショップの照合（D 案）は対象外（backlog `r3-0p-guard-disconnect/plan-X1-rest`）。詳細は `docs/03` §5「同時実行のロックと条件付きの状態遷移」・§6。
  **検証**: PHPUnit 追加 7 件（`RestControllerLockTest` のデータプロバイダに切断を足し、ロックの保持中・キャンセルした run の書き終わり待ちに 409 で資格情報が残ること〈書き終わり待ちのテストは
  全要求で副作用が無いことも確かめるようにした〉・成功後にロックが残らないこと、専用 4 件〈run の実行中は切断用の文言と `active_runs`・資格情報が残る／キャンセルの後は 200 で消える／
  要再接続で止まった run があっても同じ 409／ロックを取れなかった側でも切断用の文言〉）。`mutate-check.sh` で 5 種（判定より前に削除する・切断用の文言を渡さない・
  一覧が空でも切断用の文言にする・ロックを取れなかった側で文言を渡さない・未接続なら判定を飛ばす）がすべて CAUGHT。wp-env の dev サイト（mock `mockv`）で `rest_do_request()` により、run の開始後の切断が 409・切断用の文言・資格情報が残る、
  キャンセルの後は 200 で資格情報が消えることを確認して撤去した
- [x] **R3-0k: 警告カタログと CSV の説明列**（2026-09-28 決定。issue は未起票。ブランチ `feat/r3-0k-warning-catalog`）: いま UI に出るのは警告の件数だけで、内訳は dry-run の CSV に警告コード（`WarningCode` の全定数。52 個）がそのまま並ぶ。店舗オーナーが原因と対処を分かるように、
  `Woo\WarningCatalog`（コード → severity〈blocking／要対応／情報〉・原因・対処。英語 `__()`、日本語は R3-2 で `languages/cart-bridge-jp-ja.po` に同梱。`{code}:{detail}` の detail は `%s` に差し込む。外部アダプタの未知のコードは「不明な警告（コード）」にフォールバック）を新設し、
  dry-run の CSV（`Admin\DryRunReportCsv`）の**末尾**に `message`・`action` 列を足す（既存の列・`note` は変えない）。テスト（リフレクション）で「`WarningCode` の全定数にカタログがある」ことを強制し、文言の無い警告コードが出荷されないようにする。
  最初に書くのは、止める警告（`indicates_export_blocking()` と D22 の `VARIATION_STOCK_MANAGEMENT_MIXED`）と対処が要る警告の約 25 種で、残りは汎用文言でよい。**画面での警告要約（案 R3-0l）と実 run の項目別警告の保存は v1.0 に含めない**（DB 書込量とプライバシーの判断が要るため v1.1 以降）。
  UI 文言が増えるため R3-2（i18n）より前に入れる。D22/D23 の対処（在庫管理・在庫状況を揃える／Any を具体値に分ける）の文言は R3-3 の FAQ と共通にする
  **実装サマリ（2026-10-07）**: 新設 `Woo\WarningCatalog::describe( $warning, $direction )` が警告を重大度（`blocking`／`action_required`／`info`、カタログに無いコードは `unknown`）・原因（`message`）・
  対処（`action`。店舗が何もできない警告は空）で説明し、dry-run の CSV の**末尾**に `severity`・`message`・`action` の 3 列を足した（既存の列・`note` は不変）。計画時のユーザー回答で、
  台帳の 2 列に `severity` を足し、98 個（台帳を書いた時点の 52 個から増えた）すべてに個別の原因を書いた（約 25 種＋汎用文言の案から変更）。同じコードでも取込みとエクスポートで意味が違う
  （通貨の不一致・価格・数量・金額・クーポンの制限・決済／配送の未マッピング）ため、カタログは「コード × 向き」で引き、向きは run の種別から決める（`DryRunReportCsv::direction_for_job_type()`）。
  `customer_account_protected` だけは行の種別でも分ける（顧客の行は書かないので `blocking`、受注の行はゲスト受注として書くので `action_required`。PR #108 G4 の Codex の指摘）。
  detail が人に意味のあるコードは detail を差し込んだ文言（`%s`）を持ち、壊れた翻訳で `sprintf()` が例外を投げても CSV を止めず差し込まない文言へ倒す。外部アダプタ独自のコードは「Unknown warning (コード)」。
  CSV はユーザーの言語で書く（`get_run_report()` が書き出しの間だけ `switch_to_user_locale()`。リンクは非 JSON の要求で `_locale=user` が効かない）。文言は英語の `__()` で、日本語は R3-2。
  各コードの実際の挙動（書くか・代替値・detail の中身・dry-run に出るか）は発生元を読んで確かめ、`WarningCode` の docblock の誤り（止めるのに「警告のみ」と書いていた `variation_axis_limit_exceeded`・
  `order_line_tax_class_unsupported`・`tax_status_not_taxable` ほか）を直した。調査で見つかった既存の挙動（設定次第の警告の checksum、顧客を含めない受注のエクスポート等）は backlog `r3-0k-warning-catalog/plan-*`。
  詳細は `docs/03` §「dry-runレポートCSVの実装詳細」の「警告の説明の列（R3-0k）」。
  review-loop R1 の独立レビュー（98 個を発生元と照合）で、文言の事実誤り（ターム衝突の detail を親の ID と読める、税込に換算できない価格を「定価が無い」と説明、在庫状況の混在、
  税計算 OFF で存在しない画面の案内、取り込み直すと消える手修正の案内ほか）を直し、`sale_end_date_not_pushed` を `action_required` にした。言語のテストは切替中に書くことを固定していなかったので作り直した。
  **検証**: PHPUnit 追加 20 件（データセット込み。`WarningCatalogTest` 11〈全定数が両方向で説明されること・`indicates_export_blocking()` のコードはエクスポートで `blocking`・プレースホルダを残さない・detail の有無・
  detail の `%`・detail 付きの文言の書式・壊れた翻訳・向き・不明なコード／向き〉、`DryRunReportCsvTest` 5、`RestControllerTest` 4〈ユーザーの言語・外側の切替を戻さない・run の種別の向き〉）。`mutate-check.sh` で 13 種がすべて CAUGHT。
  wp-env の dev サイトで mock（`mockv`）の取込み・エクスポートの dry-run を回し、CSV を実 HTTP で取得して列・向きごとの文言・detail・BOM を確認し、一時 mu-plugin でサイトの言語を ja に見せて、
  ユーザーの言語（`en_US`）で書かれること・未設定ならサイトの言語になることを確認して撤去した
- [x] **R3-1: 全件E2Eリハーサル**（カラーミーのテストショップ〔非プレミアム〕で実データ移行。インポート→エクスポートの往復でデータ欠損確認。**対象は商品・顧客・在庫とインポート全般。プレミアムプラン限定の受注エクスポート・商品画像アップロードは D24 によりベータ扱いで実テストの対象外**（モックでの確認のみ。プレミアムのテストショップが用意できた時点で v1.0.x 以降に実施）。**無料版サンプル→上限解除→本移行の重複なし確認（上書きポリシー両方）=D16** を F1-8 の結果と合わせて最終確認。**R3-0a/b の確認（ブロック→解除→再 export）も含める**（送信結果が不明な状況は実 API で意図的に起こせないため、モックで確認する）。 **あわせて、`tax_type=excluded` の店舗でセール中バリエーションの `option_market_price`（定価）の税基準が `option_price` と同じか実機確認する**〔`docs/03` §10.2「価格の税込正規化とバリエーションのセール価格」の要検証。PR #61 Copilot G1-1〕。**さらに、テストショップの税設定に対して hidden 安全策（`ProductTransformer::requires_hidden_safeguard()`。作成時だけ hidden にし更新には効かない）が発動するかを確認する**〔issue #78・backlog `fix-72-partial-push/R1-X1`。発動する店舗が現実的にあるなら v1.0 に含めるかを再判断する〕）
  **実施サマリ（2026-10-05、PR #103。記録: `docs/reviews/feat/r3-1-e2e-rehearsal/rehearsal.md`）**: テストショップ（非プレミアム・税抜・四捨五入）に商品 55・会員 12 を API で投入し
  （往復で値が変わりうる条件を突く商品を含む）、ゲスト注文 3 件をユーザーが作成。手順と道具はプロジェクトスキル `rehearse-colorme`（`.claude/skills/rehearse-colorme/`。
  店舗の login_id が一致しなければ何もしない、ジョブは WP-Cron と同じ未ログイン＋kses ありで処理する）として残した。
  - **インポート全般**: dry-run → サンプル（受注の上限を 2 に下げて上限が効くことも確認）→ キャンセル → 上限解除の本移行 → 再取込みで、**重複なし・サンプルとキャンセル前の分は作り直さず `unchanged`（D16）**。
    価格（税込換算・定価ありのセール・軽減税率・端数 x.5 の境界＝四捨五入を確認）・在庫・会員の住所/県/電話/法人名・受注の合計と税額・決済/配送の対応が ColorMe と一致
    （`check-import` とスナップショットの確認。電話・受注の税額・決済/配送は review-loop で `check-import` に足し、取り直したスナップショットでも一致）。
    F1-8 の持ち越しは (1) 中断→再開を実施、(2) 上書きポリシーの選択式は v1.0 の対象外と決定（D16 を改訂、backlog `r3-1-e2e-rehearsal/D16-overwrite-policy`）、(3) 端数の実測を実施、
    (4) マッピング UI は R3-0m で実装済み、(5) 実店舗（クラフト実店舗）の残りはテストショップでの確認で代える（2026-09-28 の方針）。クーポンはこの契約では作れず対象外（F1-8 で実店舗の取込みを確認済み）
  - **往復エクスポート**（取り込んだ実体を同じショップへ PUT）: 会員の値は変化なし。商品は**空の型番に仮 SKU・会員限定販売が全員に販売可能に・在庫未設定が 0・説明の埋め込みの消失・
    継承価格の明示化・可変商品の価格・商品名の `&amp;`** が ColorMe に書き戻された（静的解析で挙げた 9 項目のうち、Woo の税設定に依存する 2 項目以外を実データで確認）。
    逆向き（Woo で作成 → export → 再取込み）も、Woo で作った実体を ColorMe 由来の値で上書きした。→ **D25（実体は作られた向きにだけ更新する）を決定、R3-1a**
  - **作成エクスポート**（Woo で作った商品・顧客）: 税抜換算・軽減税率・在庫・2 軸のバリエーションは正しい。**`zero-rate` の商品は作成時 hidden（安全策が発動）→ 次の更新で課税商品として公開**（issue #78 を実 API で再現）→ R3-1d。
    バリエーションの `option_market_price` は管理画面にもストアフロントにも表示されず、税基準は判定不能・影響なしとして要検証を閉じた（docs/03 §10.2）。顧客の姓名の順は既知の制限どおり
  - **その他の所見**: 商品名の `&`・`<…>` の保存結果がランナー（WP-Cron は未ログインで kses あり／非同期ランナーは管理者の Cookie を転送）で変わる（R3-1b）、
    海外の会員の更新が毎回 422（ColorMe は更新でも住所が必須。swagger と違う）（R3-1c）、説明の `<script>`・`<style>` の中身が文字として残る（R3-1c）。
    日本語でインストールした WooCommerce では軽減税率の税区分が `reduced-rate` でなく、取込みで 8% の商品が 10% になる（R3-1e。英語でインストールした開発サイトでは表に出ない）。
    ColorMe API の実測（小カテゴリだけの PUT は拒否、会員の PUT の必須項目、誕生日がこのショップでは保存されない）は `.claude/rules/adapters-colorme.md` に記録
  - **mock**（`verify-with-mock-adapter`）: R3-0b の既存 example が ALL PASS。R3-0a 用に `examples/partial-push/` を新設し ALL PASS（`Exporter` を壊すミューテーションで FAIL を確認）。
    プレミアム限定ベータは mock の能力をプレミアム相当／非プレミアム相当に切り替え、画像の設定（200／400）と受注 export のジョブの有無を確認
- [x] **R3-1a: 実体は作られた向きにだけ更新する（D25、issue #98）**（取り込んだ商品・顧客・在庫は export しない〔情報の警告〕、export で作った実体は取込みで上書きしない。
  出自の判定は `_cbjp_platform`＋`_cbjp_remote_id` だけでは足りない〔メールで再利用した既存顧客、export で作った実体を再取込みした場合にも付く〕ので、作成の印〔顧客は既存の
  `_cbjp_created_by_import`、商品は新設が要るか〕を計画で決める。`rehearse-colorme` の手順 2・3 を `reset-local` からやり直して確認）
  **実装サマリ（2026-10-05、PR #104。ブランチ `feat/r3-1a-direction-of-origin`。テストショップでの確認は下の「R3-1 再リハーサル」）**: 計画で、印は新設せず `_cbjp_platform` を「取込みで結ばれた」印に使い、
  「誰が作ったか」ではなく「誰が紐づけたか」で判定すると決めた（メールで採用した顧客は取込み側、顧客は `_cbjp_created_by_import` も見る。判定は新設 `Woo\Support\EntityOrigin`）。
  エクスポート側は各 Reader が `ReadItem::$linked_by_import` を立て、`Exporter` が `linked_by_import_not_exported` でスキップする（mapping の有無によらず。mapping・無料枠・intent に触れない）。
  インポート側は `WooRepository`/`DryRunRepository` が writer の前で、在庫は `StockWriter` が解決した対象で判定し、`linked_by_export_not_imported` でスキップする（local_id 0。mapping に触れない）。
  どちらも mapping があれば `unchanged` に数える。対象は商品・顧客・在庫・受注・クーポン。ユーザー決定で、エクスポートのサンプル選定から取込み品を除き、WC の商品複製でリンクのメタを写さないようにした。
  backlog `R2-M-checksum-shared-row` は解消。詳細は `docs/03` §10.2「往復の扱い（D25）」。**往復は想定しない（2026-10-06 ユーザー決定）**: このプラグインは一方向の移行に特化し、
  D25 は誤って往復したときの安全策。往復でしか起きない取りこぼしは「往復を想定しないため対応しないもの」として直さない（R3-3 で readme・FAQ に明記する）。
  review-loop R1 の独立レビューで、エクスポートで作った可変商品のバリエーションが取込み受注の明細から解決できなくなる取りこぼしを見つけ、`ProductResolver` が variant の mapping で結ばれた
  バリエーションも対象にするよう直した（ほかにサンプル選定の走査の並び・重複、在庫行のテスト、リハーサル用スクリプトのフェイルクローズ）。
  **検証**: PHPUnit 追加 53 件（実配線の往復 `RoundTripOriginTest` を含む）。`mutate-check.sh` で 38 種（Exporter の分岐と `unchanged`、Reader 5 つと在庫行の全 5 か所、顧客の作成の印、
  リポジトリ 2 つ、実体の有無、保護ロール、空のプラットフォーム、StockWriter の判定と順序、Importer の `unchanged`、サンプル選定 8 か所、複製フィルターの登録、受注明細のバリエーション解決 3 か所〔別プラットフォームの mapping を含めない〕ほか）がすべて CAUGHT。
  `rehearse-colorme` に `seed-woo prefix=`・`diff side=woo`・`check-import` の `LINKED_BY_EXPORT` を追加した。テストショップでの再リハーサル（手順 2・3）は 2026-10-05 のユーザー判断で後回しにし、
  R3-1b〜e の実装後にまとめて行った〔2026-10-06 ユーザー決定〕。**テストショップでの確認は 2026-10-08 の「R3-1 再リハーサル」で済ませた（下）**
- [x] **R3-1b: 商品名を実体参照にして保存し、エクスポートで戻す（issue #99）**（ランナーによらず同じ結果にする。`context=cron`／`admin` の両方で取り込んで確認。
  R3-1a〔D25〕で取り込んだ商品はエクスポートしなくなったので、エクスポートで戻す側の対象は Woo 生まれの商品だけになった）
  **実装サマリ（2026-10-06、PR #105。ブランチ `feat/r3-1b-product-name-entities`。テストショップでの確認は下の「R3-1 再リハーサル」）**: 新設 `Woo\Support\HtmlText` に平文⇔HTML の変換を集約。
  取込み（`ProductWriter`）は名前の `&` `<` `>` を実体参照（二重に符号化）・`\` を `&#092;` にして保存し（引用符はそのまま）、説明・短い説明は Writer 自身が `wp_kses_post()` してから保存する。
  エクスポート（`ProductReader`）は名前を `html_entity_decode` で平文へ戻し、WP が常に実体参照で保存するターム名（グローバル属性の値。`VariationAxisResolver`・`ProductReader::options()`）も戻す。
  push intent の一覧の商品名も戻す。計画時のユーザー回答で、issue の対応案（`esc_html()`）から方式を変え、ターム名・説明・push intent の表示を範囲に含めた。
  既存の取込み済み商品は checksum が変わらないので書き直さない。対象外（受注の明細名・クーポンの説明・説明の `\`）は backlog。詳細は `docs/03` §10.2「商品名の保存形式（R3-1b、issue #99）」。
  `rehearse-colorme` の `check-import` は名前を表示どおりの文字で比べ、保存値が実体参照の形かも確かめるようにし、`seed-woo` に名前が実体参照の商品（`ZZW-6`）を足した。
  review-loop R1 の独立レビューで、制御文字（kses の `wp_kses_no_null()` が WP-Cron でだけ消す）を `from_plain()` が先に消すようにし、更新のテストが `wp_update_post()` を通っていなかったのを直した。
  **検証**: PHPUnit 追加 32 件（データセット込み。未ログイン〈kses あり〉と管理者で保存した名前・更新・バリエーション名・説明・制御文字の一致、Writer→Reader の往復、ターム名、不正な UTF-8）。
  `mutate-check.sh` で 15 種がすべて CAUGHT。wp-env の dev サイトで実際の Writer/Reader により WP-Cron の条件と管理者の保存結果が一致することを確認した。
  **テストショップでの確認は 2026-10-08 の「R3-1 再リハーサル」で済ませた（下）**
- [x] **R3-1c: 海外会員の更新を警告つきでスキップ（issue #100）＋説明の `<script>`・`<style>` を中身ごと除去（issue #101）**（小さな修正 2 件。1 PR にまとめてよい。
  R3-1a〔D25〕で取り込んだ会員はエクスポートしなくなったので、#100 の対象はエクスポートで作った〔Woo 生まれの〕海外の顧客の更新だけになった）
  **実装サマリ（2026-10-06、PR #106。ブランチ `feat/r3-1c-customer-update-and-script-strip`。テストショップでの確認は下の「R3-1 再リハーサル」）**: #100 は `CustomerTransformer::to_update_payload()` が名前（空白だけでない・50 文字以内）と
  住所 3 点を作成と共有の判定で確かめ、そろわなければ `null`、`ColorMeAdapter::push_customer()` が作成と同じ `customer_required_field_missing` で PUT を送らずにスキップする（既存の mapping は残る）。
  作成側にも名前が空のスキップを足した。理由の見せ方は計画時のユーザー決定で警告コードだけ（ログは足さない。dry-run には出ない既知の限界）。backlog `e2-3-push-customer/G1-name-length-on-update` は解消。
  #101 は新設 `Woo\Support\HtmlText::sanitize_post_html()` が `<script>`・`<style>` を中身ごと除いてから `wp_kses_post()` し、取込みの `Cast::sanitize_html()` と `ProductWriter` の説明・短い説明が使う
  （タグの区切りはブラウザに合わせ、属性値・CDATA の中の文字は開始タグにしない・文字の `<` は `&lt;` にする・閉じタグ無しは除かない・コメントの中も除く・区切りは `strpos()` で探し PCRE の上限・二乗の探し直しを避ける。既知の限界は backlog `r3-1c-customer-update-and-script-strip/R2-L1`）。詳細は `docs/03` §10.2「名前・住所がそろわない顧客の更新と説明の `<script>`・`<style>`（R3-1c）」。
  `rehearse-colorme` の `check-import` は script/style の中身が Woo の説明に残っていれば MISMATCH にし、手順 3 に顧客の更新のスキップの確認を足した（issue の「C05 で確かめる」は D25 で C05 がエクスポートされなくなったため置き換え）。
  **検証**: PHPUnit 追加 53 件（データセット込み。計 1631）。`mutate-check.sh` で 26 種（除去 18・顧客 8）がすべて CAUGHT。review-loop R1 の独立レビューで、属性値・コメント・CDATA の中の `<script>` という文字から後ろの説明を消す問題を見つけ、開始タグをタグの区切りで見つける形に作り直した（あわせて二乗の探し直しも解消）。R2 の独立レビューで、その作り直しが `容量 < 500ml` のような文字の `<` の後ろの `<style>` を見落とすと分かり、`<` の扱いとコメントの扱いをブラウザ・kses に合わせた。PR #106 のゲートで、除去の前に kses と同じく制御文字を消すようにし（Codex G1-2）、`check-import` の判定を WP の HTML API で作った期待値との完全一致に作り直した（Copilot G1〜G3）。wp-env の dev サイトで実際の `Cast`・`ProductWriter`（WP-Cron の条件と管理者）・`push_customer()` を通して確認した。
  **テストショップでの確認は 2026-10-08 の「R3-1 再リハーサル」で済ませた（下）**
- [x] **R3-1d: 標準・軽減以外の税区分の商品と価格を換算できない商品のエクスポートを止める（issue #78、2026-10-05 に v1.0 へ含めると決定）**（hidden 安全策で作成して後から公開される経路を無くす。D22／D23 と同じ止める警告。**先に R3-1e の方針を決める**: 日本語でインストールした Woo では軽減税率の税区分が `reduced-rate` でないため、そのままでは日本の店舗の軽減税率の商品がすべて止まる）
  **実装（2026-10-06、PR #107。R3-1e と 1 つのブランチ `feat/r3-1de-tax-class-detection`。テストショップでの確認は下の「R3-1 再リハーサル」）**: 下の R3-1e の実装サマリにまとめた
- [x] **R3-1e: 軽減税率の税区分の見分け方（issue #102。D26、2026-10-05 決定）**（WooCommerce は既定の税区分を翻訳された名前〔日本語は「軽減税」〕から作るので、スラッグ `reduced-rate` 決め打ちでは日本語でインストールした店舗で外れ、取込みで 8% の商品が 10% になる〔金銭〕。**JP の税率が 8%／10% の税区分を自動判定する**。必要な税率が Woo に無い場合は dry-run で先に税率を作るよう促す。R3-1d と合わせて実装する。
  **R3-1a〜e をすべて実装した後、テストショップで `rehearse-colorme` の再リハーサルをまとめて行う**〔2026-10-06 ユーザー決定。PR ごとには行わない。お試し期限 2026-10-22 まで。
  R3-1a〔D25〕の確認手順は `docs/reviews/feat/r3-1a-direction-of-origin/final-report.md`〕）
  **実装サマリ（2026-10-06、PR #107。R3-1d と 1 つのブランチ `feat/r3-1de-tax-class-detection`。テストショップでの確認は下の「R3-1 再リハーサル」）**: `Woo\Support\TaxClass` が税区分を **JP の税率**で分類する
  （JP 10% → 標準、8% → 軽減、それ以外〔0% を含む〕→ unsupported、JP の税率が分からない → unconfigured。標準 `''` は JP の税率が無ければ標準〔review-loop R1 のユーザー決定でエクスポートは `''` も税率で分類〕。税率が 1 件も無い既定名〔`reduced-rate`・「軽減税」〕は軽減）。
  正規化モデルの `'reduced-rate'`（`CanonicalProduct::TAX_CLASS_REDUCED`）は Woo のスラッグではなく記号と位置づけ直した（英語でインストールした店舗では checksum が変わらない）。
  取込みは JP 8% の税区分へ入れ、無ければ税率の無い既定名の税区分（`tax_rates_not_configured`）、それも無ければ標準に倒して `reduced_tax_class_not_found`（本実行は止めない。商品だけ checksum を保存しない）。
  dry-run の CSV の `note` は `tax_setup_required`。エクスポートは標準・軽減以外の税区分の商品・公開バリエーションを `tax_class_unsupported`／`variation_tax_class_unsupported`（blocking）で止める（R3-1d）。
  ColorMe の hidden 安全策を削除し、`push_product()` は送れない商品（記号以外の税区分・価格を 1 件も換算できない〔`product_price_not_convertible`。dry-run には出ない既知の限界〕）を作成も更新もせずスキップする。
  計画時のユーザー回答: 判定の税率は法定税率の定数、8% の税区分が無くても取込みは止めない、税率の無い既定名の税区分は軽減とみなす、ColorMe 側の換算不能は本実行のスキップのみ。
  backlog `fix-72-partial-push/R1-X1`（#78）と `e2-3-push-product/R1-L1`（#102）は解消。詳細は `docs/03` §10.2「税区分の見分け方とエクスポートの止め方（D26、R3-1d/e）」。
  `rehearse-colorme` に `tax-classes`（日本語／英語インストールの既定税区分の切替）を足し、`check-import` は税率の表から求めた JP 8% の税区分で比べ、`seed-woo` は税区分を税率で選ぶようにした。
  **検証**: PHPUnit 計 1683（main から +52。hidden 安全策を前提にしたテストは作成・更新しないことの確認に書き換え）。`mutate-check.sh` で 44 種（review-loop R1 の 7 種・ゲート G2 の 1 種を含む）がすべて CAUGHT。wp-env の dev サイトで `tax-classes mode=ja` にして、実際の `ProductWriter`（WP-Cron の条件と管理者）・`ProductReader`＋`Exporter` の dry-run で確認した。
  **既知の限界**: この変更より前に日本語の Woo へ取り込んだ軽減税率の商品は、checksum 保存済みのため再取込みでは直らない。取込みの標準の商品は常に `''` に入る（`''` に 10% 以外を入れた店舗では誤る。backlog `r3-1de-tax-class-detection/R1-X1`）。
  **テストショップでの確認は 2026-10-08 の「R3-1 再リハーサル」で済ませた（下）**
- [x] **R3-1 再リハーサル: R3-1a〜e をテストショップでまとめて確認する**（2026-10-06 ユーザー決定。PR ごとには行わない。**お試し期限 2026-10-22 まで**）
  `rehearse-colorme` を `reset-local` からやり直し、各タスクの確認をまとめて行う:
  R3-1a〔D25〕は手順 2・3（確認手順は `docs/reviews/feat/r3-1a-direction-of-origin/final-report.md`）、
  R3-1b は `run context=cron|admin` → `check-import` と `ZZW-6` の作成エクスポート、
  R3-1c は説明の script/style の除去（`check-import`）と手順 3 の顧客の更新のスキップ、
  R3-1d/e は `tax-classes mode=ja` での取込み・作成エクスポートと、ゼロ税率の商品が止まること
  **結果（2026-10-08、`main` の `d627e41`）**: すべて想定どおり。`context=admin`／`cron` の取込みはどちらも `check-import` の食い違い 0 で、Woo の商品・バリエーション 100 件の値が全件一致（R3-1b）。
  P11 の `<script>`・`<style>` は中身ごと除かれ、郵便番号を消した Woo 生まれの顧客の更新は PUT を送らずスキップ（R3-1c）。取込み直後の往復エクスポートは何も送らず ColorMe の差 0、
  作成エクスポートの後の再取込みは Woo の差 0（R3-1a）。日本語の税区分で軽減税率の商品が「軽減税」に入り、「軽減税」の商品は軽減税率で作成、「免税」の商品は `tax_class_unsupported` で止まった（R3-1d/e）。
  ほかに、**ColorMe は会員の更新で電話番号を必須にしない**（電話番号だけを消した更新が `updated`。review-loop R1-6 の要実測。コードの変更は不要）と、
  **ColorMe のストアフロントは商品名を見出しにエスケープせず出す**（API の値は平文の `Fish & Chips <set>` だが、`<set>` が要素として消える。所見 F。ユーザー決定で取込みでタグを除く〔R3-1f〕）を記録した。
  テストショップに前回の往復で書き換わった P11・P12・P08・P09 は、使い捨てのスクリプトで投入時の値に戻してから始めた。記録は `docs/reviews/feat/r3-1-e2e-rehearsal/rehearsal.md`「再リハーサル」。
  テストショップ・開発サイトの後片付けはユーザーが判断する（ZZR・ZZW・ZZU の商品・会員、ゲスト注文 3 件、開発サイトの取り込んだ実体）
- [x] **R3-1f: ColorMe の商品名のタグを取込みで除く（backlog `r3-1-rerehearsal/F1`。2026-10-08 ユーザー決定）**（ColorMe のストアフロントは商品名を見出しにエスケープせずに出すので、
  名前に書いた装飾のタグは表示されず、実体参照は文字として表示される。取込みはそのまま Canonical に渡していたので、Woo ではタグが文字として見えた）
  **実装サマリ（2026-10-08、ブランチ `feat/r3-1f-strip-name-markup`）**: 新設 `HtmlText::visible_text()` が WP の HTML API（`WP_HTML_Tag_Processor::next_token()`）で文字のトークンだけをつなぎ
  （実体参照は戻る・文字の `<` は残る・コメントと HTML API が中身を文字として返さない要素〔`<script>`・`<style>`・`<textarea>`・`<title>` など〕とブラウザが表示しない `<template>`・`<noscript>` の中身は出ない・閉じていないタグから後ろは出ない）、
  `<br>` とブロック要素（`<p>`・`<div>`・`<li>` など）の境目を空白にして空白の連続を 1 つ・前後を除く。
  `<` も `&` も無い名前は何も変えない（既存の取込みの checksum を変えない）。ColorMe の `ProductTransformer` は新設 `Cast::product_name()` で名前を通し、表示される文字が無い名前（タグだけ）は元の値を使う。
  Canonical の契約（`name` は平文）は変えない。計画時のユーザー回答: 実体参照も表示どおりの文字に戻す、警告は出さない。対象外: エクスポート（Woo の名前の `<…>` は ColorMe で HTML になる。既知の限界）、
  受注の明細名（注文時の値。Woo も明細名を HTML として表示する）、オプション名・値とカテゴリ名（ストアフロントの出力を確かめていない）。詳細は `docs/03` §10.2「商品名の保存形式（R3-1b）」。
  `rehearse-colorme` の `check-import` は商品名をストアフロントの表示どおりの文字（libxml の DOM で作る `cbjp_rh_visible_name()`）と比べ、`seed-shop` に装飾タグ・`<br>`・実体参照の名前の P56 を足した。
  **検証**: PHPUnit（`HtmlTextTest` の表示どおりの文字のデータセット・近道・不正な UTF-8、`ProductTransformerTest` 3 件、変換層から `ProductWriter` まで通す結合〔未ログイン〈kses あり〉と管理者〕）。
  テストショップで P56 を投入し、dry-run と本取込み（管理者の条件で差分取込み → `reset-local` → WP-Cron の条件で全件）で P12 が `Tom &amp; Jerry`・P56 が `送料無料 Tシャツ ♥` で保存され、
  他の商品は `unchanged`、`check-import` の食い違い 0、再取込みは全件 `unchanged` を確認した
- [x] **R3-2: i18n**（POT生成、languages/ja.po 翻訳、make-json。参考スキル: wp-i18n）（2026-10-07、PR #109。ブランチ `feat/r3-2-i18n`）
  **実装サマリ**: 日本語（`ja`）の訳を `languages/` に同梱した（`cart-bridge-jp.pot`・`cart-bridge-jp-ja.po`〈555 文字列すべて訳済み〉・`.mo`・`.l10n.php`・管理画面の JS 用の JSON）。
  生成は `bin/i18n.sh pot|po|compile|check`（`npm run i18n:*`。WP-CLI は wp-env の cli コンテナ）。**JS の文字列は `build/index.js` から抜く**（make-pot は TypeScript を読まない。
  参照が build になるので JSON の名前が `wp_set_script_translations()` の探す md5 と一致する）。**文字列を変える PR は POT と ja.po の更新が必須**（ユーザー決定）で、
  CI の PHPUnit ジョブと `quality.sh` の `npm run i18n:check` がビルド済みのソースから POT を作り直して比べ、`TranslationsTest` が PO・生成物・実行時の読み込みを確かめる。
  翻訳の前に、独立の監査で見つかったソースの i18n 不備を直した: OAuth 完了の通知に ID ではなく表示名、文の連結は `joinSentences()`・列挙は翻訳できる区切りの `joinList()`
  （`src/i18n.ts`）、件数の文の `_n()`（県コード修復の結果は `src/components/repair-messages.ts` へ切り出して Jest）、Logs のレベルの列・標準の税区分の detail の翻訳、
  日時・金額の書式をユーザーの言語に（`cbjpAdmin.locale`）、資格情報が未保存のときの英語の例外メッセージを翻訳した文言に、`8% for` の書式誤認・存在しないボタン名の案内・
  「ColorMe Shop」の表記揺れ・開発中の文言。訳語はユーザー決定（Order(s)＝注文、dry run＝書き込みなし）と WooCommerce の日本語訳（言語パックで実測）に合わせた。
  用語集・含めなかったもの（サーバーが英語で保存する文言ほか）は `docs/03` §6「翻訳（R3-2）」と backlog `r3-2-i18n/plan-*`。
  PR #109 のゲートで、訳のパスの登録を WooCommerce・オートロードを確かめるガードの外（メインファイルの `cbjp_load_textdomain()`）へ移した（ガードの中だと前提条件の通知が英語のまま出る。Codex G1-1）。
  確認: `TranslationsTest` 11 件（ミューテーション 8 種がすべて CAUGHT）・Jest 2 ファイル、`i18n:check` が「文字列の追加」「翻訳者コメントの食い違い」で失敗することを実測。
  dev サイトでサイトの言語を一時的に `ja` にしてフロント・REST・admin-ajax・cron を叩き「too early」通知が出ないこと、管理者のユーザー言語を `ja` にして
  `Assets::enqueue()` の経路で JS に日本語の訳と `cbjpAdmin.locale` が渡ること・警告カタログが日本語になることを確認した（どちらも元に戻した）。
  **マージ後に残る確認（ユーザー）**: 日本語の訳の言い回し（とくに用語集と警告カタログの対処の文）と、ユーザー言語を日本語にした管理画面の目視（長い日本語でレイアウトが崩れないか）
- [x] **R3-3: readme.txt + アセット + 説明文のv1.0化**（スクリーンショット、商標表記: WooCommerce is a trademark of Automattic / ASP名は本文でのみ言及。**プラグインヘッダーと `composer.json` の Description を「Color Me Shop」のみに改める**（現状は3ASP併記。03 §7）。BASE/MakeShop の対応予定を readme に載せるかは公開時に判断。**受注エクスポートと商品画像アップロードがベータ版（プレミアムプラン限定・実店舗で未検証・既定オフ）であることを機能一覧と FAQ に明記する**〔D24〕。**エクスポートが止まる警告の対処（D22 の在庫管理・在庫状況を揃える、D23 の Any を具体値に分ける、ほか）も FAQ に載せる。文言は R3-0k のカタログと共通にする**。**一方向の移行に特化し、往復〔取り込んだショップへのエクスポート・エクスポートしたショップからの取込み〕は想定しないこと、誤って往復した場合は取り込んだ実体を送らず・エクスポートで作った実体を上書きしない〔D25〕ことも明記する**〔2026-10-06 ユーザー決定〕。**商品名の扱い（R3-1f）も書く**: 取込みは ColorMe の名前のタグを除き実体参照を戻す〔ストアフロントの表示どおり〕、エクスポートは Woo の名前の `<…>` をそのまま送るので ColorMe では HTML として解釈される。changelog には、`<` か `&` を含む名前の取込み済み商品が次の取込みで一度だけ書き直されることを書く〔R3-3 で「変換で名前が変わる商品〈タグ・実体参照を含む名前など〉だけ」に正した。`Tom & Jerry`・`1<2` は変わらない〕）
  **実装サマリ（2026-10-09。ブランチ `feat/r3-3-readme-v1`）**: `readme.txt`（英語）を新設。説明・取込み／エクスポートの機能一覧（受注のエクスポートと商品画像のアップロードは Beta）・dry-run と CSV・無料版の上限（事実のみ。**Pro には触れない**）・
  一方向の移行と D25・External services（Color Me Shop API に送る／読むデータ、規約とプライバシーポリシーの URL）・商標・インストール手順・FAQ（往復・無料版・Beta・**エクスポートが止まる警告のうち、dry-run の CSV に出て出会いやすい 14 種の原因と対処〔カタログの文言をそのまま〕**・
  CSV の読み方〔`operation`・`severity`。backlog `r3-0k-warning-catalog/R1-L1`〕・商品名の HTML〔R3-1f〕・HPOS・アンインストール。サンプルのクリーンアップは取込み用で、エクスポートの後に使うと次のエクスポートで Color Me Shop に重複を作ることも書いた〔review-loop R1-1〕）・スクリーンショット 5 枚のキャプション・changelog（0.x から上げたとき、変換で名前が変わる取込み済み商品〈タグ・実体参照を含む名前など。`Tom & Jerry`・`1<2` は変わらない〉が次の取込みで一度だけ書き直されうる）。
  ヘッダーと `composer.json` の Description を Color Me Shop のみにし、POT・日本語訳を更新。スクリーンショットは `.wordpress-org/`（`.distignore` で配布 zip から除く）。ユーザー決定（2026-10-09）: 上限は事実として書き Pro に触れない、アセットはスクリーンショットのみ、
  Contributors は仮の値、BASE・MakeShop の予定は載せない。`Stable tag` は Version と同じ 0.1.0（1.0.0 は R3-4）。
  `ReadmeTest`（新規 10 件）がヘッダーの一致・必須ヘッダー（1.0.0 以上で Contributors の仮の値が残っていないこと）・タグ数・短い説明の長さ・Description に他 ASP が無いこと・FAQ の箇条書きがカタログと文字どおり一致すること・無料版の上限の数字が `LimitPolicy` の既定値と同じこと・接続先の開示・スクリーンショットの連番・配布物の除外を確かめる。
  Plugin Check（`plugin_readme`・`plugin_header_fields`・`trademarks`）はエラー・警告 0。詳細は `docs/03` §7
- [ ] **R3-4: wordpress.org 申請**（スラッグ `cart-bridge-jp`、Plugin Check通過、バージョン 1.0.0〔プラグインヘッダーの Version・`CBJP_VERSION`・readme の `Stable tag` を一緒に上げる。`ReadmeTest` が一致を確かめる〕。**R3-3 からの申し送り**: readme の `Contributors`（仮の値）を wordpress.org のユーザー名に差し替える、バナー・アイコンを作って `.wordpress-org/` に置く、`.wordpress-org/` を SVN の `assets/` へ置く、readme 由来の changelog を GitHub Release に使うかを決める。~~要判断（申請前）: ガイドライン 5（トライアルウェアの禁止）と、無料版の実移行のサンプル上限（D14・D15）の適合性~~ → **判断済み（2026-10-09、D27）**: D15 の作りはガイドライン 5 の本文「試用期間や使用量の上限〔quota〕に達した後に機能を止めてはならない」に当たるため、無料版と Pro の境目を件数から機能に改める。**R3-6 を先に終えてから申請する**（R3-3 で「人為的に低い件数上限を違反例に挙げる」と書いたのはガイドラインの文言ではなかった。本文に例の一覧は無く、件数の制限に触れているのは審査チームの 2018-08-23 の記事「使用量を恣意的に制限してアップセルする試用版は認めてこなかった」。`docs/03` §10.0）。参考スキル: wp-org-release。**公開時に `AbstractPlatformAdapterTest` を2箇所凍結する（D20・issue #49）**: (1) `v1_method_names()` の実装をその時点の `array_keys( self::BASELINE )` を書き写したリテラル配列に置き換える（`BASELINE`との動的連動をやめる。これを忘れると公開後に追加したメソッドの既定実装削除が検出できなくなる）。(2) これ以降 `PlatformAdapter` の既存シグネチャ変更は禁止、新メソッドは `AbstractPlatformAdapter` に既定実装を添えて追加する運用に切り替える。凍結前に判断するとしていた `PlatformAdapter` 契約拡張前提の保留項目は 2026-09-26 に判断済み: `e2-3-push-*/G1-duplicate-on-retry` は D21（R3-0a/b。シグネチャを変えない方式のため凍結とは独立）、`fix-46-pref-state-repair/L-unavailable-not-split` は見送り（代替は issue #71）（03 §2 D20 規則7））
- [ ] **R3-5: アンインストールオプションUI + セキュリティ最終監査**（wp-security-check スキル。UI を足したら readme の FAQ「What happens to my data when I uninstall the plugin?」も改める）
- [ ] **R3-6: 無料版と Pro の境目の切り替え（D27）**（**R3-4 の前**に行う。`PlatformAdapter` の凍結〔D20〕より前にインターフェースの形を決めるため。大きいので計画で PR を分ける）:
  (1) 無料版から件数の上限を外す: `Sync\LimitPolicy`・`cbjp/limits/*`・`GET /limits`・上限の案内（`LimitsUpsellNotice`・`upsell-breakdown`）・サンプル選定（`SampleSelector`・`ExportSampleSelector`）を無料版から除き、商品関連（カテゴリ・タグ・商品・バリエーション・在庫・画像）は取込み・エクスポート・dry-run とも全量にする。
  (2) 顧客・受注・クーポンのコードを Pro アドオン（別リポジトリ）へ移す: Woo の Writer・Reader（`CustomerWriter`・`OrderWriter`・`CouponWriter`・`CustomerReader`・`OrderReader`・`CouponReader` と補助クラス）と関連テスト、受注のエクスポート（D24 のベータ）。無料版には顧客・受注・クーポンのコードを残さない。
  (3) 無料版に、Pro が実体の種類を足す拡張点を設ける（Writer・Reader の登録、ジョブの実体一覧、Import/Export タブの選択肢）。
  (4) readme・スクリーンショット・`ReadmeTest`・i18n を新しい範囲に合わせて改める（R3-3 の「Free version limits」の節・無料版と顧客・受注・クーポンの FAQ を書き直す。Pro への言及はリンク程度。ガイドライン 9・11）。スクリーンショットは `/wporg-screenshots` で撮り直す（`shots.json` の `dry_run_entities`・`uncheck` を無料版の画面に合わせる）。`CLAUDE.md` の冒頭とアーキテクチャ原則 7 も書き換える。
  **計画で決めること**（`docs/03` §10.0「決め残し」）: アダプタの顧客・受注・クーポンの読取り・変換・送信を無料版に残すか Pro へ移すか、拡張点の形、ツール（サンプルのクリーンアップ・リンク再構築・県コード修復）と Mappings タブと検証レポートの受注金額の突合の振り分け、Pro への案内、Pro の公開時期、0.1.0 で顧客・受注を取り込んだサイトの更新時の扱い。backlog `r3-3-readme-v1/R1-X1`（クリーンアップ後の再エクスポートで重複）はクリーンアップの置き場所と合わせて扱う

> **要判断（v1.0公開前）**: ~~無料版の上限到達時に表示する Pro 案内（03 §10.3）の導線先として、v1.0 公開と同時に Pro 版を購入可能にするか。~~
> → **D27 で問いが変わった**: 無料版 v1.0 だけでは顧客・受注・クーポンを移せないため、v1.0 と同時に Pro アドオン（試用を含む）を出すか（R3-6 の計画で決める。`docs/03` §10.0「決め残し」6）。以下は D27 より前の記録。
> Pro 版アドオンは別リポジトリ（本ファイル末尾）で、本リポジトリ側の拡張ポイントは Phase 0 で提供済み。
> R3-0h で `cbjp/limits/pro_url`（既定 `''`）を用意した。販売開始時にフィルターで購入 URL を返せば案内とリンクが出る。既定では Pro 版に触れないので、この判断を後回しにしても v1.0 は出せる。

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

> 本リポジトリのスコープ外（無料版に Pro 固有コードを含めない）。~~無料版側は `cbjp/limits/*` フィルターと
> AdapterRegistry の拡張ポイントを提供するのみ（P0-6 / P0-5 に含む）~~ → **D27（2026-10-09）**: 無料版側は、Pro が実体の種類（顧客・受注・クーポン）を足す拡張点（R3-6）と AdapterRegistry の拡張ポイントを提供する。継続同期は販売しない（D14）。
> ~~上限解除はプラットフォーム非依存のため、v2.0/v3.0 のアダプタ追加で Pro 側の変更は不要。~~ 顧客・受注・クーポンの Writer・Reader は Canonical を介するのでプラットフォームに依存しないが、BASE の顧客（D12）など ASP 固有の扱いが Pro 側に及ぶかは v2.0 で確かめる。

- Pro プラグイン（D27・`docs/03` §10.0）: 顧客・受注・クーポンの dry-run・取込み・エクスポート（R3-6 で無料版から移すコード。受注のエクスポートは D24 のベータのまま）、試用（ライセンスが無い間は各 100 件まで。上限は Pro のコードだけが持つ）、~~`cbjp/limits/*` による上限解除~~、301リダイレクトCSV生成（D17）
- パスワード設定メール（D28・`docs/03` §10.5）: 取込みで作った顧客へパスワード設定の案内を一斉に送る。再設定リンクの期限（既定 24 時間）が切れたときの再発行の案内、対象は `_cbjp_created_by_import` の顧客だけ、送信日時の記録、テスト送信、Action Scheduler で少しずつ送る
- ライセンス統合: WooCommerce API Manager クライアント（アクティベーション・アップデート取得）
- 販売サイト側: WooCommerce API Manager 導入、**有効期限の起点を初回アクティベーション時にするカスタマイズ**（D14/03 §10.1）、適格請求書対応
