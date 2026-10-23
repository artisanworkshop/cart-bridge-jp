# Cart Bridge JP Pro 利用マニュアル（Word）

`cart-bridge-jp-pro-manual.docx` は、このディレクトリの章ごとの Markdown（`01-introduction.md` 〜 `10-faq.md`）と `images/` のスクリーンショットから作った Word のマニュアル。
docx を直接直さず、Markdown を直して作り直す（Google ドキュメントにするときは、docx を Google ドライブに置いて「Google ドキュメントとして保存」）。

| ファイル | 中身 |
|---|---|
| `01-introduction.md` 〜 `10-faq.md` | 章（1 ファイル = 1 章。先頭の `#` が章の見出し） |
| `images/` | スクリーンショット（日本語の管理画面。データは匿名化済みのフィクスチャと撮影用の架空のデータ） |
| `template.docx` | 体裁（A4・余白 2.3cm・本文と見出しは游ゴシック）。pandoc の既定の reference.docx を元にした |
| `cart-bridge-jp-pro-manual.docx` | できあがり |

## docx を作り直す

グローバルスキル `md-to-docx-manual`（pandoc）で作る。このディレクトリで:

```bash
node ~/.claude/skills/md-to-docx-manual/scripts/build-manual.mjs \
  --title "Cart Bridge JP Pro 利用マニュアル" --chapters --reference-doc template.docx \
  --out cart-bridge-jp-pro-manual.docx \
  01-introduction.md 02-preparation.md 03-install-connect.md 04-mappings.md 05-dry-run.md \
  06-license.md 07-import.md 08-password-emails.md 09-export-tools.md 10-faq.md
```

画像のリンク切れはスクリプトが警告する（変換は止めない）ので、出力を必ず読む。縦に長い画像は Markdown の `{width=90%}` などで縮める（`mappings.png`）。

## スクリーンショットを撮り直す

撮影の設定はリポジトリの `.claude/pro-manual-screenshots/`（共有スキル `wporg-screenshots` の `capture.sh` を `--config` で使う）。手順と安全のための前提はそのディレクトリの `README.md`。
撮った `screenshot-N.png` を、次の名前で `images/` に置き直す。

| 設定 | 画像 | `images/` の名前 |
|---|---|---|
| `trial`（キーを有効化していない Pro） | screenshot-1 | `connections.png` |
| | screenshot-2 | `license-not-activated.png` |
| | screenshot-3 | `import-dry-run.png` |
| `licensed`（偽のキーで有効化した Pro） | screenshot-1 | `license-activated.png` |
| | screenshot-2 | `mappings.png` |
| | screenshot-3 | `import-results.png`（下の切り出し） |
| | screenshot-4 | `password-emails.png` |
| | screenshot-5 | `export.png` |
| | screenshot-6 | `tools.png` |

`import-results.png` は縦に長い（約 2,700px）ので、左のメニューを外し、タイトルとタブの帯と「インポートの結果」以下（検証レポートを含む）をつなぐ。
「インポートの結果」の見出しの位置（下の例の `1355`）は、撮った画像で確かめてから切り出す。

```bash
magick \( screenshot-3.png -crop 1120x100+160+40 +repage \) \
       \( screenshot-3.png -crop 1120x<高さ>+160+1355 +repage \) \
       -background '#f0f0f1' -append images/import-results.png
```

## 内容を直すとき

マニュアルの事実（機能・画面の文言・ライセンスの条件）は、実装と `docs/03-design-decisions.md`（§10.0・§10.1・§10.5・§10.7）から書いた。機能・画面の文言を変えたら、該当する章と画像を直す。
ライセンスの期間（初回の有効化から 3 か月・サイト数無制限）は D14 の決定どおりに書いている。販売サイトの API Manager の設定と食い違うなら、どちらかを直す（`docs/pro-sales/README.md` の「公開の前に確かめること」）。
