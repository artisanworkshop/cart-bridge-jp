# Cart Bridge JP – Migrate for WooCommerce

日本のECサイトASP（カラーミーショップ、MakeShop、BASE、将来的に他ASP）とWooCommerce間で、商品・顧客・受注データの移行を行うWordPressプラグイン。無料版は挙動確認用サンプル移行、Pro版（別プラグイン）で無制限化する（`docs/03-design-decisions.md` D14）。

リリースは1ASPずつ: **v1.0=カラーミーショップのみ（インポート＋エクスポート）、v2.0=BASE追加、v3.0=MakeShop追加**（D18、2026-09-05）。v1.0 完了前に BASE/MakeShop のアダプタ実装へ着手しない。3ASP対応を前提に実装済みの基盤（TokenStoreのリフレッシュ構造、`canFetchCustomers` 等）は削除しない。

## 開発計画ドキュメント（必読）

作業前に必ず該当ドキュメントを読むこと:

- `docs/00-plan-overview.md` — 全体アーキテクチャ・フェーズ計画・DB設計・命名規約
- `docs/01-plan-colorme.md` — カラーミーショップAPI仕様とアダプタ実装計画
- `docs/02-plan-makeshop.md` — MakeShop API（GraphQL）仕様とアダプタ実装計画
- `docs/03-design-decisions.md` — 確定した設計判断・詳細設計（**他の計画ドキュメントと矛盾する場合はこちらを優先**）
- `docs/04-plan-base.md` — BASE API仕様とアダプタ実装計画
- `docs/10-tasks.md` — 実装タスクWBS（タスクの進行管理台帳。各セッションはここから着手タスクを選ぶ）
- `docs/20-memo-hosted-oauth-relay.md` — **v2.0 の検討事項（未確定）**: 開発者ホスト型OAuth中継サーバー（案B「かんたん接続」）。v1.0 は現行のBYOアプリ方式で進め、v2.0 Phase 4 冒頭（B4-7。B4-0 の直後）で採否を判断する。外部仕様は調査日付きで記録しており、参照前に同メモ §9 の再確認チェックリストを実施すること。`docs/03` と矛盾する場合は 03 が優先

## 識別子・命名規約

| 項目 | 値 |
|---|---|
| プラグイン名 | Cart Bridge JP – Migrate for WooCommerce |
| スラッグ / テキストドメイン | `cart-bridge-jp` |
| PHP名前空間 | `CartBridgeJP\`（PSR-4、`includes/` 配下） |
| 関数・フック接頭辞 | `cbjp_` / フィルターは `cbjp/{domain}/{action}` 形式 |
| 定数接頭辞 | `CBJP_` |
| DBテーブル接頭辞 | `{$wpdb->prefix}cbjp_` |

## 技術スタック・要件

- PHP 8.2+ / WordPress 6.9+ / WooCommerce 10.0+
- **HPOS（High-Performance Order Storage）対応必須**。注文操作は必ずWooCommerce CRUD（`WC_Order`等）経由。`wp_posts`直接クエリ禁止
- 管理画面UI: TypeScript + React（`@wordpress/scripts`、`@wordpress/components`）
- 非同期処理: Action Scheduler（WooCommerce同梱）
- HTTPクライアント: WP HTTP API（`wp_remote_*`）をラップした自前クライアント（Guzzle等の外部依存は避ける。wordpress.org配布のため）

## コマンド

```bash
wp-env start                 # 開発環境起動 (http://localhost:8888, admin/password)
wp-env run cli wp ...        # WP-CLI実行
composer install             # PHP依存
composer lint                # PHPCS (WordPress Coding Standards)
composer analyze             # PHPStan (level 6+)
composer test                # PHPUnit（wp-envコンテナ内で直接実行する場合。ホストからは動かない）
composer test:wpenv          # PHPUnit（ホストから wp-env 経由で実行。通常はこちらを使う）
npx wp-env run cli wp rewrite flush --hard   # 管理画面が「not a valid JSON response」になり /wp-json/ が Apache 404 のとき（.htaccess 欠落の再生成）。permalink_structure が空（新規 wp-env 等）だと flush だけでは直らず、先に `wp rewrite structure '/%postname%/' --hard` が必要（rest_url() が /wp-json/ ではなく ?rest_route= 形式にフォールバックし、OAuth コールバック URL の登録値と食い違う）
npm install && npm start     # 管理画面UIの開発ビルド（watch）
npm run build                # 本番ビルド
```

## コーディング規約

- WordPress Coding Standards（PHPCS: `WordPress` ruleset + PSR-4クラス構成）
- UI文字列は英語で書き、`__( 'Text', 'cart-bridge-jp' )` で必ずi18n化。日本語は `languages/ja.po` で翻訳
- コードコメントは日本語可
- 入力は必ずサニタイズ、出力は必ずエスケープ、DB操作は `$wpdb->prepare()`
- APIトークン等の機密情報は暗号化して保存（`Support\TokenStore` 経由。オプションテーブルに平文保存禁止）
- nonce/capabilityチェック必須（管理操作は `manage_woocommerce`）
- `$wpdb->insert()`/`update()` はnull値を特別扱いしSQLのNULLとして書き込むが、生の `$wpdb->prepare()` + `query()` はnullを `%s` プレースホルダー経由で空文字列に変換してしまう（`vsprintf()` の挙動）。NULL許容カラムへ生クエリでnullを書く場合は `NULLIF(%s, '')` 等で明示的に変換すること
- `register_rest_route()` で `args` スキーマ（type検証）を定義しないルートは、クエリパラメータが配列（例: `?job_id[]=1`）で渡り得る。スカラー値を期待するパラメータは `is_scalar()` で検証してから使うこと。さらに `WP_REST_Request::get_param()`/`get_params()` はGETはクエリ文字列、PUT/POST/DELETE等はボディを**URLパスより優先**してマージする（`get_parameter_order()`）ため、URLパスがリソースを名指しするパラメータ（例: `/settings/mappings/{platform}` の `platform`）を `get_param()` で読むと、クエリ/ボディの同名スカラー値で意図しない別リソースへ読み書きが向いてしまう。リソースを識別するパスパラメータは必ず `get_url_params()` で取得すること
- 既知の危険パターンの指摘を受けたら、その1箇所だけでなく同一ファイル内の類似呼び出し全てを grep 等で洗い出して横展開すること（`platform` の `get_url_params()` 化は issue #32/PR #33 で 6 箇所、`run_id`/`id` は PR #34 で 5 箇所を、いずれも初回の指摘時に取りこぼしていた）
- フィクスチャの匿名化で実ドメイン（例: `shop-pro.jp`）を部分置換（サブドメイン名だけ変更）すると、ドメイン全体が予約済みexampleドメインでないため匿名化ルール違反になる。ドメインは丸ごと `example.com`/`example.jp` に置き換えること。自由入力欄（`note`/`other`/`answer_free_form*`等）は中身が無害に見えても内容に関わらず必ずプレースホルダーへ置換する
- PHPの`??`（null合体）演算子はベースがnullの配列アクセス（例: `$possiblyNull['key'] ?? $default`）でも警告を出さない。Copilotレビューはこのパターンを誤って「null配列アクセス警告」と指摘することがあるため、同種の指摘は鵜呑みにせず`php -r`等で実際に検証すること
- CopilotレビューはJSXの真偽値プロパティ省略記法（`<Component someProp />` は `someProp={true}` と等価）を「未宣言の識別子への参照でReferenceErrorになる」と誤検知することがある（実例: `reportsAvailable`単体の記述、E2-4 PR #53 G3）。`tsc --noEmit`/ビルドが通っていれば構文として正しいため、鵜呑みにせず型チェック結果で検証すること
- 安全側の判定に使うboolを配列から復元するときは `(bool)` キャストを使わないこと。`(bool) '0'` は `false`、`(bool) 'false'` は `true` になるため、壊れた値・型違いが「安全」の宣言に化けてフェイルクローズを迂回しうる（`CanonicalCoupon::from_array()` の `has_unsupported_restrictions` が実例。issue #15）。`is_bool()` で実boolのみ受け、それ以外は不明（null）へ倒す
- PHPCS（`WordPress-Extra` + `Universal.Operators.DisallowShortTernary`）は短縮三項演算子 `?:` を**エラー**にする（`?? ` のnull合体とは別物）。フォールバック値には `$a ?? $b` か、複数候補から最初の非空値を選ぶ自前ヘルパー（例: `Cast::first_non_empty()`）を使うこと
- テストでJSONフィクスチャを読む際は `file_get_contents()` ではなく `wp_json_file_decode( $path, [ 'associative' => true ] )` を使うこと。`file_get_contents()` はPHPCSの `WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` warningの対象になり `composer lint` が失敗する
- `wp_kses_post()` はscript/style以外の禁止タグ（`<iframe>`等中身が無いもの）は要素ごと消えるが、中にテキストを含む禁止タグ（例: `<script>alert(1)</script>`）はタグだけ除去されテキストは残ることがある（実測: WPのkses実装依存）。「HTMLタグを浄化すれば安全」という前提でテストを書く際は実際の出力で検証すること
- WooCommerceのAPI挙動は「同系クラスだから同じはず」の推測が外れる。判断前に wp-env 内の実ソース（`wp-env run cli -- grep -n -A20 "function xxx" /var/www/html/wp-content/plugins/woocommerce.latest-stable/...`）か `wp eval-file` での実測で確認すること。例: `WC_Coupon::__construct()` は `'shop_coupon' === get_post_type($data)` でpost typeを検証するため、削除済みIDでも例外を投げず新規作成扱いになる（他writerで必要なstale-IDフォールバックはCouponWriterには不要）。レビュー指摘に対してテストを書いたら修正なしで通った場合は、指摘自体が誤りである可能性をまず疑うこと
- CSV出力の無害化（OWASP CSVインジェクション対策）はセル先頭が `=`/`+`/`-`/`@` かどうかの判定だけでは不十分。値中に生のタブ/CR/LFが残っていると `fputcsv()` のクォートでCSV自体は壊れなくても、改行区切り前提の後続パーサーで行構造が崩れうる。数式判定の前に全ASCII制御文字を除去すること（`DryRunReportCsv::harden()` 参照。Pro版の301リダイレクトCSV等、今後のCSV出力機能にも適用すること）
- 変換層と writer をフィクスチャで別々にテストしても、`CanonicalProduct::$options` のような「配列プロパティの意味論」の層間ズレは検出できない（`CanonicalProduct::$options` にバリエーション軸の名前が混ざる問題〔`adapters-colorme.md` 参照〕はユニットテスト全通過のまま実機 dry-run で初めて判明した）。Canonical の配列プロパティの意味を変える／新しく使うときは transformer→writer を通す結合テストか実機 dry-run で確認すること
- `rest_do_request()` に渡す `WP_REST_Request` のルートにクエリ文字列（`/limits?platform=x`）を含めると `rest_no_route` になる。`set_query_params()` を使うこと。また `rest_pre_serve_request` でストリーミングするルート（CSVレポート等）は `rest_do_request()` では body が null になるため、実HTTP（アプリケーションパスワード等）で確認すること
- `rest_pre_serve_request` 等「1回だけ発火して自身をremove_filterする」前提のフィルターコールバックは、そのフィルターが必ず発火するとは限らない経路（`rest_do_request()`/`$server->dispatch()` 直接呼び出し等）があると自己解除されず残留し、後続の無関係な呼び出しで誤発火しうる。コールバック内でオブジェクト同一性等により「自分宛の呼び出しか」を判定するガードを必ず入れること。コア由来フィルターの引数型はdocblockで確認する（`rest_pre_serve_request` の第2引数は `WP_REST_Response` ではなく `WP_HTTP_Response`）
- `TokenStore::is_connected()` と `needs_reconnect()` は排他（保存済みトークンが復号できない場合、`needs_reconnect()` はtrueを返すが `is_connected()` は必ずfalse）。UIで接続状態を判定する際は `connected` 単体ではなく両フラグの組み合わせで見ること。`connected` だけを見ると「要再接続」状態を「未接続」と区別できず、再接続を促す文言・ボタンラベルの出し分けを取りこぼす
- `register_rest_route()` の `args` に `sanitize_callback` を明示すると、WP は既定の `rest_parse_request_arg`（スキーマ検証＋サニタイズ）を適用しなくなり、`type: string` を書いていても配列は弾かれない（`WP_REST_Request::sanitize_params()` は `sanitize_callback` 未指定かつ `type` ありのときだけ既定を補う。`has_valid_params()` は `validate_callback` が無ければ何も検証しない）。`sanitize_callback` を付ける引数には必ず `validate_callback => 'rest_validate_request_arg'` を併記すること（`RestController` の `/tools/*` 参照）
- `wp_delete_user()` は capability を一切見ない。`manage_woocommerce` ゲートの REST から呼ぶ場合、shop_manager（`delete_users` を持たない）が WP 管理画面でできないアカウント削除を行えてしまうため、必ず `current_user_can( 'delete_user', $user_id )` を併せて確認すること（`SampleCleanup::can_delete_user()` 参照）
- WooCommerce は `wc_modify_map_meta_cap()`（`map_meta_cap` フィルター）で、非管理者による管理者アカウントへの `edit_user`/`delete_user`/`remove_user`/`promote_user` を `do_not_allow` にする。テストで「capability は持っているが別ガードで止まる」ことを検証したい場合、管理者を対象にした `current_user_can( 'delete_user', $admin_id )` は WC 側で先に false になるため、自分自身など非管理者を対象に capability の開通を確認すること（`SampleCleanupTest` 参照）
- DBに保存済みの値を読む用の寛容なサニタイズ関数（不正なエントリを黙って読み飛ばし、エンドポイント自体を落とさないためのもの）を、REST PUT等の書込み入力検証に流用しないこと。書込み側で使うと、1件でも不正な値（非スカラー等）を含むリクエストがそのエントリだけ読み飛ばされて「成功（200）」を返し、既存の正当なデータが黙って消える。書込み検証は専用の関数（1件でも不正なら`null`を返しリクエスト全体を拒否）に分離すること（issue #27）
- 設定値（例: `cbjp_settings_{platform}`のマッピング）を検証・保存する関数が制御文字除去やtrim等の正規化を行う場合、その値の「候補一覧」を生成する別の経路（UIのプルダウン候補等）でも同じ正規化を適用すること。候補生成側だけ正規化を怠ると、空白・制御文字のみの値が候補上は非空に見えるのに保存時には空文字列化して拒否される、または前後の空白ごと異なるキーとして保存され選んだ項目が保存後に消える、という食い違いが起きる（`Admin\RestController::normalize_mapping_token()`が3箇所で共有する形に統一した実例。issue #39）
- 正規表現で「完全に指定パターンとだけ一致するか」を検証する場合、終端アンカーは`$`ではなく`\z`を使うこと。PCREの`$`は末尾改行の直前にもマッチするため、`^[0-9\-]+$`のような形式チェックは末尾に`\n`が混入した値（例: `"0312345678\n"`）を誤って「一致」と通してしまう
- `mb_strlen()`等のmbstring関数はホストにmbstring拡張が無くても、WordPress core自身（`wp-includes/compat.php`）が`function_exists()`ガード付きのポリフィルを無条件に提供する。プラグインコードは常にWPブートストラップ後に実行されるため、レビューbotが「mbstring拡張の宣言漏れ」を指摘しても誤りであることが多い（`composer.json`への`ext-mbstring`追加は不要。issue #44）
- IDE/エディタが出す phpcs 診断（短縮配列構文・ファイル名規約・docblock 等）はリポジトリの `phpcs.xml.dist` とは別のルールセット由来のノイズで、`composer lint`（単体は `vendor/bin/phpcs -s <file>`）が通っていれば対応しないこと。判断は必ずリポジトリの設定で行う（実測: `.claude/skills/*/templates/mu-plugin-mock-adapter.php` は IDE が 16 件のエラーを報告したがリポジトリ設定では exit 0）

## アーキテクチャ原則（詳細は docs/00-plan-overview.md）

1. **アダプタパターン**: 各ASPは `Adapters\PlatformAdapter` インターフェースの実装。プラットフォーム固有コードをアダプタ外に書かない
2. **正規化モデル**: ASP⇔Woo間は必ず `Canonical\*` モデル（CanonicalProduct等）を経由。直接変換禁止
3. **capability宣言**: 各アダプタは `capabilities()` で可否（カテゴリ作成可否・削除可否等）を宣言し、UI/ジョブ側が分岐
4. **破壊的操作の禁止**: リモート側データのDELETEは行わない（MakeShopは技術的に可能だが、削除は「非公開化」提案に留める）。ローカル側も上書き前にdry-run/プレビューを提供
5. **レート制限遵守**: 全API呼び出しは `Support\RateLimiter` 経由（カラーミー: 120req/分）
6. **再開可能なジョブ**: バッチはカーソル方式で中断・再開可能に。進捗は `cbjp_jobs` テーブルに永続化
7. **無料版/Pro版の分離**: 本リポジトリは無料版（dry-runは全量、実移行はサンプル上限つき。`docs/03-design-decisions.md` §10）。Pro版（上限解除・買切りライセンス）は別プラグインがフック（`cbjp/limits/*` 等）で拡張する設計にし、Pro固有コードは含めない。継続同期は販売しない（D14）。新しいインポート経路（カーソル走査以外のID指定取得等）を追加する際は必ず`LimitPolicy`を通すこと。サンプル選定自体の上限は「1回に選ばれるセットのサイズ」しか制限せず、`LimitPolicy`（`cbjp_mappings`累積カウント）を経由しないと、クリーンアップ→再選定の繰り返しで無料版上限を回避できてしまう
8. **アダプタ拡張点の信頼境界**: `cbjp/adapters/register` フィルターはPro版アドオン等の外部コードが使う拡張点。返り値の型はdocblock上の契約でしかなく実行時に強制されないため、アダプタの戻り値（`connection_fields()` 等）は信用せず防御的に検証する（不正な1アダプタが全体のAPIエンドポイントを落とさないように）。`Canonical*`モデルのコンストラクタも同じ境界。新しい引数は必ず`extras`より後ろに追加し、既存引数の位置を動かさないこと。外部アダプタが位置引数で `new CanonicalProduct(..., $extras)` のように呼び出しうるため、位置がずれるとTypeErrorになる。`CanonicalProduct::$variants`のような配列<配列>型プロパティも同じ信頼境界の一部で、各要素が配列であるか`is_array()`で確認せずオフセットアクセスしないこと（非配列要素はTypeError/Errorでジョブ全体を落としうる）。**`PlatformAdapter`自体の外部互換ポリシー（D20、issue #49）**: 外部アダプタは`PlatformAdapter`を直接implementsせず`AbstractPlatformAdapter`を継承する。v1.0.0公開前はインターフェースへの追加・変更を許容するが、公開後は既存シグネチャを変えず、新メソッドは`AbstractPlatformAdapter`に既定実装を添えて追加する（`AbstractPlatformAdapterTest`がCIで強制）。値オブジェクト（`Capabilities`等）の新しい引数は末尾に既定値付きで追加する。詳細は `docs/03-design-decisions.md` §2「外部互換ポリシー」
9. **境界データはフェイルクローズで検証**: ASPレスポンスのenum/必須値は「required」とスキーマ明記されないことが多く、欠損・不正値・想定外の新値がありうる。既知の除外値を否定する形（`!== 'x'`）ではなく既知の許可値を肯定する形（`=== 'x'`）で判定し、解釈できない値は安全側（除外/private/在庫切れ/例外）に倒すこと。楽観的デフォルト（公開・無期限・在庫あり・数量1等へのフォールバック）は金銭的リスクや誤出荷に直結する

## トピック別ルール（`.claude/rules/`）

長い落とし穴集はパス指定ルールへ分割してある（Claude Code は**該当ファイルを Read したときだけ**読み込む）。CLAUDE.md にある規約と同格の**必須ルール**で、
触るファイルに対応するものは実装・レビューの前に必ず読むこと（Codex/Copilot など Claude Code 以外のツールやサブエージェントは自動では読み込まないため、明示的に開くこと。`AGENTS.md` も参照）。

| ファイル | 内容 | 対象パス |
|---|---|---|
| `.claude/rules/adapters-colorme.md` | ASP アダプタ共通の基準（境界データ・`Page::$total`・push の部分失敗・税込換算）とカラーミー API の癖（クーポン・画像・税・`pref_id`・受注明細ほか） | `includes/Adapters/**`, `includes/Canonical/**`, `AddressMapper`, `tests/fixtures/**` |
| `.claude/rules/woocommerce-api.md` | `WC_Order`/`WC_Product`/`WC_Coupon`/在庫/税/term/`save()` など WooCommerce の実測結果 | `includes/Woo/**` |
| `.claude/rules/sync-export-tools.md` | Importer/Exporter/JobManager・`cbjp_mappings`（checksum・upsert）・サンプルクリーンアップ等のツールの設計上の罠 | `includes/Sync/**`, `includes/Woo/Tools/**`, `includes/Woo/Export/**`, `includes/Woo/Reader/**` |
| `.claude/rules/frontend.md` | React の非同期ガード（世代カウンタ）・ポーリング hook・OAuth ポップアップ・タブ/CSS・ネイティブ `confirm()` | `src/**`, `includes/Admin/Assets.php` |
| `.claude/rules/skill-scripts.md` | `.claude/skills/` 配下の bash スクリプトのフェイルクローズ（`\|\| true` の握りつぶし・`set -e` 下の出力消失） | `.claude/skills/**/scripts/**`, `.claude/skills/**/templates/**` |

## テスト方針

- ユニットテスト: 正規化モデル変換・マッピングロジックを重点的に
- APIクライアントはHTTPレイヤーをモック（実APIを叩くテストは `tests/integration/` に分離し、環境変数でトークン注入時のみ実行）
- 受注・商品変換はフィクスチャJSON（実APIレスポンスのサンプル）ベースで検証
- フィクスチャのコミット前に `tests/fixtures/README.md` の匿名化ルールを必ず適用（publicリポジトリのため個人情報・トークン厳禁）

## 作業の進め方

- **`main`への直接push**: 開発・バグ対応は必ずPR経由（`/start-task`）。ドキュメントのみの変更
  （`*.md`ファイルのみ。`composer.json`/`package.json`等の設定ファイルは含めない）に限り、
  `main`への直接pushを許容する。GitHub側のブランチ保護は「PR必須」だが、パス単位の例外は
  設定できず（クラシック保護・Rulesetsとも非対応）、管理者は元々バイパス可能なため、これは
  GitHub側の強制ではなくClaude Codeが守る運用ルールである
- dev-cycle完了（最終報告済み）後に追加の作業（backlog/docsへのissue番号追記等）を行う場合は、
  着手前に`gh pr view <PR> --json state`でPRがまだOPENか確認すること。squash/rebaseマージ済み
  だとそのfeatureブランチへpushしても本流には反映されない（issue #47 PR #51で実際に発生:
  マージ後の追記コミットをマージ済みfeatureブランチへpushしてしまい、mainへ直接コミットし直す
  手戻りが発生した）
- 開発サイクル（計画→ブランチ→実装→review-loop→PR→CI→Codex/Copilot ゲート→最終報告）はプロジェクトスキル `/cbj-dev-cycle`（`.claude/skills/cbj-dev-cycle/`。ボットゲート用スクリプト同梱）で回す。汎用の `dev-cycle` は直接使わない
- OAuth 接続なしで REST・管理画面を実機確認する（旧データの再現・Scan/Repair/Import/Export の配線）手順はプロジェクトスキル `/verify-with-mock-adapter`（`.claude/skills/verify-with-mock-adapter/`）
- 各フェーズ完了時に `composer lint && composer analyze && composer test:wpenv` を通すこと（`composer test` はホストから動かない。上の「コマンド」参照）
- 不明なAPI仕様は推測で実装せず、`docs/` の「要検証」項目として記録し、フィクスチャを用意してから実装
- コミットメッセージは Conventional Commits（`feat:`, `fix:`, `refactor:` ...）
