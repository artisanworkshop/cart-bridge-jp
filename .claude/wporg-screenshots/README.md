# wordpress.org 用スクリーンショットの設定（Cart Bridge JP）

無料版の readme の `== Screenshots ==`（`plugins/cart-bridge-jp/.wordpress-org/screenshot-N.png`）を撮り直す設定。
撮影そのものは共有スキル `/wporg-screenshots`（`~/Dev/claude-skills/skills/wporg-screenshots/`。`install.sh` で `~/.claude/skills/` に配置）が行い、
このディレクトリにはこのプラグインだけの部分を置く。R3-3（PR #111）で撮った手順を PR #112 でプロジェクトスキルにし、汎用の部分を共有スキルへ移した。

```bash
C=~/.claude/skills/wporg-screenshots/scripts/capture.sh   # リポジトリのルートから実行する
$C shoot --out <スクラッチパッド>/shots   # 試し撮り（リポジトリの画像は変えない）
$C shoot                                 # 本番（plugins/cart-bridge-jp/.wordpress-org/ を上書き）
$C status / $C cleanup                   # 強制終了した後の確認と片付け
composer test:wpenv:free -- --filter ReadmeTest   # キャプションと画像の連番
```

**実店舗・テストショップには接続せず、dev サイト（10010）にも触れない**。画面はすべて tests サイト（10011）と
`plugins/cart-bridge-jp/tests/fixtures/colorme/` の匿名化済みデータから作る。

## このディレクトリ

| ファイル | 役割 |
|---|---|
| `shots.json` | 共有スキルの設定。build（`npm run build`）、Pro を無効にして WooCommerce と無料版を有効にすること、パーマリンク、`setup_args`（dry-run するエンティティ）、Import タブが開く run の localStorage（`cbjp_run_dry_run_colorme` = setup が出す `{RUN_ID}`）、5 つのタブ（`shots[i]` が `screenshot-(i+1).png`） |
| `mu-plugin.php` | `pre_http_request` で `api.shop-pro.jp` への GET を、URL のファイル名と同じフィクスチャで返す（一覧の 2 ページ目以降は空、GET 以外とフィクスチャの無い取得はエラー）。撮影向けに、グループを表示中で `Sale` に・ショップをプレミアムプランにする（Export タブにベータの機能を出す）。Action Scheduler の過去の予定の通知を止める。PHPCS の対象（`composer lint`） |
| `setup.php`・`teardown.php` | フック。処理は `lib.php` の `cbjp_shot_setup()`・`cbjp_shot_teardown()`。capture.sh が tests サイトの mu-plugins の下へ写すのはフックだけなので、`lib.php` は wp-env がルートをマウントした `wp-content/cbjp-dev` から読む |
| `lib.php` | 準備と片付けの処理（下の「準備と片付け」）。Pro のマニュアル用の撮影（`.claude/pro-manual-screenshots/shared/lib.php`）と同じ書き方 |

## 準備と片付け

- **setup**: 前提を肯定形で確かめる（home_url が tests サイト／mu-plugin が読み込まれている／WooCommerce と無料版が有効で `colorme` が実 `ColorMeAdapter`／Pro が読み込まれておらず、登録された実体の種類が無料版のものだけ／`colorme` のトークンが無いか撮影用の偽物／撮影が始めていない `colorme` の run が開いていない）。どれかが欠ければ何も変えずに止まる。
  サイト名・通貨・国・ストアの公開、偽のトークン（`TokenStore`）を用意し、Mappings の対応（無料版はカテゴリだけ）を REST の候補一覧から作って保存する（フィクスチャの ID を書き写さない）。
  dry-run を始め、ジョブをその場で処理する（claim はフック名だけで取り、job_id で自分の run か見分ける。paused の再開は最大 5 分待つ）。全エンティティの完了を確かめてから `RUN_ID=` を出す。途中で止まるときは自分の run をキャンセルする。
- **teardown**: トークンが無いか撮影用の偽物（`screenshot-dummy-token`）であることを確かめてから、撮影が始めた run をキャンセルし、作ったカテゴリを消し、偽のトークン（とトークンの更新のロックの行）を消し、書き換えた option を戻す。最後に記録が空になったことを確かめる。
  キャンセルの REST は DB の書き換えの結果を見ずに 200 を返すので、run のジョブが閉じたことを照会し直してから記録から外す。空の値のトークンの行も「無い」と読まず、撮影用でないトークンとして止まる（setup が上書きし、teardown が消すと元の行に戻らない）。

撮影で作ったもの（始めた run・作ったカテゴリ）は option `cbjp_wporg_screenshots` に記録し、teardown は記録にあるものだけを消す・キャンセルする。
書き換える option（サイト名・通貨・国・オンボーディング・ストアの公開、`cbjp_settings_colorme` の対応付け）は、書き換える前の DB の値を記録に控え、teardown がそのまま戻す（無かったものは消す）。消した・戻したことは `get_option()`（このリクエストのキャッシュ）ではなく DB の行で確かめる。
setup・teardown は何かを変える前に記録全体（run・カテゴリの ID、option の控え、知らない項目が無いこと）を確かめ、壊れていれば空と読まずに止まる。option の控えは、書き換える option の名前をちょうど持っていなければ（足りない・ほかの名前がある）止まる。カテゴリは、記録の ID の term が撮影の付けた名前（`Apparel`・`Accessories`）で、WooCommerce の既定のカテゴリーでないときだけ消す（`default_product_cat` はインストールが term_taxonomy_id を、管理画面の「既定にする」が term_id を書くので、どちらとも比べる。照会が失敗したら止まる）。同じ名前のカテゴリが前からあれば、記録せずに使い、消さない。
プラグインか WooCommerce が無効なら片付けられないので、撮影の記録・`colorme` のトークン・開いた `colorme` のジョブのどれかが DB に残っていれば失敗する（有効にしてから `cleanup`）。何も残っていなければ何もしない（PHPUnit の後など）。

2026-10-24 より前の setup は、書き換えた option・対応付け・作ったカテゴリを戻さなかった。tests サイトのサイト名などが `Example Store` のままなのはその名残で、今の teardown は撮影の前の値（その名残を含む）に戻す。

## 目視で確かめること（試し撮りの後）

キャプションどおりの画面か／エラーの通知が無いか／英語の UI か／写っている名前がフィクスチャのものか（`Example Store`・`localhost:10011`・
フィクスチャの日本語のカテゴリ名。実店舗の名前・ドメイン・件数が無いこと）／無料版だけの画面か（顧客・受注・クーポンの選択肢や、決済・配送・
注文ステータスのマッピングの節が無いこと。Pro の画面が写っていない）。

## 画面を変える・増やすとき

- **`shots` の順番と数を readme の `== Screenshots ==` に合わせる**。キャプションを先に直す（`capture.sh` が数を照合し、`ReadmeTest` が連番を照合する）。
- `wait_for` は、そのタブが描画し終わったことを示す英語の文言（ボタン・見出し）。UI の文言を変えたら合わせる。
- Import タブの `uncheck` は、チェックの状態を `setup_args` と同じにするためのもの（結果の節とチェックが食い違う画像になる。R3-3 の R1-L12）。
  在庫は dry-run に含めない（dry-run は商品を書かないので、在庫は全件が「商品がまだ無い」でスキップと写り、誤解を招く。R3-6d の試し撮りで確認）。
- **readme の画面は無料版だけ**（R3-6d）。`shots.json` で Pro を無効にしてから撮り、`setup.php` は Pro が読み込まれていないこと・登録された実体の種類が
  無料版のもの（`category`・`tag`・`product`・`stock`・`review`）だけであることを確かめる。顧客・受注・クーポンの種類と、決済・配送・注文ステータスのマッピングの節は Pro のもの
  （そのフィクスチャは `plugins/cart-bridge-jp-pro/tests/fixtures/colorme/` にあり、mu-plugin は読まない）。tests サイトの Pro は撮影の後も無効のまま（PHPUnit の後は全部無効なので戻さない）。
- 撮影向けの差し替え（フィクスチャの値を変える）は `mu-plugin.php` に足す。mu-plugin は致命的エラーを出さない書き方にする（`.claude/rules/skill-scripts.md`）。
- 新しい API を呼ぶ画面を撮るなら、そのレスポンスのフィクスチャ（匿名化済み。`plugins/cart-bridge-jp/tests/fixtures/README.md`）が要る。無いと mu-plugin がエラーを返し、画面にエラーの通知が出て撮影が止まる。

tests サイトと PHPUnit は同じ DB・同じ接頭辞を使う。PHPUnit が起動時に戻すのはコアのテーブル（オプション・投稿・ターム・ユーザー）だけで、
撮影で作ったプラグインと WooCommerce の独自テーブルの行（dry-run のジョブ・明細・ログ・Action Scheduler）は残る。
完了した run は PHPUnit に影響しない。撮影が始めて開いたまま残った run は、次の撮影と teardown がキャンセルする。
PHPUnit は記録の option も消すので、撮影が途中で止まったまま PHPUnit を走らせると、残った run は「撮影が始めていない run」になって次の撮影が止まる（Tools タブでキャンセルする）。片付けが失敗したまま PHPUnit を走らせない。

## うまくいかないとき（このプラグインに固有のもの）

- `… shows an error notice`: そのタブの API 呼び出しが失敗している。フィクスチャの無い API を呼んでいないか、`npx wp-env run tests-cli wp db query "SELECT level, message FROM wp_cbjp_logs ORDER BY id DESC LIMIT 10"` を見る。
- `setup: … would be empty`: フィクスチャのカテゴリの候補が変わった（無料版のマッピングはカテゴリだけ）。`setup.php` の対応の作り方を合わせる。
- `the dry run did not complete for every entity: …`: 止まらなかったエンティティと状態（`product=failed` など）と、止まった理由（ジョブの失敗・5 分の期限・アクションを失ったジョブ）を出す。直前に出るエンティティごとの状態と `wp_cbjp_logs` を見る。run はキャンセル済みなので、原因を直してそのまま撮り直せる。
- `no job was created for: …`: `setup_args` に、アダプタが受け付けないエンティティがある。
- `a colorme token other than the screenshot dummy …`（setup・teardown）: tests サイトに撮影用でないトークンが保存されている。誰かが tests サイトを店舗につないだので、撮影も後片付けも何も変えずに止まる（後片付けが止まると mu-plugin は残る）。そのトークンをどうするか決めてから撮り直す・`cleanup` を実行する。
- `the plugin or WooCommerce is inactive … remain(s)`: プラグインが無効のまま、撮影の記録・トークン・開いた run のどれかが残っている。mu-plugin が残っている間に tests サイトで両方を有効にしてから（`npx wp-env run tests-cli wp plugin activate woocommerce cart-bridge-jp`）`cleanup` を実行する。
- `a colorme run that the screenshots did not start is open`（setup・teardown）: 撮影の記録に無い run が開いている（他の人・テストの run、または記録が PHPUnit で消えた後の前の撮影の run）。キャンセルせずに止まるので、終わるのを待つか Tools タブでキャンセルしてから撮り直す・`cleanup` を実行する。
- `the record of the screenshots … is broken` / `the product category … is not one the screenshots made`: 記録の option `cbjp_wporg_screenshots` が壊れているか、記録の ID が撮影の作ったカテゴリでない。何も消さずに止まるので、tests サイトを手で確かめてから記録を直す・消す。
