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

## アーキテクチャ原則（詳細は docs/00-plan-overview.md）

1. **アダプタパターン**: 各ASPは `Adapters\PlatformAdapter` インターフェースの実装。プラットフォーム固有コードをアダプタ外に書かない
2. **正規化モデル**: ASP⇔Woo間は必ず `Canonical\*` モデル（CanonicalProduct等）を経由。直接変換禁止
3. **capability宣言**: 各アダプタは `capabilities()` で可否（カテゴリ作成可否・削除可否等）を宣言し、UI/ジョブ側が分岐
4. **破壊的操作の禁止**: リモート側データのDELETEは行わない（MakeShopは技術的に可能だが、削除は「非公開化」提案に留める）。ローカル側も上書き前にdry-run/プレビューを提供
5. **レート制限遵守**: 全API呼び出しは `Support\RateLimiter` 経由（カラーミー: 120req/分）
6. **再開可能なジョブ**: バッチはカーソル方式で中断・再開可能に。進捗は `cbjp_jobs` テーブルに永続化
7. **無料版/Pro版の分離**: 本リポジトリは無料版（dry-runは全量、実移行はサンプル上限つき。`docs/03-design-decisions.md` §10）。Pro版（上限解除・買切りライセンス）は別プラグインがフック（`cbjp/limits/*` 等）で拡張する設計にし、Pro固有コードは含めない。継続同期は販売しない（D14）
8. **アダプタ拡張点の信頼境界**: `cbjp/adapters/register` フィルターはPro版アドオン等の外部コードが使う拡張点。返り値の型はdocblock上の契約でしかなく実行時に強制されないため、アダプタの戻り値（`connection_fields()` 等）は信用せず防御的に検証する（不正な1アダプタが全体のAPIエンドポイントを落とさないように）

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
- 各フェーズ完了時に `composer lint && composer analyze && composer test` を通すこと
- 不明なAPI仕様は推測で実装せず、`docs/` の「要検証」項目として記録し、フィクスチャを用意してから実装
- コミットメッセージは Conventional Commits（`feat:`, `fix:`, `refactor:` ...）
