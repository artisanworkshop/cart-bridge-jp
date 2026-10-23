# Pro のマニュアル用スクリーンショットの設定

`docs/pro-sales/manual/`（Cart Bridge JP Pro の利用マニュアル）の画像を撮る設定。撮影は共有スキル `wporg-screenshots`（`~/.claude/skills/wporg-screenshots/scripts/capture.sh`）が行い、
`--config` でこのディレクトリの設定を渡す。wordpress.org 用の無料版のスクリーンショット（`.claude/wporg-screenshots/`）とは別の設定で、Pro を有効にし、日本語の画面で撮る。

```bash
C=~/.claude/skills/wporg-screenshots/scripts/capture.sh   # リポジトリのルートから実行する
OUT=<スクラッチパッド>/manual-shots
$C shoot --config .claude/pro-manual-screenshots/trial    --out $OUT/trial      # キーを有効化していない Pro（3 枚）
$C shoot --config .claude/pro-manual-screenshots/licensed --out $OUT/licensed   # 偽のキーで有効化した Pro（6 枚）
$C status  --config .claude/pro-manual-screenshots/licensed   # 強制終了した後の確認
$C cleanup --config .claude/pro-manual-screenshots/licensed   # 片付け（teardown は trial・licensed 共通）
```

撮った画像を `docs/pro-sales/manual/images/` に置く名前は `docs/pro-sales/manual/README.md`。**実店舗・テストショップ・販売サイトには接続しない**。撮るのは tests サイト（10011）だけ。

## このディレクトリ

| ファイル | 役割 |
|---|---|
| `build.sh` | `shots.json` の `build`。管理画面をビルドし、無料版の日本語訳（`.mo`・`.l10n.php`・JS の JSON）を `dist/pro-manual-i18n/` に作る（無料版は訳を同梱しないので、撮影の間だけ言語パックの代わりに置く。issue #144） |
| `trial/`・`licensed/` | シナリオごとの `shots.json`・キャプション（`readme.txt`。capture.sh は `plugin_dir` の readme の `== Screenshots ==` と枚数を照合する。プラグインの readme ではない）と、薄いフック（`mu-plugin.php`・`setup.php`・`teardown.php`） |
| `shared/mock.php` | モック。Color Me Shop API への GET を匿名化済みのフィクスチャ（Pro と無料版の `tests/fixtures/colorme/`、撮影用の架空のクーポン `shared/fixtures/shop_coupons.json`）で返し、**販売サイト（ライセンスサーバー・更新の配信）への要求はすべて WP_Error にする**。写る名前の差し替え（開発用の商品名・カテゴリー名、非会員の顧客を会員にして重複するメールアドレスを分ける） |
| `shared/lib.php` | 準備と片付けの処理（`cbjp_pm_setup_trial()`・`cbjp_pm_setup_licensed()`・`cbjp_pm_teardown()`） |

フックは capture.sh が tests サイトの mu-plugins の下へ写して実行する。中身（`shared/`）は、wp-env がルートをマウントした `wp-content/cbjp-dev` から読む（フックの写しは `shared/` を持たないため）。

## 準備と片付け

| | trial | licensed |
|---|---|---|
| 前提の確認 | tests サイト・環境種別 local・モックの読み込み・Pro の起動・`colorme` のトークンが無いか撮影用・Pro のライセンスが無いか撮影用・撮影が始めていない `colorme` の run が開いていない。どれかが欠ければ何も変えずに止まる | 左に加えて、ストアが空（`colorme` の紐づけ・商品・クーポン・顧客・受注・商品のカテゴリー〔既定の「未分類」を除く〕とタグが 0 件。SQL で数え、照会が失敗したら止まる）。取り込んだものを「紐づけが指す実体」として消すため |
| 準備 | サイト名・通貨・国・日付の書式、無料版の訳、偽のトークン、配送ゾーン「国内」、マッピング | 左に加えて、偽のキーでの有効化の記録、マイアカウントのページ |
| 実行 | 全種類のプレビュー（在庫を除く） | 全種類のプレビューと取り込み |

撮影で作ったものは option `cbjp_pro_manual_screenshots` に記録し、teardown は記録にあるものと、撮影用と確かめられたもの（偽のトークン・偽のキー・置いた訳の SHA-256・撮影が始めた run）だけを消す・キャンセルする。最後に記録が空になったことを確かめる。
置いた訳が前の撮影の後に置き換わっていれば（言語パックの更新など。SHA-256 が記録と違う）、setup も上書きせずに止まる。
書き換える option（サイト名・通貨・日付の書式などの表示、`cbjp_settings_colorme` の対応付け、Pro のライセンスの状態・instance・状態確認の結果、マイアカウントのページの設定）は、書き換える前の DB の値を記録に控え、teardown がそのまま戻す（無かったものは消す）。消した・戻したことは `get_option()`（このリクエストのキャッシュ）ではなく DB の行で確かめる。記録（中の一覧・ID を含む）が壊れていれば、空と読まずに止まる。
対応付けのために作ったカテゴリーは、記録の ID の term が撮影の付けた名前で、WooCommerce の既定のカテゴリーでないときだけ消す（`wp_delete_term()` は `product_cat` の既定を守らない）。
WooCommerce・無料版・Pro のどれかが無効なとき、teardown は片付けられないので、撮影の記録・`colorme` のトークン・ライセンスのキーか有効化の記録・開いた `colorme` の job のどれかが DB に残っていれば止まる（モックを外した後に有効化すると、本物の API・販売サイトに届きうるため）。プラグインを有効にしてから cleanup をやり直す。
取り込んだものは `colorme` の紐づけ（`cbjp_mappings`）から辿って消す。知らない種類の紐づけがあれば、何も消さずに止まる（モックは残るので外へは出ない）。1 件でも消せなければ紐づけを残して止まり、消した後に前提と同じ範囲の件数が 0 件に戻ったことを確かめる。

PHPUnit は tests サイトと同じ DB を使い、起動時に戻すのはコアのテーブルだけ（受注〔HPOS〕・配送ゾーン・プラグインの表の行は残る）。片付けが失敗したまま PHPUnit を走らせない。

## 撮り直すときに確かめること

- 撮影の前後で tests サイトの件数が変わっていないこと（紐づけ・商品・クーポン・顧客・受注・配送ゾーン・`wp-content/languages/plugins/cart-bridge-jp-ja*`・`cbjp_pro_license_*`・`cbjp_token_colorme`）。
- 画像に実店舗の名前・ドメイン・件数が無いこと。写る名前は `Example Store`・`localhost:10011`・フィクスチャと撮影用の架空のデータだけ。
- エラーの通知が写っていないこと（capture.sh は既定のエラーの通知で止まる）。
