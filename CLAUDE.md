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
npx wp-env run cli wp rewrite flush --hard   # 管理画面が「not a valid JSON response」になり /wp-json/ が Apache 404 のとき（.htaccess 欠落の再生成）
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
- 上記の `get_url_params()` 対応は、指摘された1箇所（`save_settings_mappings()`）にのみ適用され、同一ファイル内の同型の呼び出し6箇所（`save_connection()`/`delete_connection()`/`test_connection()`/`get_authorize_url()`/`handle_oauth_callback()`/`exchange_code()`）には長期間未適用のまま残っていた（issue #32/PR #33で解消）。既知の危険パターンの指摘を受けたら、その1箇所だけでなく同一ファイル内の類似呼び出し全てをgrep等で洗い出し、横展開すること
- フィクスチャの匿名化で実ドメイン（例: `shop-pro.jp`）を部分置換（サブドメイン名だけ変更）すると、ドメイン全体が予約済みexampleドメインでないため匿名化ルール違反になる。ドメインは丸ごと `example.com`/`example.jp` に置き換えること。自由入力欄（`note`/`other`/`answer_free_form*`等）は中身が無害に見えても内容に関わらず必ずプレースホルダーへ置換する
- OAuth認可ポップアップは `window.open()` をクリックハンドラから同期的に呼ぶ（await後だとブロックされうる）。`noopener`指定時は成否に関わらず戻り値が常に`null`になる仕様なので、ポーリング等でウィンドウハンドルが必要な場合は`noopener`を使わず、生成できたハンドル側で`.opener = null`を手動設定してreverse tabnabbing対策すること
- PHPの`??`（null合体）演算子はベースがnullの配列アクセス（例: `$possiblyNull['key'] ?? $default`）でも警告を出さない。Copilotレビューはこのパターンを誤って「null配列アクセス警告」と指摘することがあるため、同種の指摘は鵜呑みにせず`php -r`等で実際に検証すること
- PHPCS（`WordPress-Extra` + `Universal.Operators.DisallowShortTernary`）は短縮三項演算子 `?:` を**エラー**にする（`?? ` のnull合体とは別物）。フォールバック値には `$a ?? $b` か、複数候補から最初の非空値を選ぶ自前ヘルパー（例: `Cast::first_non_empty()`）を使うこと
- テストでJSONフィクスチャを読む際は `file_get_contents()` ではなく `wp_json_file_decode( $path, [ 'associative' => true ] )` を使うこと。`file_get_contents()` はPHPCSの `WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` warningの対象になり `composer lint` が失敗する
- `wp_kses_post()` はscript/style以外の禁止タグ（`<iframe>`等中身が無いもの）は要素ごと消えるが、中にテキストを含む禁止タグ（例: `<script>alert(1)</script>`）はタグだけ除去されテキストは残ることがある（実測: WPのkses実装依存）。「HTMLタグを浄化すれば安全」という前提でテストを書く際は実際の出力で検証すること
- カラーミー `shopCoupon.usage_limit` は `indisposable`/`disposable` の**enum文字列**（1ユーザーあたりの利用回数制限）であり、`CanonicalCoupon::usage_limit`（発行総数のint）に対応するのは別フィールドの `total_usage_limit`。`(int) 'indisposable'` は `0` になるため誤って `usage_limit` にキャストしないこと
- カラーミー `product.images[]` には `mobile: true` の項目（PC用画像のモバイル向け重複エントリ）が混在する。フィルタせず取り込むとWoo側で画像が重複登録される
- カラーミー受注（`sale`）の `tax` フィールドは商品分の消費税のみで送料分を含まない。注文全体の税額（Wooの合計と整合する値）が必要な場合は `totals.normal_tax_amount + totals.reduced_tax_amount` を使うこと
- カラーミーの `display_state` はエンドポイントごとにenumが異なりうる（例: `GET /v1/groups`のレスポンスは`showing/hidden/showing_for_members/sale_for_members`の4値だが、`POST /v1/groups`のリクエストスキーマは`showing/hidden/members_only`の3値で別物）。修正時はレスポンス側の実際のスキーマ行を確認してから判定条件を書くこと
- `CanonicalProduct::stock`（バリエーション含む）に`null`を渡すと`Importer`は「在庫管理外＝在庫あり」と解釈する。在庫管理対象なのに実数が不明な場合は`null`ではなく`0`を返すこと
- 変換層（Transformer）が一部の行を除外・展開しうるエンティティ（バリエーション展開、非公開行の除外、変換失敗行のスキップ等）では、APIの生行数（`meta.total`等）をそのまま`Page::$total`として返さないこと。`processed`と1:1対応するとは限らず、進捗率が100%を超えたり永遠に届かなかったりする。1:1対応を保証できない場合はnullを返し、ページング終端の判定にだけ使う（`ColorMeAdapter`のproduct/customer/order/stock参照）。新しいASPアダプタでも同じ基準を適用すること
- WooCommerceのAPI挙動は「同系クラスだから同じはず」の推測が外れる。判断前に wp-env 内の実ソース（`wp-env run cli -- grep -n -A20 "function xxx" /var/www/html/wp-content/plugins/woocommerce.latest-stable/...`）か `wp eval-file` での実測で確認すること。例: `WC_Coupon::__construct()` は `'shop_coupon' === get_post_type($data)` でpost typeを検証するため、削除済みIDでも例外を投げず新規作成扱いになる（他writerで必要なstale-IDフォールバックはCouponWriterには不要）。レビュー指摘に対してテストを書いたら修正なしで通った場合は、指摘自体が誤りである可能性をまず疑うこと
- `WC_Order::set_status()` はステータスが実際に遷移したとき `maybe_set_date_paid()`/`maybe_set_date_completed()` を呼び、`date_paid`/`date_completed` に **`time()`（＝移行の実行時刻）** を打刻する。過去の受注日を保つため、日付の設定・補正は必ず `set_status()` より**後**に行うこと
- variable商品の親の `stock_status` は子variationから導出され（`WC_Product_Variable::sync()`）、親へ直接 `set_stock_status()` しても保存時に子由来の値へ戻る。さらに `set_manage_stock(false)` を書くと `WC_Product::validate_props()` が `stock_quantity` を空にし、店舗が設定した親レベル在庫管理が消える。variable親には在庫を書かないこと
- `term_exists()` は名前だけでなく `sanitize_title()` 由来のslugでもフォールバック照合するため、名前が異なるのにslugが衝突する2つのタームを誤って「重複」と判定することがある（実測: `foo-bar` 作成後に `Foo Bar` を `term_exists()` で照合すると誤って前者のIDを返す）。一方 `wp_insert_term()` 自身はこの場合を拒否せず、自動サフィックス付きslugで新規作成する。重複の事前チェックは `term_exists()` ではなく `get_terms()` による名前＋親の直接比較を使うこと（`TermWriter::find_conflicting_term_id()` 参照）
- writerの `validate()`（dry-run）で「保存しないと判定できない」と決めつける前に、対応する `write()` 内のコア関数呼び出し自体が読取専用の事前チェックで完結していないか確認すること。例: `wp_update_user()` のメール重複チェック（`wp_insert_user()` 内部）は `email_exists()` のみで再現可能。安易に「保存依存の警告」として `validate()` 対象外に倒すと、無料版dry-runの警告カバレッジが不必要に狭まる
- CSV出力の無害化（OWASP CSVインジェクション対策）はセル先頭が `=`/`+`/`-`/`@` かどうかの判定だけでは不十分。値中に生のタブ/CR/LFが残っていると `fputcsv()` のクォートでCSV自体は壊れなくても、改行区切り前提の後続パーサーで行構造が崩れうる。数式判定の前に全ASCII制御文字を除去すること（`DryRunReportCsv::harden()` 参照。Pro版の301リダイレクトCSV等、今後のCSV出力機能にも適用すること）
- カラーミー `product.options[]` はバリエーション軸の定義そのもの（`variants[].option1/option2.name` と同名）。`CanonicalProduct::$options`（非バリエーション属性）へ転記すると `ProductWriter` が同名衝突とみなし全バリエーション商品に `attribute_name_collision` が付く。軸名と一致するものは変換層で除外すること
- 変換層と writer をフィクスチャで別々にテストしても、`CanonicalProduct::$options` のような「配列プロパティの意味論」の層間ズレは検出できない（上の options 問題はユニットテスト全通過のまま実機 dry-run で初めて判明した）。Canonical の配列プロパティの意味を変える／新しく使うときは transformer→writer を通す結合テストか実機 dry-run で確認すること
- `wc_prices_include_tax()` は `woocommerce_calc_taxes=yes` が前提。税計算OFFの店舗では `woocommerce_prices_include_tax=yes` でも false を返し `prices_include_tax_disabled` 警告が出る（誤りではなく仕様。検証環境では税計算ONと税率登録まで行うこと）
- `rest_do_request()` に渡す `WP_REST_Request` のルートにクエリ文字列（`/limits?platform=x`）を含めると `rest_no_route` になる。`set_query_params()` を使うこと。また `rest_pre_serve_request` でストリーミングするルート（CSVレポート等）は `rest_do_request()` では body が null になるため、実HTTP（アプリケーションパスワード等）で確認すること
- `rest_pre_serve_request` 等「1回だけ発火して自身をremove_filterする」前提のフィルターコールバックは、そのフィルターが必ず発火するとは限らない経路（`rest_do_request()`/`$server->dispatch()` 直接呼び出し等）があると自己解除されず残留し、後続の無関係な呼び出しで誤発火しうる。コールバック内でオブジェクト同一性等により「自分宛の呼び出しか」を判定するガードを必ず入れること。コア由来フィルターの引数型はdocblockで確認する（`rest_pre_serve_request` の第2引数は `WP_REST_Response` ではなく `WP_HTTP_Response`）
- `wp-scripts build` はJSエントリー（例: `index`）からimportしたCSSを `build/index.css` ではなく `build/style-index.css`（`style-<エントリー名>.css`）として出力する。`wp_enqueue_style()` 側のパスをこれに合わせないと `file_exists()` ガードが常にfalseになりCSSが一切enqueueされない（`wp-components` のコアCSSも道連れで読み込まれず、管理画面が丸ごと無スタイルになった実例あり。`includes/Admin/Assets.php` 参照）
- 管理画面のタブナビゲーションは自前CSSではなくWordPressコア標準の `nav-tab-wrapper` / `nav-tab` / `nav-tab-active` クラス（`wp-admin/css/common.css` に定義済み）を使うこと。間隔・アクティブ状態の表示が無料で手に入る。自前CSSはコアクラスがカバーしない余白調整のみに留める（`src/App.tsx`, `src/style.css` 参照）
- `TokenStore::is_connected()` と `needs_reconnect()` は排他（保存済みトークンが復号できない場合、`needs_reconnect()` はtrueを返すが `is_connected()` は必ずfalse）。UIで接続状態を判定する際は `connected` 単体ではなく両フラグの組み合わせで見ること。`connected` だけを見ると「要再接続」状態を「未接続」と区別できず、再接続を促す文言・ボタンラベルの出し分けを取りこぼす
- `Adapters\ColorMe\Transform\Cast::money()` は欠損・非数値を無言で`0`に丸める。税込/税抜のように対になったフィールドで片方だけこれを使うと、一方は非ゼロなのにもう一方が黙って`0`という財務的に矛盾した値になり得る（`OrderTransformer`の`unit_price_excl_tax`が実例。issue #14）。「金額が0円」と「復元できない」を区別してフェイルクローズの分岐に使いたい場合は`Cast::money_or_null()`（null透過）を使うこと
- 税込換算等の金額計算はfloat除算（`$amount / 100`等）を避け、整数演算（先に乗算してから`intdiv()`に`+50`/`+99`等の丸め調整値を足す）で行うこと。`CanonicalProduct::$price`が浮動小数点誤差を避けるため金額を文字列で保持する設計と揃える。境界値（税率0〜100・価格1〜200万円）を網羅してもfloatの丸め誤りは実際には再現しなかったが、財務計算では確定的な整数演算を優先する（`ProductTransformer::round_tax()`参照。issue #24）
- ASPのboolean系フィールド（例: `tax_reduced`）がswaggerで必須指定されていない場合、`true === Cast::to_bool_or_null(...) ? A : B`のような三項演算子は欠損・非boolean値も無条件にBへ倒す。その分岐が税率選択等の金額計算に影響するなら、欠損は「Bとみなす」のではなく「不明」としてnullを返し換算自体を諦めること（`ProductTransformer::list_price_including_tax()`参照。issue #24）
- `ProductWriter::resolve_sale_price()`は`sale_price <= 0`を不正とみなし`regular_price`を有効価格として採用する。この仕様を知らずにTransformer側で新しい価格分岐ロジックを書くと、正規の無料商品（`sales_price=0`）に高い定価が設定されている場合、無料商品が定価の有料商品に化ける。価格を条件分岐させるTransformerを書く際は対応するWriterの無効値判定を必ず確認すること（issue #24）
- `ColorMeAdapter`のようにAPI由来の設定（`shop.json`の税設定等）をインスタンス単位でキャッシュするTransformerは、`TokenStore::get()`のペイロードキャッシュ（インスタンス単位で永続）・`AdapterRegistry`（プラットフォーム単位でPHPプロセス単位に静的キャッシュ）と同じ寿命を共有する。同一プロセス内で片方だけ新しくなることはない（再接続は別プロセス・別インスタンスで行われるため、次のプロセスで両方作り直される）。「再接続時にキャッシュだけ古くなる」という指摘を見たら、まずこの寿命が本当にズレるか確認すること（issue #24）
- `Sync\JobManager::filter_and_order_entities()`は`can_fetch_customers=false`のアダプタ（BASE等）では顧客エンティティのジョブ自体を除外し、`Woo\Writer\OrderWriter::apply_customer()`も既存`mappings`の解決のみで新規顧客作成は行わない。受注インポート時に抽出した顧客（`CustomerExtractor`等、D12）を永続化する経路は現状存在しないため、そのようなアダプタを実装する際はImporter/JobManager側にプラットフォーム非依存の新しい拡張点を設計する必要がある（`docs/04-plan-base.md` B4-5参照。issue #26）
- `Sync\JobManager`は`RateLimitExhaustedException`を固定`PAUSED_RESUME_DELAY_SECONDS`（60秒）後に再試行する実装で、日次上限のような長時間（翌日まで等）の再試行遅延を指定する仕組みが無い。1日◯件のような上限を持つASP（BASE等）のexport実装時は、再試行遅延を可変にする拡張点をJobManagerに追加する必要がある（`docs/10-tasks.md` E5-1参照。issue #26）
- カラーミーAPIのswagger.json（OpenAPI定義）のパステンプレート（例: `/v1/sales`）は拡張子なしだが、実際のAPIリクエストパスは`.json`拡張子付き（`GET/POST /v1/sales.json`）が正しい（swagger内のAPI利用説明・curl例、`ColorMeAdapter`の実装で確認可能）。レビューbotがswagger定義の生パスへの統一を提案してくることがあるが、鵜呑みにせず実装・利用例と照合すること（issue #26）
- `ProductWriter::variation_axis_names()`はoption1が無くoption2のみの商品（ColorMeのoption1/2は独立フィールドで構造的にありうる）も配列キーの欠番（`[1 => 'Size']`）として保持するが、保存後のWC商品属性は`WC_Product_Attribute::get_position()`が単なる出現順で欠番があっても0番から詰められ、どちらのスロット（option1/2）由来だったかを覚えていない。永続化後のデータから受注明細のoption1/2値と属性を対応付ける処理を書く際は、スロット番号（0=option1固定）で対応付けず、非null値の「個数」と軸の数を突き合わせて位置ペアで対応付けること（`ProductResolver::resolve_variation_by_options()`参照。issue #27）
- 配送方法のゾーンインスタンス（`flat_rate:5`のようなコロン付きID）の実在確認・タイトル取得には`WC_Shipping_Zones::get_shipping_method( $instance_id )`（`bool|WC_Shipping_Method`を返す）を使うこと。内部で使われる`WC_Data_Store::load('shipping-zone')->get_method()`を直接呼ぶとPHPStanが動的解決される戻り値の型を追えず`Call to an undefined method`エラーになる（issue #27）
- `register_rest_route()` の `args` に `sanitize_callback` を明示すると、WP は既定の `rest_parse_request_arg`（スキーマ検証＋サニタイズ）を適用しなくなり、`type: string` を書いていても配列は弾かれない（`WP_REST_Request::sanitize_params()` は `sanitize_callback` 未指定かつ `type` ありのときだけ既定を補う。`has_valid_params()` は `validate_callback` が無ければ何も検証しない）。`sanitize_callback` を付ける引数には必ず `validate_callback => 'rest_validate_request_arg'` を併記すること（`RestController` の `/tools/*` 参照）
- `wc_get_product()` は削除済みの variation ID に対して `false` ではなく中身の無い `WC_Product_Variation` を返しうる（`WC_Product_Variation_Data_Store_CPT::read()` は投稿欠損で例外を投げず、商品種別キャッシュも残るため。実測: `SampleCleanup` で削除直後の ID）。削除済みかどうかは `instanceof` ではなく `get_post()` の有無で判定すること
- DBに保存済みの値を読む用の寛容なサニタイズ関数（不正なエントリを黙って読み飛ばし、エンドポイント自体を落とさないためのもの）を、REST PUT等の書込み入力検証に流用しないこと。書込み側で使うと、1件でも不正な値（非スカラー等）を含むリクエストがそのエントリだけ読み飛ばされて「成功（200）」を返し、既存の正当なデータが黙って消える。書込み検証は専用の関数（1件でも不正なら`null`を返しリクエスト全体を拒否）に分離すること（issue #27）

## フロントエンド（React/TypeScript）規約

- ポーリングhook（`useRunPolling`等）の`refetch()`が一時的な通信エラーを内部でcatchして自動再試行する設計の場合、そのPromiseは通信の成否に関わらず正常解決する。呼び出し元が「`await refetch()`が解決した＝新しいstateが反映された」と決め打ちすると、一時的な失敗時に古いstateのまま後続処理（例: ボタンの再有効化）が進んでしまう。真に「新しいデータが届いた」ことを検知したい場合は、Promiseの解決ではなくstate自体（成功時のみ新しい参照になるオブジェクト等）の変化をeffectで監視すること（`src/hooks/useRunPolling.ts`, `src/tabs/ImportTab.tsx`のretryJob参照。issue #30）
- 複数ジョブから成るrunの「終端判定」（`isTerminal`: 全ジョブがcompleted/failed/cancelledのいずれか）と「成功判定」（全ジョブがcompleted）を混同しないこと。キャンセル・一部失敗したrunも`isTerminal`はtrueになるため、「完了時のみ出す」UI（全体レポートDL・アップセル集計等）を`isTerminal`だけでゲートすると、部分的・失敗した結果を完全な結果として提示してしまう。個別の判定（全ジョブcompleted）を別途用意すること（`src/components/RunProgress.tsx`のallCompleted参照。issue #30）
- WordPressコアが登録する`wp-components`等の共有アセットハンドルは、WooCommerce等の他プラグインが独自バージョンを登録しているとそちらが優先されることがあり、同じ`@wordpress/components`の`Notice`等でも環境によってレイアウト（例: `flex`+`padding: 8px 12px` vs `grid`+`padding: 12px`）が変わりうる。見た目の余白等を安定させたい箇所はアップストリームのデフォルトに依存せず自前CSSで明示的に上書きすること（`src/style.css`の`.components-notice.is-info`参照）

## アーキテクチャ原則（詳細は docs/00-plan-overview.md）

1. **アダプタパターン**: 各ASPは `Adapters\PlatformAdapter` インターフェースの実装。プラットフォーム固有コードをアダプタ外に書かない
2. **正規化モデル**: ASP⇔Woo間は必ず `Canonical\*` モデル（CanonicalProduct等）を経由。直接変換禁止
3. **capability宣言**: 各アダプタは `capabilities()` で可否（カテゴリ作成可否・削除可否等）を宣言し、UI/ジョブ側が分岐
4. **破壊的操作の禁止**: リモート側データのDELETEは行わない（MakeShopは技術的に可能だが、削除は「非公開化」提案に留める）。ローカル側も上書き前にdry-run/プレビューを提供
5. **レート制限遵守**: 全API呼び出しは `Support\RateLimiter` 経由（カラーミー: 120req/分）
6. **再開可能なジョブ**: バッチはカーソル方式で中断・再開可能に。進捗は `cbjp_jobs` テーブルに永続化
7. **無料版/Pro版の分離**: 本リポジトリは無料版（dry-runは全量、実移行はサンプル上限つき。`docs/03-design-decisions.md` §10）。Pro版（上限解除・買切りライセンス）は別プラグインがフック（`cbjp/limits/*` 等）で拡張する設計にし、Pro固有コードは含めない。継続同期は販売しない（D14）。新しいインポート経路（カーソル走査以外のID指定取得等）を追加する際は必ず`LimitPolicy`を通すこと。サンプル選定自体の上限は「1回に選ばれるセットのサイズ」しか制限せず、`LimitPolicy`（`cbjp_mappings`累積カウント）を経由しないと、クリーンアップ→再選定の繰り返しで無料版上限を回避できてしまう
8. **アダプタ拡張点の信頼境界**: `cbjp/adapters/register` フィルターはPro版アドオン等の外部コードが使う拡張点。返り値の型はdocblock上の契約でしかなく実行時に強制されないため、アダプタの戻り値（`connection_fields()` 等）は信用せず防御的に検証する（不正な1アダプタが全体のAPIエンドポイントを落とさないように）。`Canonical*`モデルのコンストラクタも同じ境界。新しい引数は必ず`extras`より後ろに追加し、既存引数の位置を動かさないこと。外部アダプタが位置引数で `new CanonicalProduct(..., $extras)` のように呼び出しうるため、位置がずれるとTypeErrorになる。`CanonicalProduct::$variants`のような配列<配列>型プロパティも同じ信頼境界の一部で、各要素が配列であるか`is_array()`で確認せずオフセットアクセスしないこと（非配列要素はTypeError/Errorでジョブ全体を落としうる）
9. **境界データはフェイルクローズで検証**: ASPレスポンスのenum/必須値は「required」とスキーマ明記されないことが多く、欠損・不正値・想定外の新値がありうる。既知の除外値を否定する形（`!== 'x'`）ではなく既知の許可値を肯定する形（`=== 'x'`）で判定し、解釈できない値は安全側（除外/private/在庫切れ/例外）に倒すこと。楽観的デフォルト（公開・無期限・在庫あり・数量1等へのフォールバック）は金銭的リスクや誤出荷に直結する

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
- 各フェーズ完了時に `composer lint && composer analyze && composer test:wpenv` を通すこと（`composer test` はホストから動かない。上の「コマンド」参照）
- 不明なAPI仕様は推測で実装せず、`docs/` の「要検証」項目として記録し、フィクスチャを用意してから実装
- コミットメッセージは Conventional Commits（`feat:`, `fix:`, `refactor:` ...）
