# Cart Bridge JP Pro の販売用の資料

販売サイト（`https://wc.artws.info/`）で Pro を売るための商品説明とアイコン。配布物（`plugins/`）には含めない。

| ファイル | 中身 |
|---|---|
| `product-description.html` | 商品の「説明」。Gutenberg のブロックのマークアップ（コアのブロックだけ） |
| `product-short-description.html` | 商品の「簡単な説明」。素の HTML（ブロックのコメントなし） |
| `icon/icon.svg` | Pro のアイコンの元の SVG（256×256） |
| `icon/icon-128x128.png`・`icon/icon-256x256.png` | アイコン（API Manager の製品・更新の画面など） |
| `icon/icon-1024x1024.png` | 商品画像（WooCommerce の商品画像に使う大きさ） |
| `manual/cart-bridge-jp-pro-manual.docx` | 利用マニュアル（Word。スクリーンショット入り）。原稿・画像・作り直しの手順は `manual/README.md` |

## 商品説明の使い方

1. `product-description.html` の `{{TRIAL_URL}}`（2 か所）を、0 円の Pro の商品の URL に置き換える。
2. WooCommerce の商品の編集画面で、「説明」の欄を**テキスト**のタブに切り替えて貼り付ける（ビジュアルのタブに切り替えると、ブロックのコメントが崩れることがある）。
   ブロックエディタで商品を編集している場合は、コードエディタに貼り付ける。
3. 「簡単な説明」の欄には `product-short-description.html` をテキストのタブで貼り付ける（この欄は `do_blocks` を通らないので、ブロックのマークアップを使わない）。
4. 価格は商品の価格の欄に入れる（説明には書いていない）。

作り:

- 商品の「説明」タブの中に `the_content` で出るので（WooCommerce がタブに「説明」の h2 を付ける）、見出しは h3 から始める。
- 色はテーマのプリセットに頼らず、アイコンと同じ色を直接指定した（藍 `#1b2e5b`・朱 `#e0452e`・金 `#e9b949`・薄い藍 `#f4f6fb`・薄い金 `#fdf6e3`）。テーマを替えても色は変わらない。
- 使ったブロック: グループ・段落・見出し・ボタン・テーブル・カラム・リスト・詳細（よくある質問）。
- 2026-10-23 に開発サイト（WordPress 7.1.3）のブロックエディタに読み込み、76 個のブロックがすべて有効（検証のエラーなし・クラシックのブロックなし）と確かめた。テーブルの `hasFixedLayout` は WordPress 7.1 の既定が true（列の幅が均等になり、スマホ幅で見出しが語の途中で折り返す）なので、2 つの表とも `{"hasFixedLayout":false}` を明示している。

## 書いた事実の出どころ

説明の事実は、実装（`plugins/cart-bridge-jp-pro/`）と設計の記録（`docs/03-design-decisions.md`）から書いた。機能を変えたら説明も直す。

| 説明の項目 | 出どころ |
|---|---|
| 無料版と Pro の分担（表） | `docs/03` §10.0（D27）、無料版の readme、Pro の `ColorMeCommerceAdapter::capabilities()`（顧客は作成・更新、受注のエクスポートはプレミアムプランだけ・Beta、クーポンは読み取りだけ） |
| 受注のエクスポートと画像のアップロードが Beta | `docs/03` §10.2「プレミアムプラン限定機能のベータ扱い（D24）」 |
| 顧客の項目（フリガナ・生年月日・メルマガ） | Pro の `CanonicalCustomer`・`CustomerWriter::apply_extras_meta()` |
| 受注で在庫が減らない・メールが出ない | Pro の `OrderWriter`（`SideEffectGuard`・`set_order_stock_reduced()`） |
| 表せない条件のクーポンは見送る | Pro の `CouponWriter`（`COUPON_RESTRICTIONS_UNSUPPORTED`） |
| パスワード設定メール | `docs/03` §10.5（D28・R3-10） |
| キーが無い間は dry-run だけ・0 円の配布・返金なし | `docs/03` §10.7（D30）・§10.1（D14） |
| サイト数無制限・初回の有効化から 3 か月・期限後も動く | `docs/03` §10.1（D14） |
| 無料版で接続済みなら接続し直す | `docs/03` §10.0「R3-6c2 の実装」（Pro が顧客・受注・クーポンのスコープを足す） |
| BASE・MakeShop は今後 | `CLAUDE.md`（D18。v2.0・v3.0） |

## 公開の前に確かめること（販売サイトの設定）

説明は D14 の決定どおりに書いている。販売サイトの設定がまだなら、設定するか説明を直す。

- **有効期限の起点**: 説明は「初回の有効化から 3 か月」。WooCommerce API Manager の既定は購入時から数えるので、初回の有効化から数えるカスタマイズ（D14）が入っているか。
- **有効化の数**: 説明は「サイトの数に制限なく」。API Manager の製品が「無制限の有効化」になっているか。
- **猶予期間**: API Manager の猶予期間（既定 14 日）の間は、期限後でも新しいサイトを有効化できる。説明の「期限が過ぎると新しいサイトでの有効化はできない」と厳密に合わせるなら 0 にする（`docs/10` R3-8「販売サイト」）。
- **0 円の商品**: `{{TRIAL_URL}}` に入れる商品があり、同じ zip（`cart-bridge-jp-pro-1.0.0.zip`）が置かれているか。

## アイコン

無料版のアイコン（`plugins/cart-bridge-jp/.wordpress-org/icon-256x256.png`。藍の夜に朱の太鼓橋と青海波、月）を SVG で描き直し、左上の空に金の「PRO」の札を足した。
橋の曲線は無料版の PNG の輪郭から合わせ込んだので、並べても形はほぼ同じ。色も無料版から取った。

- 「PRO」の文字は SVG の `<text>`（Avenir Next の Heavy。無ければ Helvetica Neue・Arial）。PNG は macOS で `rsvg-convert` を使って書き出した。別の環境で書き出すと字形が変わりうる。
- 書き出し: `rsvg-convert -w 256 -h 256 icon/icon.svg -o icon/icon-256x256.png`（128・1024 も同じ）
