---
name: verify-with-mock-adapter
description: >
  ライブの OAuth 接続なしで、wp-env の開発サイト上で Cart Bridge JP の REST・管理画面（React）を検証する手順。
  mock アダプタを mu-plugin で任意のプラットフォームキー（例: `colorme`）に登録し、修正前のデータ（旧バグの出力・
  一部だけ壊れた状態）を実 Writer で再現して投入 → `rest_do_request()` と管理画面で Scan/Repair/Import/Export を通し →
  投入したものを完全に撤去する。「mock アダプタで確認して」「OAuth なしで実機確認したい」「旧データを再現して是正ツールを
  試したい」「Tools/Export/Import を実際に動かして見たい」「検証用データを片付けて」などと言われたら使う。
  データ是正系のツール、Export/Import の配線、Tools タブの追加を実装した後の実機確認が主な用途。
---

# /verify-with-mock-adapter — OAuth なしの実機確認

実店舗の認証情報が無い（副管理者では ColorMe の再認可が通らない等）状況でも、REST と React UI を実際に動かして確認できるようにする。
**単体テストでは見えない層間のズレ**（Transformer → Canonical → Writer → ツールの入力の食い違い、UI の状態遷移、ブラウザ固有の問題）
を見つけるのが目的で、単体テストの代わりではない（`PushIntentResolverTest` のような結合テストも併せて書く）。

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
   （例: `colorme`。`AddressMapper` のように `colorme` だけを解釈するコードを通すときは、そのキーで登録する必要がある）。
   `templates/mu-plugin-mock-adapter.php` を wp-env の `mu-plugins/` へ置く。mock は `plugins/cart-bridge-jp/tests/unit/Fixtures/MockPlatformAdapter`
   （無料版の composer の autoload-dev。ルートで `composer install` すれば無料版の autoload も作られ、dev サイトから使える）で、オプション `cbjp_verify_seed` から
   顧客・受注を組み立てる。このオプションは **PHP 配列を `update_option()` で保存する**（JSON 文字列ではない。mu-plugin は
   `get_option()` の戻り値を配列として読む。配列でない値・配列でない行は読み飛ばす）。
   `cbjp_verify_seed.push`（`{ enabled: bool, create_failure: 'ambiguous_5xx'|'partial_push'|'partial_rate_limit'|null }`）は mock の `push_*()` を有効にし（既定は全て
   `UnsupportedOperationException`）、`create_failure` は作成 POST が 5xx（結果不明）になる経路（D21-B）か、作成は確定したが後続で止まった経路
   （`PartialPushException`。D21-A。`partial_rate_limit` はレート制限でジョブを一時停止させる）を再現する。後の 2 つは remote_id が固定なので、1 回の export で
   作成経路を通る商品を 1 件に絞る（`examples/partial-push/`）。
   Export タブの Beta 表示・既定オフ・能力による項目の出し分け（D24）を見るときは、`cbjp_verify_seed.capabilities`
   （`{ can_create_order, can_push_images, beta_features }`。省略したキーは mock の既定）で mock の `capabilities()` を上書きできる
   （プレミアム相当は `can_*=true`＋`beta_features=['order_export','image_push']`、非プレミアム相当は `can_*=false`）。
   Mappings タブと Import タブの事前チェック（R3-0m）を見るときは、`cbjp_verify_seed.mapping_candidates`（`{ payment: [{id,name}], shipping: [...] }`。
   mock の `mapping_candidates()` がそのまま返す）と、受注ごとの `payment_method_id`/`payment_method_name`/`shipping_method_id`/`shipping_method_name`
   （canonical の `payment`/`shipping` の `method_id`/`method_name`）を入れる。マッピングの設定（`cbjp_settings_{platform}`）は Mappings タブ
   （`#/mappings`）か `PUT /settings/mappings/{platform}` で行い、撤去時はそのオプションも消す。
   **クラス定義を mu-plugin のトップレベルに書かない**（mu-plugins は通常プラグインより先に読み込まれ、
   autoloader がまだ無い）。`plugins_loaded` のコールバック内で `new` する。
   mock の `id()` は登録キーと同じ値を返す（`platform_id`）。`Importer`/`Exporter`/`JobManager` は mapping のキーを
   登録キーではなく `$adapter->id()` から決めるため。したがって `colorme` で登録して Import/Export を `JobManager` 経由で回すと、
   実 `colorme` の mapping を共有する。Import/Export の配線だけを見たいなら、実 platform と
   衝突しないキー（例: `mockv`）で登録する。`colorme` で回すなら `ZZV-` の remote_id と手順 6 の撤去を必ず守る。
3. **修正前のデータを再現して投入する**（実 Writer を使う）: `$S/scripts/mock-adapter.sh run <seed.php>`。
   seed は `WooRepositoryFactory::for_platform()->write()` / `OrderWriter` で実際に取り込み → 旧コードの出力へ書き戻す（例:
   県コードは `JP{pref_id}` の恒等変換）。投入した ID を `cbjp_verify_ids` オプションに記録し、`cbjp_verify_seed` に mock へ渡す
   データを保存する（R3-6a で削除した県コード修復の検証 `examples/prefecture-repair/seed.php` が実例。git の履歴にある）。投入する実体の
   `remote_id` は `ZZV-` 接頭辞にする（撤去時の目印）。
4. **REST で検証する**: `$S/scripts/mock-adapter.sh run <verify.php>`。`wp_set_current_user(1)` のうえ `rest_do_request()`。
   **クエリ文字列をルートに含めず `set_query_params()` を使う**（含めると `rest_no_route`）。
   読取専用の要求が書かないこと・実行後に期待値になること・再実行で変更 0（冪等）を確認する。実例: `examples/push-intent-resolution/verify-rest.php`。
5. **管理画面で確認する**（React の変更があるとき）: `npm run build` → Chrome で `http://localhost:<port>/wp-admin/admin.php?page=cart-bridge-jp#/tools`。
   - **ログインはユーザーに依頼する**（アシスタントはパスワードを入力できない）。
   - **ビルドし直したら cmd+r でリロードする**。ハッシュだけが違う URL への `navigate` は SPA を再読込せず、古いバンドルが残る
     （文言が古いまま、と気付いたらこれ）。
   - ネイティブの `window.confirm()` を出すボタン（Import タブの Run import 等）をブラウザ自動操作で押さない（タブがフリーズする）。
   - 確認後にコンソールエラーを見る（`read_console_messages` の `onlyErrors`）。
6. **完全に撤去する**（必須）: `$S/scripts/mock-adapter.sh run <cleanup.php>`（投入したユーザー・受注・mapping・オプションを削除。
   `examples/push-intent-resolution/cleanup.php`）→ `$S/scripts/mock-adapter.sh uninstall`（mu-plugin を削除）→ `inspect` で
   手順 1 の状態に戻ったことを確認 → リポジトリ内の一時ファイルを削除。**一時ファイルはコミットしない**（`git status` で確認）。

## 落とし穴（PR #80 の Export タブ UI 検証で実際に踏んだもの）

- **`mock-adapter.sh run` に渡す PHP はリポジトリ内に置き、リポジトリのルートからの相対パスで渡す**（`wp eval-file` はコンテナ内の `wp-content/cbjp-dev`〔ルートのマウント〕で実行されるため、`/tmp` やスクラッチパッドはコンテナから見えない）。コミット前に削除する。
- **`AdapterRegistry::all()` は静的キャッシュ**。同一 PHP プロセス内で `cbjp_verify_seed` を書き換えて再検証するなら、書き換えの直後に `CartBridgeJP\Adapters\AdapterRegistry::reset_cache()` を呼ぶ（呼ばないと最初に組み立てた mock がそのまま使われ、seed の変更が効かない）。
- **`cbjp_process_job`（Action Scheduler）は CLI では自走しない**。`start_run` の後、検証スクリプト内で pending を処理する（`as_get_scheduled_actions( [ 'hook' => 'cbjp_process_job', 'status' => 'pending' ] )` → `do_action_ref_array( $action->get_hook(), $action->get_args() )` → `ActionScheduler_Store::instance()->mark_complete()`）。
- **mock のキー（例 `mockv`）は既定では `connected` ではないので、Export/Import タブの platform 選択に出ない**（`ExportTab` は `c.connected` の platform だけを扱う）。タブに出すには、検証スクリプトで偽のトークンを保存して connected にする: `( new CartBridgeJP\Support\TokenStore( 'mockv' ) )->save( [ 'access_token' => 'verify-<作業名>' ] )`（実 `colorme` を汚さない。R3-0h）。撤去時は値を確かめてから `cbjp_token_mockv` を消す（`push-intent-resolution/cleanup.php` はトークンが残っていると拒否する）。`push` 系の挙動は `cbjp_verify_seed.push`（`enabled`/`create_failure`）で切り替える。
- **REST（`rest_do_request()`）で走らせた run を管理画面に出すには**: 進行中（未終了のジョブがある）run は、各タブが `GET /runs?platform=` で見つけて自動で取り込む・案内する（R3-0i）ので何もしなくてよい。**終了済みの run** を出すときだけ、localStorage の `cbjp_run_{type}_{platform}`（type は `dry_run`／`import`／`dry_run_export`／`export`）に run_id を入れて `cmd+r` する（タブは直前の run_id をここから復元してポーリングする）。確認後はキーを消す。
- **「見失った run」（run_id がブラウザに届かなかった run）を再現するには**、`JobRepository::create()` でジョブを直接作り、`update_status()`/`mark_failed()` で running・paused・失敗で停止（先頭 failed＋兄弟 pending）にする。Action Scheduler の action を作らないので run は動かず、画面を開いても進まない（`JobManager::start_run()` だと管理画面を開いた時点で async runner が処理してしまう）。撤去は `cbjp_jobs` の該当 platform の行を消す（R3-0i）。
- **検証中だけ効かせたいフィルター**は、ブラウザからの REST にも効かせる必要があるなら mu-plugin に置く（CLI の検証スクリプト内の `add_filter()` はそのプロセスにしか効かない）。別の一時 mu-plugin に書いて `mu-plugins/` に置き、撤去で消す。テンプレートは商品を seed しないので、Export の確認は Woo 側に `ZZV-` の商品を作る。
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
  `sleep(1)`）を比べる。
- **UI 確認のために mock を実 platform のキー（`colorme`）で登録したときは、UI で run を始めない**（mapping が実 `colorme` と共有になる）。表示と設定の保存だけを見て、
  書いたオプション（例: `cbjp_export_options_colorme`）と `cbjp_verify_seed` のキーを撤去する（`cbjp_verify_seed` は共有オプションなので自分のキーだけを外す）。
  実 platform の cleanup スクリプトは「OAuth トークンがある platform を拒否」するので使えない。専用の撤去スクリプトで自分が書いたキーだけを消す。

- **Claude in Chrome で `CheckboxControl` を座標・ref でクリックしても切り替わらないことがある**（R3-6b2）。状態は `javascript_tool` で `input.checked` を読み、操作は `element.click()`。
  `SelectControl` は `HTMLSelectElement.prototype` の value の setter で値を入れて `change` を dispatch する（React の onChange が走る）
- **画面の警告・失敗の表示を確かめるには、一時的な mu-plugin で失敗する種類を `cbjp/entity_types/register` に登録する**（例: `scan()` が例外を投げる LinkSource →
  Tools タブの「飛ばした種類」の警告。R3-6b2）。確認後に mu-plugin を消す

## してはいけないこと

- **実アダプタ（`ColorMeAdapter` 等）を偽のキーで登録して `JobManager`/`Exporter` を通さない**: mapping のキーは登録キーではなく
  `$adapter->id()` から決まる（`ColorMeAdapter::id()` は固定で `final`）ため、`colorme` の実 mapping を書き換えうる。実アダプタを試すなら
  `Woo\Reader\*` を直接呼び、`push_*()` を `pre_http_request` のモックで直接呼ぶ（`Sync\Exporter` と mapping 永続化を通さない）。
- 実 platform の既存行を消す/上書きする。撤去は自分が投入した ID・`ZZV-` の remote_id だけを対象にする。
- WooCommerce の HPOS 設定を切り替えない（同期外れの受注があると WC が拒否する。開発サイトの共有設定を変えない）。
- 他のセッション由来の mu-plugin（例: `cbjp-e2-3-customer`）を消さない。

## ファイル

- `scripts/mock-adapter.sh` — `inspect` / `install <key>` / `run <php>` / `uninstall`（wp-env のパスは `npx wp-env install-path`）
- `templates/mu-plugin-mock-adapter.php` — mock アダプタを登録する mu-plugin（キーと seed オプション名を置換して使う）
- `examples/push-intent-resolution/` — export の作成結果が不明（5xx）→ 印（push intent）が残る → 再 export はブロック → `not_created` 解除 → 再 export で作成、
  と `link` の失敗系（404）を `rest_do_request()` で通す検証（issue #73 / D21-B。R3-1 の「R3-0a/b の確認をモックで」にも使う）。`verify-rest.php`
  は各ステップを PASS/FAIL で出し、失敗があれば非ゼロで終了する（seed は不要で、`cbjp_verify_seed.push` は自分で切り替える）。**前提**: `install mockv` 済み・
  Woo に export できる商品が 1 件以上・前回の残りが無い（あれば先頭で中止するので `cleanup.php` を流す）。export できる商品はすべて対象になる
  （作成が 5xx になるので、それぞれに印が残る）。`cleanup.php` は `mockv` の intent・mapping・job・ログ（resolve の操作ログは context の platform で特定）と、この example が書く
  オプションだけを消す（`cbjp_verify_seed` は `push` キーだけを外し、他の example と共有するキーと `cbjp_verify_ids` は残す）。**mock アダプタが登録されているときだけ実行する**
  （OAuth トークンを持つ・mock 以外のアダプタが登録されている・アダプタが未登録〔uninstall 後など〕の platform は拒否。uninstall 後の掃除は `install` し直してから）。終わりに `left:` で 0 件を確認する（`inspect` は logs・jobs・intents を数えない）。
  `link` の成功系統（実在確認・別実体で使用中の remote_id の 409）は、mock が商品を保持しないため対象外（単体テストが担当）
- `examples/partial-push/` — export の作成は確定したが後続の処理で止まった（`PartialPushException`。R3-0a / D21-A）経路の検証（R3-1 で追加）。`verify-rest.php` は
  作成経路を 1 商品に絞って（ほかの export できる商品を先に `ZZV-OTHER-*` の remote_id で結んで更新経路に回す。R3-6a で無料版のサンプルを外したため）`create_failure` を `partial_push`（後続が 5xx）・`partial_rate_limit`（後続がレート制限 → 一時停止 → 再開）に切り替え、作成済みの
  remote_id で mapping が書かれ印は残らないこと、次の export（再開を含む）が作成ではなく更新になることを確かめる。checksum は見ない（mock にカテゴリ対応が無く、
  どの商品も `category_map_unresolved` で null のまま）。**前提**: `install mockv` 済み・価格のある公開の単純商品が 2 件以上・前回の残りが無い。
  掃除は `push-intent-resolution/cleanup.php`（同じキーの intent・mapping〔`ZZV-OTHER-*` を含む〕・job・ログを消す）。一時停止のログは job_id を context にだけ持つ（ログの `job_id` 列は空）ので、文脈で探している
