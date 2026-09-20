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
- 既知の危険パターンの指摘を受けたら、その1箇所だけでなく同一ファイル内の類似呼び出し全てを grep 等で洗い出して横展開すること（`platform` の `get_url_params()` 化は issue #32/PR #33 で 6 箇所、`run_id`/`id` は PR #34 で 5 箇所を、いずれも初回の指摘時に取りこぼしていた）
- フィクスチャの匿名化で実ドメイン（例: `shop-pro.jp`）を部分置換（サブドメイン名だけ変更）すると、ドメイン全体が予約済みexampleドメインでないため匿名化ルール違反になる。ドメインは丸ごと `example.com`/`example.jp` に置き換えること。自由入力欄（`note`/`other`/`answer_free_form*`等）は中身が無害に見えても内容に関わらず必ずプレースホルダーへ置換する
- OAuth認可ポップアップは `window.open()` をクリックハンドラから同期的に呼ぶ（await後だとブロックされうる）。`noopener`指定時は成否に関わらず戻り値が常に`null`になる仕様なので、ポーリング等でウィンドウハンドルが必要な場合は`noopener`を使わず、生成できたハンドル側で`.opener = null`を手動設定してreverse tabnabbing対策すること
- PHPの`??`（null合体）演算子はベースがnullの配列アクセス（例: `$possiblyNull['key'] ?? $default`）でも警告を出さない。Copilotレビューはこのパターンを誤って「null配列アクセス警告」と指摘することがあるため、同種の指摘は鵜呑みにせず`php -r`等で実際に検証すること
- 安全側の判定に使うboolを配列から復元するときは `(bool)` キャストを使わないこと。`(bool) '0'` は `false`、`(bool) 'false'` は `true` になるため、壊れた値・型違いが「安全」の宣言に化けてフェイルクローズを迂回しうる（`CanonicalCoupon::from_array()` の `has_unsupported_restrictions` が実例。issue #15）。`is_bool()` で実boolのみ受け、それ以外は不明（null）へ倒す
- PHPCS（`WordPress-Extra` + `Universal.Operators.DisallowShortTernary`）は短縮三項演算子 `?:` を**エラー**にする（`?? ` のnull合体とは別物）。フォールバック値には `$a ?? $b` か、複数候補から最初の非空値を選ぶ自前ヘルパー（例: `Cast::first_non_empty()`）を使うこと
- テストでJSONフィクスチャを読む際は `file_get_contents()` ではなく `wp_json_file_decode( $path, [ 'associative' => true ] )` を使うこと。`file_get_contents()` はPHPCSの `WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` warningの対象になり `composer lint` が失敗する
- `wp_kses_post()` はscript/style以外の禁止タグ（`<iframe>`等中身が無いもの）は要素ごと消えるが、中にテキストを含む禁止タグ（例: `<script>alert(1)</script>`）はタグだけ除去されテキストは残ることがある（実測: WPのkses実装依存）。「HTMLタグを浄化すれば安全」という前提でテストを書く際は実際の出力で検証すること
- カラーミー `shopCoupon.usage_limit` は `indisposable`/`disposable` の**enum文字列**（1ユーザーあたりの利用回数制限）であり、`CanonicalCoupon::usage_limit`（発行総数のint）に対応するのは別フィールドの `total_usage_limit`。`(int) 'indisposable'` は `0` になるため誤って `usage_limit` にキャストしないこと
- カラーミー `shopCoupon.group_limit_type`（`none`/`including`/`excluding`）は**商品**グループによる制限であり会員グループ制限ではない（swagger の description で確認可能）。「会員グループ限定クーポン」と読み違えたコメントが実際にコードへ入り込んだ実績があるため、制限系フィールドの意味は必ず swagger の description を読んでから書くこと
- `CanonicalCoupon::$has_unsupported_restrictions` は三値（`false`=Wooへ写せない制限が残っていない / `true`=残っている / `null`=アダプタが宣言していない）。`CouponWriter` は `true` と `null` の両方で保存を見送るため、新しいアダプタのクーポン変換で明示的に `false` を渡し忘れるとクーポンが1件も取り込まれない（テストで `new CanonicalCoupon(...)` を書く場合も同じ。名前付き引数 `has_unsupported_restrictions:` を使う）。Woo自身は商品・カテゴリ・メール制限をネイティブに持つが、`CanonicalCoupon` にそれらを運ぶフィールドが無く `CouponWriter` も該当setterを呼ばないため、**現時点で `false` にしてよいのは「ASP側に制限が無い」場合のみ**。「Wooに機能があるから写せるはず」で `false` を立てると制限が落ちた無制限クーポンが保存される
- カラーミー `product.images[]` には `mobile: true` の項目（PC用画像のモバイル向け重複エントリ）が混在する。フィルタせず取り込むとWoo側で画像が重複登録される
- カラーミー受注（`sale`）の `tax` フィールドは商品分の消費税のみで送料分を含まない。注文全体の税額（Wooの合計と整合する値）が必要な場合は `totals.normal_tax_amount + totals.reduced_tax_amount` を使うこと
- カラーミーの `display_state` はエンドポイントごとにenumが異なりうる（例: `GET /v1/groups`のレスポンスは`showing/hidden/showing_for_members/sale_for_members`の4値だが、`POST /v1/groups`のリクエストスキーマは`showing/hidden/members_only`の3値で別物）。修正時はレスポンス側の実際のスキーマ行を確認してから判定条件を書くこと
- `CanonicalProduct::stock`（バリエーション含む）に`null`を渡すと`Importer`は「在庫管理外＝在庫あり」と解釈する。在庫管理対象なのに実数が不明な場合は`null`ではなく`0`を返すこと
- 変換層（Transformer）が一部の行を除外・展開しうるエンティティ（バリエーション展開、非公開行の除外、変換失敗行のスキップ等）では、APIの生行数（`meta.total`等）をそのまま`Page::$total`として返さないこと。`processed`と1:1対応するとは限らず、進捗率が100%を超えたり永遠に届かなかったりする。1:1対応を保証できない場合はnullを返し、ページング終端の判定にだけ使う（`ColorMeAdapter`のproduct/customer/order/stock参照）。新しいASPアダプタでも同じ基準を適用すること
- WooCommerceのAPI挙動は「同系クラスだから同じはず」の推測が外れる。判断前に wp-env 内の実ソース（`wp-env run cli -- grep -n -A20 "function xxx" /var/www/html/wp-content/plugins/woocommerce.latest-stable/...`）か `wp eval-file` での実測で確認すること。例: `WC_Coupon::__construct()` は `'shop_coupon' === get_post_type($data)` でpost typeを検証するため、削除済みIDでも例外を投げず新規作成扱いになる（他writerで必要なstale-IDフォールバックはCouponWriterには不要）。レビュー指摘に対してテストを書いたら修正なしで通った場合は、指摘自体が誤りである可能性をまず疑うこと
- `WC_Order::set_status()` はステータスが実際に遷移したとき `maybe_set_date_paid()`/`maybe_set_date_completed()` を呼び、`date_paid`/`date_completed` に **`time()`（＝移行の実行時刻）** を打刻する。過去の受注日を保つため、日付の設定・補正は必ず `set_status()` より**後**に行うこと
- `wc_get_order_statuses()` はコアの標準ステータスに加え、WooCommerce Blocksが登録する内部ステータス `wc-checkout-draft`（チェックアウト時の一時的な下書き注文用）を含む。このステータスへ受注を設定すると、`woocommerce_cleanup_draft_orders`（日次cron、`DraftOrders::delete_expired_draft_orders()`）が24時間経過後に`WC_Order::delete(true)`で受注を完全削除する。ユーザー入力（マッピング設定等）由来のステータス文字列を検証する際は、`wc_get_order_statuses()`に存在するかだけでなく、このような「一覧には出るが受注を書き込んではいけない」ステータスも除外すること（`Woo\Support\MappingCandidates::is_disallowed_order_status()`参照。issue #39）
- variable商品の親の `stock_status` は子variationから導出され（`WC_Product_Variable::sync()`）、親へ直接 `set_stock_status()` しても保存時に子由来の値へ戻る。さらに `set_manage_stock(false)` を書くと `WC_Product::validate_props()` が `stock_quantity` を空にし、店舗が設定した親レベル在庫管理が消える。variable親には在庫を書かないこと
- `term_exists()` は名前だけでなく `sanitize_title()` 由来のslugでもフォールバック照合するため、名前が異なるのにslugが衝突する2つのタームを誤って「重複」と判定することがある（実測: `foo-bar` 作成後に `Foo Bar` を `term_exists()` で照合すると誤って前者のIDを返す）。一方 `wp_insert_term()` 自身はこの場合を拒否せず、自動サフィックス付きslugで新規作成する。重複の事前チェックは `term_exists()` ではなく `get_terms()` による名前＋親の直接比較を使うこと（`TermWriter::find_conflicting_term_id()` 参照）
- `Sync\Importer::process_items()` は per-itemの警告を**dry-runのときしか永続化しない**（`$is_dry_run` ガード内の `DryRunItemRepository::insert_many()` が唯一の書込経路）。実移行の結果レポートに個別の警告は残らないため、「警告に情報を足せばユーザーが気付ける」という前提の設計は成立しない。またdry-run行は `existing_local_id` 列（`Admin\DryRunReportCsv::HEADER`）を持つので、`WarningCode::with_detail()` を足す前にその情報が既に行に載っていないか確認すること（PR #37 でdetailを追加→冗長と判明し取り消した）
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
- `wc_get_orders()` の `meta_query`/`field_query` は HPOS の `OrdersTableQuery` だけが解釈し、レガシー投稿型ストレージでは WC 9.2+ が非対応引数として**無視**（全件が返る）＋`doing_it_wrong` を出す（`WC_Order_Data_Store_CPT::query()` の `woocommerce_order_data_store_cpt_query_unsupported_args`）。メタでの絞り込みを正とする処理は `OrderUtil::custom_orders_table_usage_is_enabled()` で引数を分岐したうえ、取得後に必ずメタを再検証すること（`MappingRebuilder::scan_orders()` 参照）
- `wp_delete_user()` は capability を一切見ない。`manage_woocommerce` ゲートの REST から呼ぶ場合、shop_manager（`delete_users` を持たない）が WP 管理画面でできないアカウント削除を行えてしまうため、必ず `current_user_can( 'delete_user', $user_id )` を併せて確認すること（`SampleCleanup::can_delete_user()` 参照）
- WooCommerce は `wc_modify_map_meta_cap()`（`map_meta_cap` フィルター）で、非管理者による管理者アカウントへの `edit_user`/`delete_user`/`remove_user`/`promote_user` を `do_not_allow` にする。テストで「capability は持っているが別ガードで止まる」ことを検証したい場合、管理者を対象にした `current_user_can( 'delete_user', $admin_id )` は WC 側で先に false になるため、自分自身など非管理者を対象に capability の開通を確認すること（`SampleCleanupTest` 参照）
- `get_posts()`/`WP_Query` の `post_status => 'any'` はゴミ箱（`trash`）と `auto-draft` を**含まない**（`exclude_from_search` が真のステータスを除外する。実測: ゴミ箱の商品は `any` で0件）。レビューbotが「`any` は trash も返す」と指摘してくることがあるが誤りなので、`wp eval` で実測して返答すること
- `wc_get_product()` は削除済みの variation ID に対して `false` ではなく中身の無い `WC_Product_Variation` を返しうる（`WC_Product_Variation_Data_Store_CPT::read()` は投稿欠損で例外を投げず、商品種別キャッシュも残るため。実測: `SampleCleanup` で削除直後の ID）。削除済みかどうかは `instanceof` ではなく `get_post()` の有無で判定すること
- DBに保存済みの値を読む用の寛容なサニタイズ関数（不正なエントリを黙って読み飛ばし、エンドポイント自体を落とさないためのもの）を、REST PUT等の書込み入力検証に流用しないこと。書込み側で使うと、1件でも不正な値（非スカラー等）を含むリクエストがそのエントリだけ読み飛ばされて「成功（200）」を返し、既存の正当なデータが黙って消える。書込み検証は専用の関数（1件でも不正なら`null`を返しリクエスト全体を拒否）に分離すること（issue #27）
- 設定値（例: `cbjp_settings_{platform}`のマッピング）を検証・保存する関数が制御文字除去やtrim等の正規化を行う場合、その値の「候補一覧」を生成する別の経路（UIのプルダウン候補等）でも同じ正規化を適用すること。候補生成側だけ正規化を怠ると、空白・制御文字のみの値が候補上は非空に見えるのに保存時には空文字列化して拒否される、または前後の空白ごと異なるキーとして保存され選んだ項目が保存後に消える、という食い違いが起きる（`Admin\RestController::normalize_mapping_token()`が3箇所で共有する形に統一した実例。issue #39）
- 破壊的操作（サンプルクリーンアップ等）のプレビュー件数は、実行側と**同じ判定関数**で算出すること。mapping 行数をそのまま出すと、他プラットフォーム所有・削除済み・親削除でカスケードする variation・共有画像の分が実行結果とズレる（PR #34 のボットゲートで G1-3/12・G2-2・G3-2 と 3 ラウンド連続で同種の指摘を受けた。`SampleCleanup::can_delete_entity()` を preview/run で共用する構成を参照）
- `_cbjp_platform` は email 突合による採用で別プラットフォームに書き換わる**可変**の所有メタ。「誰が作成したか」の判定にはこれを使わず、作成時にのみ書く不変マーカー `_cbjp_created_by_import`（値は作成プラットフォームID）を使うこと。可変メタで判定すると採用→リンク解除の後に作成元が削除できなくなる（G1-4/G2-1/G3-1）。**逆に「インポートが書いたデータを補正・更新するツール」の所有判定には `_cbjp_platform`（最後に書いたプラットフォーム）を使う**: `CustomerWriter` は採用した既存アカウントにも住所を書くため、不変マーカーで絞ると採用アカウントの誤りが直らない（`PrefStateRepair`。Codex/Copilot が不変マーカーへの絞り込みを繰り返し誤提案した。issue #46）
- `cbjp_mappings` を減らす・リセットする経路（クリーンアップ等）は、対応する実体を削除できない状況（権限不足等）では実行自体を拒否すること。unlink だけして mappings とサンプルセットを消すと `LimitPolicy` の累積カウントが消え、無料版上限（アーキテクチャ原則 7）を回避してデータを増やし続けられる（G1-11）
- `MediaImporter` は同一 `_cbjp_source_url` の添付を商品・タームをまたいで再利用する。取り込み画像を削除する処理は `post_parent` や単一の参照だけで孤児と判定せず、参照する全ての実体が消える場合のみ削除すること（R2-2/G2-2/G3-2）
- 金額突合の通貨は現在の店舗設定（`get_woocommerce_currency()`）ではなく、受注に保存された `WC_Order::get_currency()` から判定すること（店舗通貨は後から変えられる。ASP 側通貨は `OrderWriter::PLATFORM_CURRENCY`。G3-3）
- カラーミーの決済/配送方法のID→名称は `GET /payments.json`/`GET /deliveries.json` で取得できる（`ColorMeAdapter` が `OrderTransformer` 用の名称マップ構築に内部利用済み。同じ取得ロジックを `ColorMeAdapter::mapping_candidates()` がE2-1のマッピングUI向け候補一覧としても再利用している）。OAuth接続さえ済んでいればColorMe管理画面へのブラウザログイン（副管理者アカウントでは受注詳細等の一部ページが権限不足で見られないことがある）は不要
- `WC_Order_Item_Product::get_product_id()` は（バリエーション明細でも）常に**親商品ID**を返す。バリエーション自体のIDは別メソッド `get_variation_id()`（バリエーションでなければ0）。受注明細からサンプル選定・商品解決を行う処理（`Sync\ExportSampleSelector` 等）で `get_product_id()` を使えば、バリエーションは自動的に親商品1件へ畳み込まれる（D15「バリエーションは親商品で1件」を満たすために追加のロジックが不要）
- `WC_Product_Variation::get_manage_stock( 'view' )`（既定コンテキスト）は、バリエーション自身が在庫管理していなくても親が管理していれば文字列 `'parent'`（truthy）を返し、`get_stock_quantity( 'view' )` もこの場合**自動的に親の在庫数を返す**。ただし親レベルの在庫は複数バリエーションで共有される単一プールであり、`get_manage_stock() !== false` を「バリエーション個別の管理対象」の判定に使い各バリエーションへ同じ数量をそのまま複製すると、実在庫のバリエーション数倍を販売可能数量として申告してしまう（金銭的リスク。`'parent'` の場合は在庫切れ等にフェイルクローズして警告すること。`Woo\Reader\ProductReader::variation_stock()` 参照）
- `wc_get_products( [ 'paginate' => true, ... ] )` は配列ではなく `(object) ['products' => WC_Product[], 'total' => int, 'found_posts' 相当, 'max_num_pages' => int]` を返す（`WC_Product_Data_Store_CPT::query()`）。`products` は復元失敗した投稿を `array_filter()` で除外するため、キーが飛び番になりうる（`array_values()` が必要）
- エクスポート方向の `cbjp_dry_run_items`/`cbjp_mappings` は列の意味を読み替えて使う（スキーマ変更なし）。`cbjp_mappings` は `local_id`/`remote_id` とも方向を持たない設計だが、export方向は起点が常にWooローカルID（`MappingRepository::find_remote_id()`/`find_many_by_local_ids()` で逆引きする）。`cbjp_dry_run_items.remote_id`（`NOT NULL`・`UNIQUE(job_id, entity, remote_id)`）は新規作成候補（既存remote_idが無い）の行では一意性確保のためプレースホルダ `local:{local_id}` を入れ、`existing_local_id` 列にWooローカルIDを格納する（`Sync\Exporter::dry_run_row()` 参照）
- `cbjp_mappings.checksum` は `CHAR(64)`固定長（生のsha256 hex digest専用）で、export/import等どちらか一方の書込みに文字列プレフィックスを付けて名前空間を分けるような拡張はできない（MySQLが黙って切り詰める）。同じ`(platform, entity_type, remote_id)`行を複数の書込元が共有し、かつ「生ハッシュとして同じ値でも意味が異なる」場合は、`hash('sha256', 名前空間文字列 . $item->canonical_json())`のようにハッシュ対象（入力）側に名前空間を混ぜ込むこと（出力は引き続き64文字に収まる。`Sync\Exporter::export_checksum()`参照。issue発覚: E2-2 R1でimport/exportが同じchecksum列を生ハッシュのまま共有し、一方が他方の変更検知を破壊しかけた）
- `MappingRepository::upsert()`は`(platform, entity_type, remote_id)`のユニークキーで`ON DUPLICATE KEY UPDATE`するため、同じlocal_idに対してアダプタが異なるremote_idを返した場合（例: リモート側で削除された実体をupdate時に再作成した）、新remote_idで別行がINSERTされるだけで旧remote_idの行が孤児として残る。`find_remote_id()`/`find_many_by_local_ids()`はid昇順の最初の行を採用するため、以後は削除済みremote_idへ永久に再送し続け、無料版の累計カウントも余分に消費する。remote_idが変わったことを検知したら`upsert()`の前に`delete_one()`で旧行を消すこと（`Sync\Exporter::process_items()`参照。PR #40 G3）
- `WC_Product_Variable`の`_regular_price`/`_sale_price`は`WC_Product_Variable_Data_Store_CPT::sync_price()`が保存の度に削除するため、`WC_Product_Variable::get_regular_price()`/`get_sale_price()`は常に空文字列を返す（親は`_price`にバリエーションの価格帯のみ複数値で保持する）。variable商品の価格を読む処理でこれを無条件に使うと「0円」に丸まってしまう（金銭的リスク）。代表値が必要な場合は`get_variation_regular_price('min', false)`（定価）を使うこと。**`get_variation_price()`は使わない**（実効価格＝セール中はセール価格を返すため、期間限定セールがASP側等へ恒久的な定価として焼き付く）。さらにどちらの関数も、公開かつ（`woocommerce_hide_out_of_stock_items=yes`設定次第で）在庫ありのバリエーションが1件も無い場合（`get_visible_children()`が空集合。全バリエーション非公開、または当該設定＋全バリエーション在庫切れ等）、PHPの`current([])`規約により**bool `false`**を返す（WC自身の規約ではない）。戻り値を`declare(strict_types=1)`下の非nullable`string`へ無条件に渡すと`TypeError`になるため、`is_string()`でガードしフェイルクローズすること（`Woo\Reader\ProductReader::price_fields_for_variable()`参照。issue: E2-2 R2でこの2点を両方見落とし、Critical〈ジョブ恒久失敗〉+ High〈セール価格流出〉を作り込んだ）
- `WC_Product::get_sale_price()`はセール開始/終了日程（`date_on_sale_from`/`date_on_sale_to`）を一切考慮しない生の`_sale_price`をそのまま返す。日程を考慮するのは`is_on_sale()`（`edit`コンテキストなら表示用フィルターを経由しない）のみ。セール価格をそのまま他システムへ転記する処理では`is_on_sale('edit')`で先にゲートし、`ProductWriter::resolve_sale_price()`と同じ基準（数値・0より大きい・通常価格未満）でも検証すること
- 明示的なカテゴリを持たない商品を`wp_insert_post()`経由で保存すると、WooCommerceが`get_option('default_product_cat')`（通常「未分類」）を自動付与する（`WC_Product_Data_Store_CPT::update_terms()`の`empty($categories)`ガード）。この付与はカテゴリを"never touched"の場合だけでなく、`set_category_ids([])`等で明示的に空へ解決された場合も同様に発生する。カテゴリ関連のテスト・ロジックでこの自動付与を意図的に避けたい場合は、有効な（マッピング済みの）カテゴリを最低1つ明示的に割り当てること
- `WC_Product_Variable::get_children()`は`publish`/`private`両方のバリエーションIDを返す（`get_visible_children()`は`publish`のみ）。非公開バリエーションをそのままエクスポート等でリモートへ渡すと、マーチャントが意図的に非公開にした在庫が復活しうる（金銭的リスク）。バリエーションを列挙する処理では目的に応じてどちらを使うか明示的に選ぶこと（`Woo\Reader\ProductReader::variants()`参照。PR #40 G2）
- `WC_Product::get_tax_class()`（標準/軽減税率等のスラッグ）と`get_tax_status()`（`taxable`/`shipping`/`none`）は独立したフィールド。`tax_class`だけを見て`tax_status='none'`（非課税）や`'shipping'`（送料のみ課税）を無視すると、非課税商品が通常課税として扱われる。`CanonicalProduct`のように`tax_status`を運ぶフィールドが無いモデルへ変換する場合は最低限警告すること（`Woo\Reader\ProductReader::to_read_item()`参照。PR #40 G3）
- `WC_Product_Variable::get_visible_children()`は`publish`ステータスだけでなく、店舗の`woocommerce_hide_out_of_stock_items`設定が有効な場合は在庫切れバリエーションも暗黙に除外する。在庫連携（エクスポート等）でこれを使うと、バリエーションの在庫がゼロになった瞬間にその行ごと消え、「在庫切れ」という状態そのものを相手側へ伝える手段が無くなる。非公開バリエーションの除外だけが目的なら`get_children()`＋`'publish' !== $variation->get_status()`の明示チェックを使うこと（`Woo\Reader\ProductReader::variation_axis_attributes()`と同じ判定。`Woo\Reader\StockReader::items_for_variable()`参照。issue: E2-2 PR-Bの計画段階では`get_visible_children()`を使う設計だったが実装時に気付いて訂正した）
- `WC_Coupon`（他`WC_Data`系オブジェクトも同様の疑いあり）を`new WC_Coupon()`で新規作成して`->save()`し、その後に**同一インスタンス**へ追加のsetterを重ねて再度`->save()`すると、`post_status`が黙って`draft`へ落ちる（実測確認済み）。`WC_Coupon_Data_Store_CPT::create()`（1回目のsave）は状態プロパティ未設定（`null`）なら`post_status`を`'publish'`へフォールバックするが、この決定をインメモリの`status`プロパティへは書き戻さないため、`update()`（2回目以降のsave）は`$coupon->get_status('edit')`（＝`null`のまま）をそのまま`wp_update_post()`へ渡してしまう。全フィールドを1回の`save()`で確定させるか、再利用する前に`set_status('publish')`を明示すること
- `WC_Coupon::set_code()`はコードを小文字へ正規化して保存する（`wc_format_coupon_code()`）。大文字を含むコードで作成しても`get_code()`は小文字で返る
- `WC_Order_Item_Product::set_product_id()`/`set_variation_id()`は投稿タイプ検証を持ち、参照先が削除済みだと`WC_Data_Exception`を投げる。データストアの`read()`が呼ぶ`WC_Data::set_props()`はこれをプロパティ毎にcatchするため、`get_product_id()`/`get_variation_id()`自体が既定値`0`を返してしまい、削除済み参照と「一度も持たない」参照をCRUD層では区別できなくなる（`WC_Coupon::set_amount()`と同じパターン）。生のorder-item-meta（`get_metadata('order_item', $id, '_product_id'/'_variation_id', true)`。CRUD層の検証を経ないため削除後も元のIDのまま残る）を直接読めば区別できる（`Woo\Reader\OrderReader::remote_product_id()`参照。PR #41）。なお同種の「`set_props()`がプロパティ毎に例外を握りつぶす」パターンでも安全なフォールバック先はプロパティ毎に異なる（`WC_Coupon::set_date_prop()`は解釈不能な値をUNIXエポックへ安全側解決するが、`set_minimum_amount()`は検証自体を持たず壊れた値をそのまま返す）ため、「似たプロパティだから同じ挙動のはず」と推測せず個別に実測すること
- WooCommerce本体は既定で`add_filter('woocommerce_stock_amount', 'intval')`（`wc-core-functions.php`。コメント「Stock amounts are integers by default.」）を登録し、`WC_Order_Item_Product::set_quantity()`等の数量を常にintへ丸める。量り売り等の小数量拡張はこの既定フィルターを外すため、`declare(strict_types=1)`下でfloat数量を`int`引数の関数へ無条件に渡すと`TypeError`でページ全体の処理を落としうる。数量を扱う関数は実行時の型を信用せず検証すること（`Woo\Reader\OrderReader::line_items()`参照。PR #41）
- `manage_stock=true`でも`stock_status`が明示的に`outofstock`のまま`stock_quantity`が正の値、ということがありうる（`woocommerce_notify_no_stock_amount`＝WooCommerce設定「在庫切れ通知のしきい値」。`WC_Product::validate_props()`が`save()`の度にこの状態を作る）。数量だけを見て在庫ありと判断せず`is_in_stock()`を優先すること（`Woo\Support\StockDerivation`参照。PR #41）
- 同一フィールド名でもASPの書込み（POST/PUT）リクエストスキーマと読出し（GET）レスポンススキーマが別物のことがある。カラーミー`POST /products/{id}/options`の`values`はリクエストでは**オブジェクトの配列**（`[{"name":"赤"}]`）だが、`GET /products.json`の`options[].values`は**文字列配列**（`["赤","青"]`）。GETレスポンスのスキーマをそのまま書込みに流用すると422になり、修正しなければバリエーションが1件も作成できない（issue #43）。書込み系エンドポイントは必ずswaggerの`requestBody`定義を個別に確認すること
- 1エンティティのpushが複数リクエストに分割される実装（例: 商品本体→バリエーション→画像）では、各サブリクエストの失敗を「再試行対象」（429/5xx/通信断）と「終端」（その他4xx。再試行しても解決しない）に分類し、`RateLimitExhaustedException`は**全てのサブリクエストのcatchで最優先に再スロー**すること（握り潰すとレート制限時にジョブ全体を一時停止する契約が壊れる）。`ColorMeAdapter::is_retryable_failure()`/`record_failure()`/`append_failure_warning()`参照（issue #43）。他ASPのpush実装・push_customer/order/stockでも同じパターンを踏襲する
- 税込⇔税抜の相互変換で丸め方式（切り捨て/切り上げ/四捨五入）を実装する場合、順方向（税抜→税込）と逆方向（税込→税抜）で**同じ丸め方向を使うと往復が一致しない**（切り捨て・切り上げは逆方向の丸めが真の逆演算になる。四捨五入は同方向でよい。`php -r`で6000通りのnet/rate組を全数検証済み。`ProductTransformer::divide_with_rounding()`参照、issue #43）
- `Woo\WarningCode::indicates_export_blocking()`へ新しい警告コードを追加する前に、その警告が「フレッシュな環境でも既定で発火するか」を確認すること。`PRICES_INCLUDE_TAX_DISABLED`（`woocommerce_prices_include_tax=no`）は多くの実店舗の既定設定で、blocking化すると無料版の挙動確認自体ができなくなる店舗が続出する（`JobManagerExportTest`が実際に2件壊れて発覚。issue #43）。少数の境界条件でしか発火しない警告（`PRODUCT_PRICE_INVALID`等）とは扱いを分けること
- **【重大】カラーミーの `pref_id` はJIS X 0401（＝Wooの`JPxx`）と並びが一致しない（23県。例: `pref_id=4`は秋田だがJIS/Wooの4は宮城、16↔18、19〜23・25〜34・36〜37・43〜44）**。番号をそのまま同一視しない（`AddressMapper::PREF_ID_TO_JIS_NUMBER`、issue #44）。対応表はswaggerの`info.description`の**散文**（`<details>`内のMarkdown表）にしか無く、JSON実例1件から「標準通り」と一般化すると誤る（一度誤った）。**フィクスチャ既定の`pref_id=13`（東京）は表の固定点で何も検出できない**ため、テストには4/5・19〜23・25〜30等の固定点でない値を使うこと
- Writer側の変換（例: `AddressMapper`）を直しても、Canonicalが**変換前の生の値**を保持している限りchecksumは変わらず、`Importer`のchecksum一致スキップにより**取込み済みデータは再インポートで直らない**。Woo側に生の値が残らないと修正前後のデータは値だけで判別できず（入れ替え・巡回は一律の再適用が二重適用になる）、是正は「ASPから権威の値を再取得し、現在値が旧バグの出力と一致する場合に限り書き換える」ツールで行う（`Woo\Tools\PrefStateRepair`、issue #46）
- `WC_Abstract_Order::save()` は保存中の例外（他プラグインの`woocommerce_before_order_object_save`フック等）を**内部で握りつぶしてログに残し、IDを返す**（WC 11.1の`abstract-wc-order.php`で確認）。呼び出し側からは成功に見えるため、受注を書き換える処理は`wc_get_order()`で読み直して書けたことを確認する（表示フィルターの影響を受けない`edit`コンテキストで）。`update_user_meta()`も同値更新と書込み失敗の両方でfalseを返し区別できない（issue #46）
- `ApiException`のステータス0は「未接続（`ColorMeAdapter::client()`）」「通信断（`HttpClient`のWP_Error）」「JSON破損（`ColorMeClient`）」のすべてで使われ、**0だけでは判別できない**。「再接続が必要」と案内してよいのは401/403か`context['not_connected'] === true`（アダプタが明示）のみ。ASPが429を返してリトライ上限に達した場合は`is_rate_limited()`（`RateLimitExhaustedException`はクライアント側スロットルのみ）。新しいアダプタは未接続を`not_connected`で明示すること（issue #46）
- 正規表現で「完全に指定パターンとだけ一致するか」を検証する場合、終端アンカーは`$`ではなく`\z`を使うこと。PCREの`$`は末尾改行の直前にもマッチするため、`^[0-9\-]+$`のような形式チェックは末尾に`\n`が混入した値（例: `"0312345678\n"`）を誤って「一致」と通してしまう
- カラーミーの`address1`は「市区町村・番地」を1フィールドに含むが、WooCommerceのJPロケール（`WC()->countries->get_address_fields('JP')`）は`billing_city`（市区町村）と`billing_address_1`（番地）を別の必須フィールドとして扱う。エクスポート方向でColorMeへ住所を送る際は`city`+`address_1`の連結が必要（`Woo\Support\AddressMapper::to_asp_address_payload()`参照。E2-3 PR-Cで`CustomerTransformer`から移設し、新設`OrderTransformer`（配送先・ゲスト顧客）とも共有する形にした）
- `mb_strlen()`等のmbstring関数はホストにmbstring拡張が無くても、WordPress core自身（`wp-includes/compat.php`）が`function_exists()`ガード付きのポリフィルを無条件に提供する。プラグインコードは常にWPブートストラップ後に実行されるため、レビューbotが「mbstring拡張の宣言漏れ」を指摘しても誤りであることが多い（`composer.json`への`ext-mbstring`追加は不要。issue #44）
- ASP側のpush系API（例: カラーミー`sale.details[].price`）が単価×数量方式の場合、Woo側の明細合計を数量で割った値をそのまま丸めて送ると、割り切れない数量（例: ¥1000を3個で割ると¥333.33...）で単価×数量が実際の合計と一致しなくなる（`price=333, product_num=3`→ColorMe側は¥999として計算し¥1円分が消える）。この場合に「単価を省略してASP側のカタログ価格へフォールバックする」設計は、それ自体が`remote_id`確定後は再試行されない恒久的な金額の食い違いを生む（`PRODUCT_PRICE_INVALID`と同じ金銭的リスクの構図）。単価を復元できない明細がある場合は省略せず受注全体をブロックすること（`Adapters\ColorMe\Transform\OrderTransformer::unit_price_divides_evenly()`参照。issue #45。Codexレビューで自分自身が入れた「省略フォールバック」修正の危険性を1ラウンド後に指摘され撤回した）
- `Adapters\ColorMe\Transform\Cast::to_string_or_null()`はリテラルな空文字列`""`のみをnullへ正規化し、空白のみの値（例: `"   "`）は非空文字列として扱う。手入力ミス・不正なCSV取込等で境界データが空白のみになりうる「存在チェック」（例: 配送先住所の有無判定）にこれを使うと、空白だけの値を「存在する」と誤判定し正しいフォールバック（例: 請求先住所）が起きない。この用途では`Cast::to_meaningful_string_or_null()`（トリム後に空ならnull。値自体はトリムしない）を使うこと（issue #45）
- 新しい警告コードを定義しても、それを消費すべき既存の判定関数（`Woo\WarningCode::indicates_export_blocking()`等）への登録が漏れることがある。`ORDER_LINE_TAX_CLASS_UNSUPPORTED`（非課税・zero-rate等）は定義済みだったが`indicates_export_blocking()`に未登録のまま残っており、対応するASPの税区分と異なる税額で受注が恒久的に作成されうる状態だった（Codex・Copilotが独立に同一箇所を指摘。issue #45）。新規コードを定義した際は、それが本来どの既存判定関数の対象であるべきかを確認し、類似コードと横並びで登録すること

## フロントエンド（React/TypeScript）規約

- ポーリングhook（`useRunPolling`等）の`refetch()`が一時的な通信エラーを内部でcatchして自動再試行する設計の場合、そのPromiseは通信の成否に関わらず正常解決する。呼び出し元が「`await refetch()`が解決した＝新しいstateが反映された」と決め打ちすると、一時的な失敗時に古いstateのまま後続処理（例: ボタンの再有効化）が進んでしまう。真に「新しいデータが届いた」ことを検知したい場合は、Promiseの解決ではなくstate自体（成功時のみ新しい参照になるオブジェクト等）の変化をeffectで監視すること（`src/hooks/useRunPolling.ts`, `src/tabs/ImportTab.tsx`のretryJob参照。issue #30）
- 複数ジョブから成るrunの「終端判定」（`isTerminal`: 全ジョブがcompleted/failed/cancelledのいずれか）と「成功判定」（全ジョブがcompleted）を混同しないこと。キャンセル・一部失敗したrunも`isTerminal`はtrueになるため、「完了時のみ出す」UI（全体レポートDL・アップセル集計等）を`isTerminal`だけでゲートすると、部分的・失敗した結果を完全な結果として提示してしまう。個別の判定（全ジョブcompleted）を別途用意すること（`src/components/RunProgress.tsx`のallCompleted参照。issue #30）
- WordPressコアが登録する`wp-components`等の共有アセットハンドルは、WooCommerce等の他プラグインが独自バージョンを登録しているとそちらが優先されることがあり、同じ`@wordpress/components`の`Notice`等でも環境によってレイアウト（例: `flex`+`padding: 8px 12px` vs `grid`+`padding: 12px`）が変わりうる。見た目の余白等を安定させたい箇所はアップストリームのデフォルトに依存せず自前CSSで明示的に上書きすること（`src/style.css`の`.components-notice.is-info`参照）
- 破壊的・本番書込み系の確認ダイアログ（`ImportTab.tsx`の本移行実行確認、`ToolsTab.tsx`のクリーンアップ確認）にネイティブ`window.confirm()`を使っている。ブラウザ拡張系の自動操作（Claude in Chrome等）やE2Eツールからクリックすると、ネイティブダイアログがレンダラーをブロックしてタブがフリーズし、ブラウザ再起動が必要になることがある（F1-8の実店舗テストで発生）。将来wp-e2e-playwright等でE2Eを自動化する場合も同じ問題になるため、`@wordpress/components`のモーダル等ネイティブダイアログに依存しない確認UIへの置き換えを検討すること
- 非同期処理（fetch effect・保存処理等）の古い応答が新しい状態を上書きしないようにするガードは、比較対象を「値の一致」（例: プラットフォーム名）ではなく**単調増加する世代カウンタ**にすること。値の一致だけで判定すると、同じ値へ短時間で戻った場合（例: プラットフォームA→B→A）に古いリクエストの応答を「最新」と誤認する。さらに、同じ状態を更新しうる複数の非同期処理（例: 取得のGETと保存のPUT）がある場合は、それら**すべてが同じ世代カウンタを共有**する必要がある。片方だけ導入してももう片方が古い判定方法のままだと同種のレースが残る（`src/tabs/ExportTab.tsx`の`platformGenerationRef`参照。issue #39）。`ToolsTab.tsx`は置換済み（issue #46）。**`ImportTab.tsx`には値比較の`platformRef`が残っている**（backlog `fix-46-pref-state-repair/R1-X1`）

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
- 開発サイクル（計画→ブランチ→実装→review-loop→PR→CI→Codex/Copilot ゲート→最終報告）はプロジェクトスキル `/cbj-dev-cycle`（`.claude/skills/cbj-dev-cycle/`。ボットゲート用スクリプト同梱）で回す。汎用の `dev-cycle` は直接使わない
- 各フェーズ完了時に `composer lint && composer analyze && composer test:wpenv` を通すこと（`composer test` はホストから動かない。上の「コマンド」参照）
- 不明なAPI仕様は推測で実装せず、`docs/` の「要検証」項目として記録し、フィクスチャを用意してから実装
- コミットメッセージは Conventional Commits（`feat:`, `fix:`, `refactor:` ...）
