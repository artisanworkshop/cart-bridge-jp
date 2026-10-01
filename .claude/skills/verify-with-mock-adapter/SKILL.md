---
name: verify-with-mock-adapter
description: >
  ライブの OAuth 接続なしで、wp-env の開発サイト上で Cart Bridge JP の REST・管理画面（React）を検証する手順。
  mock アダプタを mu-plugin で任意のプラットフォームキー（例: `colorme`）に登録し、修正前のデータ（旧バグの出力・
  一部だけ壊れた状態）を実 Writer で再現して投入 → `rest_do_request()` と管理画面で Scan/Repair/Import/Export を通し →
  投入したものを完全に撤去する。「mock アダプタで確認して」「OAuth なしで実機確認したい」「旧データを再現して是正ツールを
  試したい」「Tools/Export/Import を実際に動かして見たい」「検証用データを片付けて」などと言われたら使う。
  データ是正系のツール（県コード修復のような）、Export/Import の配線、Tools タブの追加を実装した後の実機確認が主な用途。
---

# /verify-with-mock-adapter — OAuth なしの実機確認

実店舗の認証情報が無い（副管理者では ColorMe の再認可が通らない等）状況でも、REST と React UI を実際に動かして確認できるようにする。
**単体テストでは見えない層間のズレ**（Transformer → Canonical → Writer → ツールの入力の食い違い、UI の状態遷移、ブラウザ固有の問題）
を見つけるのが目的で、単体テストの代わりではない（`PrefStateRepairTest` のような結合テストも併せて書く）。

## いつ使うか / 使わないか

- 使う: 新しい `/tools/*`・Export/Import の REST・Tools タブの UI を実装した直後。既にインポート済みの誤ったデータを直すツールの検証（旧出力の再現が要る）。
- 使わない: 実 API の応答形の確認（mock は実 API を返さない。認証情報が揃ってから実店舗で確認する。PR 本文に「実 API 未確認」と明記する）。
  実アダプタ（`ColorMeAdapter` 等）を偽のキーで登録する検証（下記「してはいけないこと」）。

## 手順

`S=.claude/skills/verify-with-mock-adapter`（リポジトリルートから実行）。wp-env は起動済みであること（`npx wp-env start`。ポートは
`.wp-env.json` で固定。dev は 10010。旧手順の `.wp-env.override.json` が残っていると旧ポートで起動するので、
`cbj-dev-cycle` の Step 0 に従って先に消す）。

1. **開発サイトの既存データを確認する**（実データを汚さないため。最初に必ず）:
   `$S/scripts/mock-adapter.sh inspect` — `cbjp_mappings` の platform 別件数・`cbjp_*` オプション・ユーザー数・受注数を出す。
   実 platform（例: `colorme`）の行が既にある場合、その platform の mock 登録は「追加のみ・撤去は自分の投入分だけ」で行う。
2. **mock アダプタを登録する**: `$S/scripts/mock-adapter.sh install <platform-key>`
   （例: `colorme`。県コード修復のように `AddressMapper` が `colorme` だけを解釈するツールは、そのキーで登録する必要がある）。
   `templates/mu-plugin-mock-adapter.php` を wp-env の `mu-plugins/` へ置く。mock は `tests/unit/Fixtures/MockPlatformAdapter`
   （composer の autoload-dev。`composer install` 済みなら dev サイトから使える）で、オプション `cbjp_verify_seed` から
   顧客・受注を組み立てる。このオプションは **PHP 配列を `update_option()` で保存する**（JSON 文字列ではない。mu-plugin は
   `get_option()` の戻り値を配列として読む。配列でない値・配列でない行は読み飛ばす）。
   `cbjp_verify_seed.push`（`{ enabled: bool, create_failure: 'ambiguous_5xx'|null }`）は mock の `push_*()` を有効にし（既定は全て
   `UnsupportedOperationException`）、`create_failure` は作成 POST が 5xx（結果不明）になる経路を再現する（D21-B）。
   Export タブの Beta 表示・既定オフ・能力による項目の出し分け（D24）を見るときは、`cbjp_verify_seed.capabilities`
   （`{ can_create_order, can_push_images, beta_features }`。省略したキーは mock の既定）で mock の `capabilities()` を上書きできる
   （プレミアム相当は `can_*=true`＋`beta_features=['order_export','image_push']`、非プレミアム相当は `can_*=false`）。
   Mappings タブと Import タブの事前チェック（R3-0m）を見るときは、`cbjp_verify_seed.mapping_candidates`（`{ payment: [{id,name}], shipping: [...] }`。
   mock の `mapping_candidates()` がそのまま返す）と、受注ごとの `payment_method_id`/`payment_method_name`/`shipping_method_id`/`shipping_method_name`
   （canonical の `payment`/`shipping` の `method_id`/`method_name`）を入れる。マッピングの設定（`cbjp_settings_{platform}`）は Mappings タブ
   （`#/mappings`）か `PUT /settings/mappings/{platform}` で行い、撤去時はそのオプションも消す。
   無料版の上限と Pro 版の案内先は `cbjp_verify_seed.limits`（`{ entity: int|null }`。null は Pro 相当の解除）と `cbjp_verify_seed.pro_url`
   （`cbjp/limits/pro_url` にそのまま渡り、`LimitPolicy::pro_url()` が検証する）で差し替えられる。**サイト全体に効き**、フィルターが呼ばれた時点で
   読むので同じプロセス内で seed を書き換えても効く。使い終わったらキーを外す（R3-0h）。
   **クラス定義を mu-plugin のトップレベルに書かない**（mu-plugins は通常プラグインより先に読み込まれ、
   autoloader がまだ無い）。`plugins_loaded` のコールバック内で `new` する。
   mock の `id()` は登録キーと同じ値を返す（`platform_id`）。`Importer`/`Exporter`/`JobManager` は mapping・上限・サンプルのキーを
   登録キーではなく `$adapter->id()` から決めるため。したがって `colorme` で登録して Import/Export を `JobManager` 経由で回すと、
   実 `colorme` の mapping と無料版の上限（`LimitPolicy`）を共有する。Import/Export の配線だけを見たいなら、実 platform と
   衝突しないキー（例: `mockv`）で登録する。`colorme` で回すなら `ZZV-` の remote_id と手順 6 の撤去を必ず守る。
3. **修正前のデータを再現して投入する**（実 Writer を使う）: `$S/scripts/mock-adapter.sh run <seed.php>`。
   seed は `WooRepositoryFactory::for_platform()->write()` / `OrderWriter` で実際に取り込み → 旧コードの出力へ書き戻す（例:
   県コードは `JP{pref_id}` の恒等変換）。投入した ID を `cbjp_verify_ids` オプションに記録し、`cbjp_verify_seed` に mock へ渡す
   データを保存する。`examples/prefecture-repair/seed.php` が実例。投入する実体の `remote_id` は `ZZV-` 接頭辞にする（撤去時の目印）。
4. **REST で検証する**: `$S/scripts/mock-adapter.sh run <verify.php>`。`wp_set_current_user(1)` のうえ `rest_do_request()`。
   **クエリ文字列をルートに含めず `set_query_params()` を使う**（含めると `rest_no_route`）。
   Scan（読取専用）が書かないこと・Repair 後に期待値になること・再実行で変更 0（冪等）を確認する。実例: `examples/prefecture-repair/verify-rest.php`。
5. **管理画面で確認する**（React の変更があるとき）: `npm run build` → Chrome で `http://localhost:<port>/wp-admin/admin.php?page=cart-bridge-jp#/tools`。
   - **ログインはユーザーに依頼する**（アシスタントはパスワードを入力できない）。
   - **ビルドし直したら cmd+r でリロードする**。ハッシュだけが違う URL への `navigate` は SPA を再読込せず、古いバンドルが残る
     （文言が古いまま、と気付いたらこれ）。
   - ネイティブの `window.confirm()` を出すボタン（Sample data cleanup 等）をブラウザ自動操作で押さない（タブがフリーズする）。
   - 確認後にコンソールエラーを見る（`read_console_messages` の `onlyErrors`）。
6. **完全に撤去する**（必須）: `$S/scripts/mock-adapter.sh run <cleanup.php>`（投入したユーザー・受注・mapping・オプションを削除。
   `examples/prefecture-repair/cleanup.php`）→ `$S/scripts/mock-adapter.sh uninstall`（mu-plugin を削除）→ `inspect` で
   手順 1 の状態に戻ったことを確認 → リポジトリ内の一時ファイルを削除。**一時ファイルはコミットしない**（`git status` で確認）。

## 落とし穴（PR #80 の Export タブ UI 検証で実際に踏んだもの）

- **`mock-adapter.sh run` に渡す PHP はリポジトリ内に置く**（`wp eval-file` はコンテナ内で実行されるため、`/tmp` やスクラッチパッドはコンテナから見えない）。コミット前に削除する。
- **`AdapterRegistry::all()` は静的キャッシュ**。同一 PHP プロセス内で `cbjp_verify_seed` を書き換えて再検証するなら、書き換えの直後に `CartBridgeJP\Adapters\AdapterRegistry::reset_cache()` を呼ぶ（呼ばないと最初に組み立てた mock がそのまま使われ、seed の変更が効かない）。
- **`cbjp_process_job`（Action Scheduler）は CLI では自走しない**。`start_run` の後、検証スクリプト内で pending を処理する（`as_get_scheduled_actions( [ 'hook' => 'cbjp_process_job', 'status' => 'pending' ] )` → `do_action_ref_array( $action->get_hook(), $action->get_args() )` → `ActionScheduler_Store::instance()->mark_complete()`）。
- **mock のキー（例 `mockv`）は既定では `connected` ではないので、Export/Import タブの platform 選択に出ない**（`ExportTab` は `c.connected` の platform だけを扱う）。タブに出すには、検証スクリプトで偽のトークンを保存して connected にする: `( new CartBridgeJP\Support\TokenStore( 'mockv' ) )->save( [ 'access_token' => 'verify-<作業名>' ] )`（実 `colorme` を汚さない。R3-0h）。撤去時は値を確かめてから `cbjp_token_mockv` を消す（`push-intent-resolution/cleanup.php` はトークンが残っていると拒否する）。`push` 系の挙動は `cbjp_verify_seed.push`（`enabled`/`create_failure`）で切り替える。
- **REST（`rest_do_request()`）で走らせた run を管理画面に出すには、localStorage の `cbjp_run_{type}_{platform}`**（type は `dry_run`／`import`／`dry_run_export`／`export`）にrun_id を入れて `cmd+r` する（タブは直前の run_id をここから復元してポーリングする）。確認後はキーを消す。
- **検証中だけ効かせたいフィルター**は、ブラウザからの REST にも効かせる必要があるなら mu-plugin に置く（CLI の検証スクリプト内の `add_filter()` はそのプロセスにしか効かない）。上限（`cbjp/limits/{entity}`）と `cbjp/limits/pro_url` はテンプレートが `cbjp_verify_seed.limits`/`pro_url` で差し替えられるので、それ以外のフィルターだけを別の一時 mu-plugin に書いて `mu-plugins/` に置き、撤去で消す。テンプレートは商品を seed しないので、Export の確認は Woo 側に `ZZV-` の商品を作る。
- **DB を直接変えた後の目視は必ず `cmd+r`**。同じ URL への `navigate` は再読込にならず（performance entries も引き継がれる）、マウント時に1回だけ取得するコンポーネントは古い応答のまま残る。今回は「データが消えた」と誤診して長時間の調査になった。
- **dev サイトには配送ゾーンが無く、Woo 側の配送候補（`MappingCandidates::shipping_methods()`）が 0 件**（R3-0m）。配送マッピングの解消まで確かめるなら、名前に `ZZV` を含む一時ゾーンに `flat_rate` を足し（`WC_Shipping_Zone` → `add_shipping_method()`）、撤去時にゾーン名を確かめてから `delete()` する。候補 0 件の表示（「先に WooCommerce 側で設定を」）を見たいならゾーンを作る前に確認する
- **`npx wp-env run cli wp eval '<複数行の PHP>' | tail -N` は結果行が切れる**（wp-env が実行コマンドの全文を前後に出すため、`tail` が結果ではなくコマンドの echo だけを拾う）。`tail` を付けないか、`echo "RESULT: …"` の目印行を出して `grep` する。

- **`http://localhost:<port>/wp-admin/...` が別ホスト（例: `*.wp.local`）のログイン画面へ飛ぶときは、wp-env ではなく別のローカル環境が応答している**
  （R3-0j で実際に発生: WordPress Studio の別サイトが `[::1]:8895`〔IPv6〕を掴み、Chrome の `localhost` がそちらへ解決された。wp-env は IPv4/`*` 側なので
  `curl http://127.0.0.1:<port>/wp-login.php` は 200、`lsof -nP -iTCP:<port> -sTCP:LISTEN` で別プロセスが見える）。相手のサイトを止める（2026-10-01 に wp-env のポートを
  Studio の帯域 8881〜8999 の外〔10010〕へ移したので、通常は起きない。dev-env スキルの `ports.js check` で確認できる）。**ログイン画面が出ても、その別サイトには何も入力しない**
- **JobManager 経由で「変更なしなら再送しない（checksum）」を確認するときは、専用の商品を作る**。開発サイトの既存商品はカテゴリ未マッピング等の
  未解決参照を持ち、`fully_resolved` が偽で checksum が保存されない（毎回 `updated` になる）ため、`totals` の skipped/updated では見分けられない。
  専用商品（`ZZV-` の SKU・既定カテゴリを mock の `category_map` に載せる）を作り、その mapping の `checksum` と `synced_at`（再送されれば進む。1 秒単位なので run の間に
  `sleep(1)`）を比べる。`add_filter( 'cbjp/limits/product', '__return_null' )` で無料版のサンプル選定を外すと専用商品が確実に対象になる。
- **UI 確認のために mock を実 platform のキー（`colorme`）で登録したときは、UI で run を始めない**（mapping・上限が実 `colorme` と共有になる）。表示と設定の保存だけを見て、
  書いたオプション（例: `cbjp_export_options_colorme`）と `cbjp_verify_seed` のキーを撤去する（`cbjp_verify_seed` は共有オプションなので自分のキーだけを外す）。
  実 platform の cleanup スクリプトは「OAuth トークンがある platform を拒否」するので使えない。専用の撤去スクリプトで自分が書いたキーだけを消す。

## してはいけないこと

- **実アダプタ（`ColorMeAdapter` 等）を偽のキーで登録して `JobManager`/`Exporter` を通さない**: mapping・サンプルキャッシュのキーは登録キーではなく
  `$adapter->id()` から決まる（`ColorMeAdapter::id()` は固定で `final`）ため、`colorme` の実 mapping を書き換えうる。実アダプタを試すなら
  `Woo\Reader\*` を直接呼び、`push_*()` を `pre_http_request` のモックで直接呼ぶ（`Sync\Exporter` と mapping 永続化を通さない）。
- 実 platform の既存行を消す/上書きする。撤去は自分が投入した ID・`ZZV-` の remote_id だけを対象にする。
- WooCommerce の HPOS 設定を切り替えない（同期外れの受注があると WC が拒否する。開発サイトの共有設定を変えない）。
- 他のセッション由来の mu-plugin（例: `cbjp-e2-3-customer`）を消さない。

## ファイル

- `scripts/mock-adapter.sh` — `inspect` / `install <key>` / `run <php>` / `uninstall`（wp-env のパスは `npx wp-env install-path`）
- `templates/mu-plugin-mock-adapter.php` — mock アダプタを登録する mu-plugin（キーと seed オプション名を置換して使う）
- `examples/prefecture-repair/` — 県コード修復ツール（issue #46）の検証一式（`seed.php` / `verify-rest.php` / `cleanup.php`）
- `examples/push-intent-resolution/` — export の作成結果が不明（5xx）→ 印（push intent）が残る → 再 export はブロック → `not_created` 解除 → 再 export で作成、
  と `link` の失敗系（404）を `rest_do_request()` で通す検証（issue #73 / D21-B。R3-1 の「R3-0a/b の確認をモックで」にも使う）。`verify-rest.php`
  は各ステップを PASS/FAIL で出し、失敗があれば非ゼロで終了する（seed は不要で、`cbjp_verify_seed.push` は自分で切り替える）。**前提**: `install mockv` 済み・
  Woo に export できる商品が 1 件以上・前回の残りが無い（あれば先頭で中止するので `cleanup.php` を流す）。どの商品がサンプルに選ばれるかは
  `ExportSampleSelector` 次第。`cleanup.php` は `mockv` の intent・mapping・job・ログ（resolve の操作ログは context の platform で特定）と、この example が書く
  オプションだけを消す（`cbjp_verify_seed` は `push` キーだけを外し、prefecture-repair と共有する他のキーと `cbjp_verify_ids` は残す）。**mock アダプタが登録されているときだけ実行する**
  （OAuth トークンを持つ・mock 以外のアダプタが登録されている・アダプタが未登録〔uninstall 後など〕の platform は拒否。uninstall 後の掃除は `install` し直してから）。終わりに `left:` で 0 件を確認する（`inspect` は logs・jobs・intents を数えない）。
  `link` の成功系統（実在確認・別実体で使用中の remote_id の 409）は、mock が商品を保持しないため対象外（単体テストが担当）
- `examples/upsell-notice/` — 無料版の Pro 案内（`LimitsUpsellNotice`。issue #55 / R3-0h）の元になる値を `rest_do_request()` で確かめる検証。`verify-rest.php` は
  Woo に `ZZV-UPSELL-*` の商品 4 件（うち 1 件は価格なし＝export で止まる）を作り、`cbjp_verify_seed.limits.product=2` で dry-run と export を走らせて、
  totals の `unchanged`・作った商品ごとの dry-run の明細（`VALID-*` は created、`NOPRICE` は skipped＋`product_price_invalid`。集計だけだと dev サイトの
  既存商品が結果を隠すため）・上限で「未移行」が残ること（export のサンプル `cbjp_export_sample_mockv` を作った 4 件に固定する。受注が 10 件以上ある
  dev サイトでは受注の商品だけでサンプルが決まるため）・`/limits` の `pro_url`（既定は空、有効な URL はそのまま、
  不正な値は空）を PASS/FAIL で出す。最後に偽トークンで `mockv` を connected にし、Export タブで通知を目視する手順（localStorage に入れる run_id と、
  `pro_url` を切り替える `wp eval`）を出す。**前提**: `install mockv` 済み・前回の残りが無い（トークン・商品・状態オプションに加え、seed の `push`/`limits`/`pro_url` と
  mockv のサンプル・レート制限のオプションも数え、あれば先頭で中止。push-intent-resolution も `push` を使うため）。
  `cleanup.php` は偽トークン（値がこの example のものと一致するときだけ。復号できないトークンがあれば拒否）・作った商品（記録した ID と、SKU の接頭辞で見つかる取り残し。
  `wc_get_products()` の `sku` は部分一致なので接頭辞は自分で確かめる）・`mockv` の行・この example が書く
  seed のキー（`push`/`limits`/`pro_url`）とオプションだけを消し、`left:` で 0 件を確認する

関連: `docs/03-design-decisions.md` §10.3（ツール）、`.claude/skills/cbj-dev-cycle/SKILL.md`（開発サイクル Step 2 の実機確認）。
