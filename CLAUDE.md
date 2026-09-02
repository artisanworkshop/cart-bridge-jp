# Cart Bridge JP – Migrate for WooCommerce

日本のECサイトASP（カラーミーショップ、MakeShop、BASE、将来的に他ASP）とWooCommerce間で、商品・顧客・受注データの移行を行うWordPressプラグイン。無料版は挙動確認用サンプル移行、Pro版（別プラグイン）で無制限化する（`docs/03-design-decisions.md` D14）。

## 開発計画ドキュメント（必読）

作業前に必ず該当ドキュメントを読むこと:

- `docs/00-plan-overview.md` — 全体アーキテクチャ・フェーズ計画・DB設計・命名規約
- `docs/01-plan-colorme.md` — カラーミーショップAPI仕様とアダプタ実装計画
- `docs/02-plan-makeshop.md` — MakeShop API（GraphQL）仕様とアダプタ実装計画
- `docs/03-design-decisions.md` — 確定した設計判断・詳細設計（**他の計画ドキュメントと矛盾する場合はこちらを優先**）
- `docs/04-plan-base.md` — BASE API仕様とアダプタ実装計画
- `docs/10-tasks.md` — 実装タスクWBS（タスクの進行管理台帳。各セッションはここから着手タスクを選ぶ）

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
- `register_rest_route()` で `args` スキーマ（type検証）を定義しないルートは、クエリパラメータが配列（例: `?job_id[]=1`）で渡り得る。スカラー値を期待するパラメータは `is_scalar()` で検証してから使うこと
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
- `rest_pre_serve_request` 等「1回だけ発火して自身をremove_filterする」前提のフィルターコールバックは、そのフィルターが必ず発火するとは限らない経路（`rest_do_request()`/`$server->dispatch()` 直接呼び出し等）があると自己解除されず残留し、後続の無関係な呼び出しで誤発火しうる。コールバック内でオブジェクト同一性等により「自分宛の呼び出しか」を判定するガードを必ず入れること。コア由来フィルターの引数型はdocblockで確認する（`rest_pre_serve_request` の第2引数は `WP_REST_Response` ではなく `WP_HTTP_Response`）

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
