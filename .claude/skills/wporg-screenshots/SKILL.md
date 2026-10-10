---
name: wporg-screenshots
description: >
  wordpress.org の readme に載せるスクリーンショット（`plugins/cart-bridge-jp/.wordpress-org/screenshot-N.png`）を、wp-env の tests サイトで撮り直す手順。
  Color Me Shop API への通信を一時 mu-plugin で匿名化済みフィクスチャへ向け、偽のトークンで接続した状態・マッピング・dry-run の結果を
  用意して、Playwright（ヘッドレスの Chrome）で管理画面の各タブを撮り、最後に撮影の run・偽のトークン・mu-plugin・ログイン Cookie を必ず片付ける。
  「スクリーンショットを撮り直して」「readme の画像を更新して」「管理画面の見た目が変わったので wordpress.org の画像を直して」などと
  言われたら使う。画面の文言・タブ・無料版の範囲を変える PR（R3-6 など）と、wordpress.org への申請前（R3-4）が主な用途。
---

# /wporg-screenshots — wordpress.org 用スクリーンショットの撮影

R3-3（PR #111）で readme のスクリーンショット 5 枚を撮った手順をスクリプトにしたもの。**実店舗・テストショップには接続せず、dev サイト（10010）にも触れない**。
画面はすべて tests サイト（10011）と `plugins/cart-bridge-jp/tests/fixtures/colorme/` の匿名化済みデータから作る。

## いつ使うか / 使わないか

- 使う: 管理画面の文言・タブ・項目を変えて、readme の `== Screenshots ==` の画面と食い違ったとき。無料版と Pro の境目を変えたとき（R3-6）。申請前の撮り直し（R3-4）。
- 使わない: 実店舗のデータを写したいとき（写してはいけない）。バナー・アイコンの作成（別の作業）。

## 前提

- wp-env が起動している（`npx wp-env start`。tests サイトは `.wp-env.json` の `testsPort`＝10011）。
- `npm install` 済み。Playwright（1.61）は `@wordpress/scripts` 経由で `node_modules` にある（新しい依存は足さない）。
- Google Chrome がインストールされている。無ければ `npx playwright install chromium` で同梱の Chromium を入れ、`CBJP_SHOTS_CHANNEL=`（空）を付けて実行する。
- readme の `== Screenshots ==` のキャプションの数が `shots.json` の `shots` と同じ（違うと `capture.sh` は撮らずに止まる）。

## 手順

`S=.claude/skills/wporg-screenshots`（リポジトリルートから実行）。

1. **試し撮り**（出力先をスクラッチパッドにして、リポジトリの画像はまだ変えない）:
   `$S/scripts/capture.sh shoot --out <スクラッチパッド>/shots`
   1〜2 分かかる。最後に `screenshot-N.png` とキャプションの対応を表示する。
2. **目視で確かめる**（Read で各画像を開く）: キャプションどおりの画面か／エラーの通知が無いか／英語の UI か／
   写っている名前がフィクスチャのものか（`Example Store`・`localhost:10011`・フィクスチャの日本語のカテゴリ名。実店舗の名前・ドメイン・件数が無いこと）／
   無料版だけの画面か（顧客・受注・クーポンの選択肢や、決済・配送・注文ステータスのマッピングの節が無いこと。Pro の画面が写っていない）。
3. **本番**: `$S/scripts/capture.sh shoot`（`plugins/cart-bridge-jp/.wordpress-org/` を上書きする）。
4. `git diff --stat plugins/cart-bridge-jp/.wordpress-org/` で変わった画像を見る。描画は決定的で、画面が変わっていなければバイト単位で同じ画像になる。
   変えていない画面に差分が出たら、WooCommerce のメニューのバッジ（`Payments 1` など、WooCommerce 側の状態で出たり消えたりする）の揺れのことが多い。
   中身が同じなら `git checkout -- plugins/cart-bridge-jp/.wordpress-org/screenshot-N.png` で戻し、意図した画面の差分だけをコミットする。
5. `ReadmeTest`（キャプションと画像の連番の一致）を走らせる:
   `composer test:wpenv:free -- --filter ReadmeTest`

`capture.sh` は終わるとき（失敗・Ctrl-C を含む）に、mu-plugin（API のモック）がまだある間に `php/teardown.php` で撮影の run と偽のトークンを片付けてから、
mu-plugin とログイン Cookie を消す。モックを先に消すと、残った run のアクションや偽のトークンで管理画面・WP-Cron が動いたとき実 API に通信が出うるため。
後片付けに失敗したら mu-plugin を残して終了コード 1 になる（原因を直して `cleanup`）。強制終了（SIGKILL）した後は
`$S/scripts/capture.sh status` で確かめ、`$S/scripts/capture.sh cleanup` で同じ順に片付ける（mu-plugin が無くても run とトークンは片付ける）。

tests サイトと PHPUnit は同じ DB・同じ接頭辞を使う。PHPUnit が起動時に戻すのはコアのテーブル（オプション・投稿・ターム・ユーザー）だけで、
撮影で作ったプラグインと WooCommerce の独自テーブルの行（dry-run のジョブ・明細・ログ・Action Scheduler）は残る。
完了した run は PHPUnit に影響しない（品質チェックが通ることを確認済み）。開いたままの run は次の撮影の最初にキャンセルする。

## 仕組み

| ファイル | 役割 |
|---|---|
| `scripts/capture.sh` | 前提の確認（キャプションの数・Playwright）→ `npm run build` → tests サイトの応答の確認 → mu-plugin を tests サイトへ置く → Pro アドオン（`cart-bridge-jp-pro`）を無効にし、WooCommerce と本プラグインを有効化・パーマリンクを `/%postname%/` に → `php/setup.php` → `scripts/shoot.cjs` で一時ディレクトリへ撮り、全部撮れたら出力先へ写す（余った番号の画像があれば警告）→ `php/teardown.php` → mu-plugin と Cookie を消す（後片付けに失敗したら mu-plugin を残し、消せなければ終了コード 1）。最後まで走ったことは `DONE` で確かめ、途中で止まれば終了コード 1 |
| `templates/mu-plugin-screenshot-fixtures.php` | `pre_http_request` で `api.shop-pro.jp` への GET を、URL のファイル名と同じフィクスチャで返す（一覧の 2 ページ目以降は空、GET 以外はエラー）。撮影向けに、グループを表示中で `Sale` に・ショップをプレミアムプランにする（Export タブにベータの機能を出す）。Action Scheduler の過去の予定の通知を止める。PHPCS の対象（`composer lint`） |
| `php/setup.php` | 前提を肯定形で確かめる（home_url が tests サイト／mu-plugin が読み込まれている／`colorme` が実 `ColorMeAdapter`／Pro が読み込まれておらず、登録された実体の種類が無料版のものだけ／`colorme` のトークンが無いか撮影用の偽物。別のトークンなら何も変えずに止まる）。サイト名・通貨・国・ストアの公開・ユーザー 1 の言語（英語）、偽のトークン（`TokenStore`）を用意し、Mappings の対応（無料版はカテゴリだけ）を REST の候補一覧から作って保存する（フィクスチャの ID を書き写さない）。開いたままの `colorme` の run をキャンセルしてから dry-run を始め、ジョブをその場で処理する（claim はフック名だけで取り、job_id で自分の run か見分ける。paused の再開は最大 5 分待つ）。全エンティティの完了を確かめてから `RUN_ID=` と `COOKIES=`（1 時間で切れる管理者のログイン Cookie）を出す。途中で止まるときは自分の run をキャンセルする |
| `php/teardown.php` | home_url が tests サイトであることと、`colorme` のトークンが無いか撮影用の偽物（`screenshot-dummy-token`）であることを確かめてから（別のトークン・復号できない値なら何も変えずに失敗）、開いたままの `colorme` の run をキャンセルし、偽のトークンを消す。プラグインか WooCommerce が無効なら片付けられないので、トークンか開いた `colorme` のジョブが残っていれば失敗する（有効にしてから `cleanup`）。何も残っていなければ何もしない（PHPUnit の後など） |
| `scripts/shoot.cjs` | Cookie でログインし、Import タブが開く run を localStorage（`cbjp_run_dry_run_colorme`）に入れてから、`shots.json` の順に各タブを幅 1280 の全体で撮る。タブごとに `wait_for` の文言が出るまで待ち（30 秒）、`uncheck` のチェックを外し、エラーの通知があれば撮らずに失敗する。フォーカスの枠とホバーは写さない |
| `shots.json` | `dry_run_entities`（dry-run するエンティティ）と `shots`（`tab`・`wait_for`・任意の `uncheck`）。`shots[i]` が `screenshot-(i+1).png` |

## 画面を変える・増やすとき

- **`shots` の順番と数を readme の `== Screenshots ==` に合わせる**。キャプションを先に直す（`capture.sh` が数を照合し、`ReadmeTest` が連番を照合する）。
  枚数を減らしたら、余った番号の画像（`capture.sh` が警告する）を `git rm` で消す。
- `wait_for` は、そのタブが描画し終わったことを示す英語の文言（ボタン・見出し）。UI の文言を変えたら合わせる。
- Import タブの `uncheck` は、チェックの状態を `dry_run_entities` と同じにするためのもの（結果の節とチェックが食い違う画像になる。R3-3 の R1-L12）。
  在庫は dry-run に含めない（dry-run は商品を書かないので、在庫は全件が「商品がまだ無い」でスキップと写り、誤解を招く。R3-6d の試し撮りで確認）。
- **readme の画面は無料版だけ**（R3-6d）。`capture.sh` は Pro アドオンを無効にしてから撮り、`setup.php` は Pro が読み込まれていないこと・登録された実体の種類が
  無料版のもの（`category`・`tag`・`product`・`stock`・`review`）だけであることを確かめる。顧客・受注・クーポンの種類と、決済・配送・注文ステータスのマッピングの節は Pro のもの
  （そのフィクスチャは `plugins/cart-bridge-jp-pro/tests/fixtures/colorme/` にあり、mu-plugin は読まない）。tests サイトの Pro は撮影の後も無効のまま（PHPUnit の後は全部無効なので戻さない）。
- 撮影向けの差し替え（フィクスチャの値を変える）は mu-plugin のテンプレートに足す。mu-plugin は致命的エラーを出さない書き方にする（`.claude/rules/skill-scripts.md`）。
- 新しい API を呼ぶ画面を撮るなら、そのレスポンスのフィクスチャ（匿名化済み。`plugins/cart-bridge-jp/tests/fixtures/README.md`）が要る。無いと mu-plugin がエラーを返し、画面にエラーの通知が出て撮影が止まる。

## してはいけないこと

- dev サイト（10010）で撮らない。実 `colorme` の接続・マッピング・取込み済みのデータがある（`setup.php` は home_url が違えば止まる）。
- mu-plugin を外して撮らない。実 API へ出ていく（`setup.php` は mu-plugin が読み込まれていなければ止まる）。
- mu-plugin を残したまま PHPUnit を走らせない（tests サイトの mu-plugin は PHPUnit にも読み込まれ、HTTP のモックを横取りする）。
- mu-plugin を `rm` で手で消さない。`cleanup` を使う（run と偽のトークンを先に片付ける）。
- ログイン Cookie をリポジトリに置かない（`capture.sh` は一時ディレクトリに書き、終了時に消す）。

## うまくいかないとき

- `waitFor` のタイムアウト: `wait_for` の文言が UI と違う。画面が開けていない（Cookie が通らない・PHP のエラー）こともある。失敗した回の画像は出力先に写さないので、tests サイトに管理者でログインして同じタブを開いて確かめる。
- `the … tab shows an error notice`: そのタブの API 呼び出しが失敗している。フィクスチャの無い API を呼んでいないか、`npx wp-env run tests-cli wp db query "SELECT level, message FROM wp_cbjp_logs ORDER BY id DESC LIMIT 10"` を見る。
- `setup: … would be empty`: フィクスチャの決済・配送・カテゴリ・状態の候補が変わった。`setup.php` の対応の作り方を合わせる。
- `the dry run did not complete for every entity: …`: 止まらなかったエンティティと状態（`product=failed` など）と、止まった理由（ジョブの失敗・5 分の期限・アクションを失ったジョブ）を出す。直前に出るエンティティごとの状態と `wp_cbjp_logs` を見る。run はキャンセル済みなので、原因を直してそのまま撮り直せる。
- `no job was created for: …`: `dry_run_entities` に、アダプタが受け付けないエンティティがある。
- `a colorme token other than the screenshot dummy …`（setup・teardown）: tests サイトに撮影用でないトークンが保存されている。誰かが tests サイトを店舗につないだので、撮影も後片付けも何も変えずに止まる（後片付けが止まると mu-plugin は残る）。そのトークンをどうするか決めてから撮り直す・`cleanup` を実行する。
- `the plugin or WooCommerce is inactive … remain`: プラグインが無効のまま、撮影のトークンか開いた run が残っている。mu-plugin が残っている間に tests サイトで両方を有効にしてから（`npx wp-env run tests-cli wp plugin activate woocommerce cart-bridge-jp`）`cleanup` を実行する。
- `teardown failed; keeping the mu-plugin`: 後片付けが失敗した。原因を直して `cleanup` を実行する。
- `Unsupported chromium channel` / Chrome が無い: `npx playwright install chromium` の後に `CBJP_SHOTS_CHANNEL= $S/scripts/capture.sh shoot`。
