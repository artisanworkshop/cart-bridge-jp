---
name: rehearse-colorme
description: >
  カラーミーショップの「テストショップ」と wp-env の開発サイトの間で、Cart Bridge JP の全件リハーサル（インポート全般 →
  同じショップへの往復エクスポート → Woo 生まれのデータの作成エクスポート）を回し、ColorMe 側の値が往復で変わっていないかを
  スナップショットの差分で確かめる手順とスクリプト。「リハーサルして」「全件 E2E」「往復でデータが欠けないか確かめて」
  「テストショップで実データ移行を試して」「R3-1」「公開前の最終確認」「スナップショットを取って比べて」などと言われたら使う。
  実店舗（本番のショップ）には使わない（ユーザーが店舗を名指しして明示的に頼んだときだけ。安全規則参照）。
---

# /rehearse-colorme — テストショップでの全件リハーサル

単体テスト・mock では見えない**実 API との往復のずれ**（取込みでの変換 → Woo → export で ColorMe へ戻した値）を、
ColorMe 側のスナップショットを往復の前後で比べて見つける。R3-1（v1.0 公開前の最終確認）のために作った。
R3-4（公開）前の再確認や、v2.0 の BASE（B4-6）でも同じ考え方で使う。

## 安全規則（最初に読む）

- **テストショップ専用**。ColorMe に触るスクリプトは引数 `shop=<login_id>` を必須にし、接続中の店舗（`GET /shop.json` の `login_id`）と
  一致しなければ何もせずに止まる（`php/_lib.php` の `cbjp_rh_require_shop()`）。店舗の login_id は repo に書かず、毎回引数で渡す。
  このガードは「接続先が指定した店舗か」を確かめるだけで、「テストショップか」は確かめない（`run type=export` は `acknowledge_production_write` を自動で付ける）。
- 本番の店舗の login_id を渡さない。本番の店舗にはエクスポート（実 PUT/POST）しない。往復エクスポートは ColorMe の値を書き換える試験で、プラグインはリモートを削除できない。
- 開発サイト（wp-env、`http://localhost:10010`）は `reset-local` で初期化する前提。`reset-local` は環境が `local` かつ host が `localhost` のときだけ動く。
- 出力（スナップショット・dry-run の明細）はリポジトリのルートの `.rehearsal/`（gitignore 済み）にだけ書く。wp-env はルートを `wp-content/cbjp-dev` にマウントするので Web から配信されうるうえ、
  `0.0.0.0` で待ち受けるので（ルートの `.htaccess` も拒否する。D29）、`rehearse.sh` は Apache のアクセス拒否（`.htaccess`）を置き、HTTP で読めない（403/404）ことを確かめてから PHP を動かす（読めたら止まる）。**実店舗で使った場合、その値（商品・顧客・受注の ID、件数）を
  docs・PR・コミットに書かない**（CLAUDE.md）。テストショップの値でも、記録には件数と変化の種類だけを書く。
- 管理画面での入力（カテゴリ・グループ・クーポン・ストアフロントの注文、OAuth の接続）はユーザーが行う。

## スクリプト

`S=.claude/skills/rehearse-colorme/scripts/rehearse.sh`（リポジトリルートから。wp-env は起動済み、Node は `.nvmrc` の版）。
`$S php <name> key=value ...` は `php/<name>.php` を `wp eval-file` で実行する。

| コマンド | 内容 |
|---|---|
| `$S php inspect shop=<id>` | 店舗の税設定・プラン・件数・カテゴリ/グループ/クーポン/決済/配送と、ローカルの件数（mapping・ジョブ・Woo 実体・Woo の税設定〔税区分ごとのプラグインの分類と、税率の表から求めた JP 8% の税区分。D26〕）。読み取りのみ |
| `$S php tax-classes [mode=preview\|ja\|en]` | 開発サイトの既定の税区分を、日本語でインストールした状態（「軽減税」「免税」。スラッグは URL エンコード）と英語の状態（`reduced-rate`・`zero-rate`）で切り替える（issue #102・D26 の確認用）。移し先の税区分を作り、税率を付け替え、商品・バリエーション・受注明細の税区分を CRUD で保存し直してから古い税区分を消す（消すだけだと商品が黙って標準に見える）。移し元と移し先の両方に税率がある対・移し元の税区分が消えているのにまだそれを指す税率・商品・明細がある対があれば何も変えずに止まり、税率が移し先に着かなければ（`_update_tax_rate()` は一覧に無いスラッグを黙って標準にする）止まり、古い税区分を指すものが残れば終了コード 1。既定は preview（件数だけ）。進行中のジョブ・処理中のジョブのアクション（キャンセル直後のページ）があれば止まる |
| `$S php reset-local [mode=preview\|yes]` | 開発サイトの Woo データ（商品・受注・role が customer だけの顧客・クーポン・商品カテゴリ/タグ・取込んだ画像）と `cbjp_*` テーブル・サンプル選定のオプション・ジョブの pending アクションを消す。接続・マッピング設定・Woo の設定は残す。既定は preview（件数だけ） |
| `$S php seed-shop shop=<id> [part=all\|products\|customers] [categories=require\|skip]` | `ZZR-` の商品 56 件（往復リスクを突く 19 件＋埋め草 36 件＋名前に装飾のタグ・`<br>`・実体参照を書いた P56〔R3-1f〕）と `zzr-…@example.com` の会員 12 件を API で投入。同じ名前・メールが既にあれば飛ばす（途中で失敗した投入は再実行で残りだけ入る） |
| `$S php seed-woo [prefix=<英大文字 3 字>] [extra-email=<address>]` | 開発サイトの Woo に、ColorMe 由来でない `ZZW-` の商品 6 件（単純〔SKU なし〕・軽減税率〔JP の税率が 8% の税区分。日本語の「軽減税」でもよい〕・ゼロ税率〔`zero-rate`／「免税」。エクスポートで止まる〕・1 軸でセール中のバリエーション・2 軸・名前が実体参照〔`Fish &amp; Chips &lt;set&gt;`。issue #99〕）と顧客 1 件を作る（8% の税区分かゼロ税率の税区分が無ければ何も作らずに止まる）（手順 3 の作成エクスポート用）。`prefix` は名前・型番・メール・姓の接頭辞（既定 `ZZW`。前のリハーサルでエクスポートした `ZZW-` がテストショップに残っていると、取込みでそれが Woo に入り同じ名前・型番・メールで作れないので `ZZV` などに変える）。`extra-email` はユーザーが受信できるアドレスで顧客を 1 件足す（`add_member` の通知メールの有無の確認用。repo に書かない） |
| `$S php snapshot shop=<id> label=<name> [side=both\|colorme\|woo]` | ColorMe（商品・会員・クーポン・受注）と Woo（商品・バリエーション・顧客・受注・クーポン・`_cbjp_*` メタ・mapping）を `.rehearsal/<name>.json` へ |
| `$S php check-import label=<name> [expect-export-links=yes]` | side=both のスナップショットで、取込んだ値を ColorMe の値と突き合わせる（`MISMATCH`＝規則から見て食い違い／`NOTE`＝規則どおりだが往復で問題になりうる変換／`MISSING`／`LINKED_BY_EXPORT`＝エクスポートで結ばれた実体〔D25。取込みは上書きしないので値は突き合わせない。取込みだけの段階では「印を書かずに結んだ」退行と見分けられないので既定では失敗に数え、手順 3 の作成エクスポートの後は `expect-export-links=yes` で許す〕）。商品（名前〔表示どおりの文字が ColorMe のストアフロントに表示される文字〔タグを除き実体参照を戻したもの。期待値は libxml の DOM で作る `_lib.php` の `cbjp_rh_visible_name()`。R3-1f〕と一致し、保存値が実体参照の形＝生の `<` `>` `\`・実体参照でない `&` が無いこと。issue #99〕・型番・公開状態〔非公開であるべきものの公開は MISMATCH〕・税区分〔軽減税率の商品は JP の税率が 8% の税区分に、それ以外は標準に入っていること。期待する税区分はスナップショットの税の設定〔`woo.tax_setup`: 基準所在地と税率の表〕から、WooCommerce の行の選び方〔優先度ごとに最も具体的な 1 行〕と複合税率の計算を書き写して求める。D26。税の設定の無い古いスナップショットや、郵便番号・市で限定した税率があって再現できない設定は MISMATCH 1 件で取り直しを促す〕・価格・在庫と在庫管理・説明〔`<script>`・`<style>` の中身が文字として残っていないこと。WP の HTML API で中身を空にしてプラグインと同じ浄化を通した期待値と、kses が変化しなくなるまで掛けてから完全一致で比べる〔`_lib.php` の `cbjp_rh_script_style_check()`〕。コメントの中の `<script>` はブラウザでは要素でないので比べない。issue #101〕・同じ id の重複）、バリエーション（型番・価格・セール価格・在庫）、会員（メール・郵便番号・県・住所・法人名・電話・名前）、受注（合計・税額〔`totals` の標準＋軽減〕・決済/配送のマッピング先）。`MISMATCH`／`MISSING`（と、`expect-export-links=yes` が無いときの `LINKED_BY_EXPORT`）が 1 件でもあれば終了コード 1 |
| `$S php diff a=<name> b=<name> [side=colorme\|woo] [entity=products,customers\|all]` | 2 つのスナップショットの ColorMe 側（既定）か Woo 側を id・項目単位で比べる（`make_date`/`update_date`/`account_id` は除外）。`side=woo` の entity は products・customers・orders・coupons・mappings（mappings は `platform/entity/remote_id` で突き合わせる）。D25 の確認（エクスポートで作った実体が再取込みで変わらないこと）に使う |
| `$S php run shop=<id> type=<dry_run\|import\|dry_run_export\|export> [entities=a,b] [cancel-after=n] [max-minutes=n] [attach=<run_id>] [context=cron\|admin]` | `POST /runs` で始め、その run のジョブだけを Action Scheduler の claim を取って同期で処理（管理画面を開いたままでも二重に処理しない。他の run の閉じたジョブのアクションは処理して片付け、開いたジョブのものは手放す）。**ジョブは既定で未ログイン＋kses あり（WP-Cron と同じ）で処理する**（`context=admin` は管理画面から非同期ランナーが動く場合と同じ管理者。R3-1 の時点ではどちらで処理されたかで商品名・説明の保存結果が変わった。R3-1b〔issue #99〕以後は同じになるはずで、両方で取り込んで `check-import` で確かめる）。`attach` は止まった run を続きから処理する。ジョブが失敗・未完了のまま終わると終了コード 1（`cancel-after` で止めた場合を除く）。paused は再開時刻まで待つ。dry-run は明細を `.rehearsal/<run_id>-items.json` に保存して操作・警告コードの件数を出す。import は検証レポートも出す |
| `$S limits-on '<json>'` / `$S limits-off` | 無料版の上限を差し替える mu-plugin（`templates/mu-plugin-rehearsal-limits.php`）とオプション `cbjp_rehearsal_limits` を置く／消す。`{"order":2}` で受注だけ 2 件、全 entity を `null` にすると Pro 版の解除を模擬（F1-8 と同じ） |

## 手順

各段の結果は `docs/reviews/<branch>/rehearsal.md` などに記録する（件数・判定・見つかった差。店舗の ID は書かない）。

### 0. 準備

1. `$S php inspect shop=<id>` で接続先がテストショップであること、税設定（`tax_type`/`tax`/`reduce_tax_rate`/`tax_rounding_method`）とプランを確認する。
2. **ユーザー（管理画面）**: `ZZR-` で始まる大カテゴリ 2（うち 1 つに小カテゴリ 2）・グループ 2・クーポン 2〜3（定額・定率・最低購入金額つきなど）を作る。
   決済方法を 2 種類にしておくと、受注の決済マッピングを複数で確かめられる。
3. `$S php seed-shop shop=<id>` で商品・会員を投入する（`FAIL` が出たものは API で作れなかった条件として記録する）。
4. **ユーザー（ストアフロント）**: 注文を 3〜5 件（会員でログインして／ゲストで、オプション商品・軽減税率の商品・複数明細・クーポン使用を混ぜる）。
5. 開発サイト: `$S php reset-local`（preview）→ `mode=yes`。Woo の税設定を想定する店舗の構成にする（日本の一般的な構成なら「税込入力・標準 10%・軽減税率の税区分に 8%」）。
   変更前の値は記録しておく。Mappings タブで決済・配送のマッピングを設定する。
   **日本語でインストールした店舗（issue #102・D26）を確かめるときは `$S php tax-classes mode=ja`**（`reduced-rate`→「軽減税」、`zero-rate`→「免税」。税率と商品の税区分も移す）。
   取込み（手順 1）で軽減税率の商品が「軽減税」に入り、作成エクスポート（手順 3）で「軽減税」の商品が軽減税率で送られ「免税」の商品が止まることを見る。終わったら `mode=en` で戻す。
6. `$S php snapshot shop=<id> label=s0 side=colorme`（往復前の ColorMe）。

### 1. インポート（ColorMe → Woo）

1. `$S php run shop=<id> type=dry_run`（全量）→ 警告コードの件数と明細を見る。
2. サンプル（無料版の上限）: 受注が少なければ `$S limits-on '{"order":2}'` → `$S php run shop=<id> type=import` → 件数が上限以下であること。
3. キャンセル試験: `$S limits-on '{"category":null,"tag":null,"product":null,"customer":null,"order":null,"stock":null,"coupon":null,"review":null}'`
   → `$S php run shop=<id> type=import cancel-after=2` → もう一度 `type=import`（最後まで）→ 重複が無いこと（mapping 数＝ColorMe の件数、Woo の実体数が一致）。
4. 上限解除の本移行がサンプル分を作り直さないこと（D16。サンプルで作成済みの実体は `unchanged` か `updated`、`created` にならない）。
5. もう一度 `type=import` → 全件 `unchanged`（checksum 一致）。
6. `$S php snapshot shop=<id> label=w1` → `$S php check-import label=w1`。`MISMATCH` は原因を調べ、`NOTE` は往復リスクの実例として記録する。
   ランナーによらないこと（issue #99）は、`reset-local` からやり直して `context=admin` で取り込み、同じく `check-import` が通ること（商品名の `MISMATCH` が無いこと）で確かめる。
7. 検証レポート（`run` が出す `verification`）の件数・受注合計が一致していること。

### 2. 往復エクスポート（Woo → 同じショップへ。D25 以後は何も送らないことを確かめる）

D25（R3-1a、`docs/03` §10.2「往復の扱い（D25）」）以後、取込みで結ばれた実体（`_cbjp_platform` が colorme）はエクスポートしない。

1. `$S php run shop=<id> type=dry_run_export` → 取込んだ商品・会員・在庫がすべて `linked_by_import_not_exported`（スキップ）で、作成・更新が 0 であること。
2. `$S php run shop=<id> type=export` → 何も送らない（作成・更新 0）。
3. `$S php snapshot shop=<id> label=s1 side=colorme` → `$S php diff a=s0 b=s1`。**取り込んだ実体の差が 0** であること（手順 3 を続けて行う場合は、
   作成エクスポートの後にまとめて比べ、Woo 生まれの実体の追加〔`+`〕だけが出ることを確かめてもよい）。D25 より前の往復で起きた変化の実例は
   下の「往復リスク」と `docs/reviews/feat/r3-1-e2e-rehearsal/rehearsal.md`。
4. もう一度 `type=import` → 重複なし・全件 `unchanged`（取込みが書いた checksum をエクスポートが書き換えなくなった）。

### 3. Woo 生まれのデータの作成エクスポート

Woo で作った商品・顧客（`_cbjp_*` メタ無し）を export して ColorMe に新規作成し、API で読み戻して値を確かめる。`$S php seed-woo` で作る（単純〔SKU なし〕、可変〔1 バリエーションだけセール〕、
軽減税率、ゼロ税率（`zero-rate`／「免税」）などの標準・軽減以外の税区分〔R3-1d・issue #78 以後は作成も更新もせず、dry-run・結果に `tax_class_unsupported` が出る。以前は hidden で作成され、次の更新で公開されていた〕、2 軸、名前が実体参照の商品〔ColorMe に `Fish & Chips <set>` の文字で届くこと。issue #99〕、顧客）。前のリハーサルの `ZZW-` がテストショップに残っているときは `prefix=ZZV` などに変える
（手順 1 の取込みでそれが Woo に入っているため）。読み戻した後に `snapshot side=woo`（例 `label=w1`）→ `type=import` → `snapshot side=woo`（`label=w2`）→
`diff a=w1 b=w2 side=woo entity=all` で、**エクスポートで作った実体が再取込みで変わらない**（D25。`linked_by_export_not_imported` でスキップ）・重複が無いことを確かめる。
`check-import` はこれらを `LINKED_BY_EXPORT` として数える（このときは `expect-export-links=yes` を付ける）。`seed-woo` は、作る予定の名前・メールの実体が取込みで結ばれていれば、何も作る前に止まる。
バリエーションの `option_market_price`（定価）の税基準は API の応答に税込の対が無いので、管理画面・ストアフロントの表示で確かめる。
名前・住所がそろわない顧客の更新（issue #100）: 作成エクスポートした Woo 生まれの顧客の郵便番号を消し（`npx wp-env run cli wp user meta update <user_id> billing_postcode ''`）、
`type=export` で顧客が `skipped`・`warned` になり、`PlatformWriter threw while pushing a customer item.` のエラーログが出ない（PUT を送らない）ことを確かめてから郵便番号を戻す。
続けて電話番号だけを消し（`billing_phone`）、`type=export` が `updated` になることを確かめてから戻す（2026-10-08 の再リハーサルで、ColorMe は会員の更新で
電話番号を必須にしないと実測した。プラグインは空の電話番号を省いて送り、ColorMe の値はそのまま残る。422〔`PlatformWriter threw`〕になったら仕様が変わったので、
`CustomerTransformer` の更新の判定に電話番号を足す。review-loop R1-6）。

### 4. mock で確かめるもの

送信結果が不明な状況（D21 / R3-0a/b）とプレミアム限定のベータ機能（受注 export・画像アップロード、D24）は実 API で起こせない・使えないので
`verify-with-mock-adapter`（キー `mockv`）で確かめる。

## 往復リスク（diff・check-import で見る観点）

静的解析で挙げたもの（R3-1）。実データで起きたか・直したかは `docs/10-tasks.md` の R3-1 と `docs/03` を見る。

1. 型番が空 → 取込みが付ける仮 SKU（`colorme-{id}`／`colorme-{pid}-{vid}`）が export で型番として書き戻される
2. `display_state` の `sale_for_members`→`showing`、`showing_for_members`→`hidden`、取込みで private にした商品（販売期間外・売切れ非表示）が `hidden` に固定
3. 在庫管理ありで在庫 null → 取込みで 0 → export で 0（バリエーションでは他のバリエーションと商品の在庫も 0 になる。D22 実装 7.）
4. 説明 HTML の `wp_kses_post` で消えた要素（iframe・script 等）が export で ColorMe からも消える（`<script>`・`<style>` は R3-1c〔issue #101〕から中身ごと除く。以前は中身の JS・CSS が文字として残った）
5. Woo に軽減税率の税区分が無い → D26（R3-1e）以前は `reduced-rate` 決め打ちで、日本語でインストールした店舗（「軽減税」）では取込みで 8% の商品が 10% の税区分に入り、エクスポートでは非標準として扱われた。以後は JP の税率で見分け、8% の税区分が無ければ取込みは標準に倒して `reduced_tax_class_not_found`（商品は checksum を保存しない）
6. バリエーションの `option_price` null（商品価格を継承）→ 明示の値、`get_sku()`/`get_weight()` の親へのフォールバックで親の値が送られる
7. variable 商品の `sales_price` が最安バリエーションの価格で上書き
8. Woo が税抜入力（かつ税率あり）だと、取込んだ税込額にさらに税を足して逆算する
9. 商品名の `&`・`<` などがエンティティ化・除去されて書き戻される（R3-1b〔issue #99〕で、取込みは実体参照で保存・エクスポートは平文へ戻して送るようにした）。
   ColorMe のストアフロントは名前を HTML として表示する（エスケープしない）ので、取込みは名前のタグを除き実体参照を戻した表示どおりの文字にする（R3-1f）。
   エクスポートは Woo の名前の `<…>` をそのまま送るので、ColorMe では HTML として解釈される（既知の限界）

## 後片付け

- `$S limits-off`（mu-plugin とオプションを消す）。
- テストショップの `ZZR-`・Woo 生まれの商品・会員はプラグインから消せない（リモートを削除しない）。ユーザーが管理画面で消すか判断する。
- 開発サイトを戻すなら `$S php reset-local mode=yes`。Woo の税設定を変えた場合は記録した値に戻す（`tax-classes mode=ja` にしたなら `mode=en`）。
- `.rehearsal/` は gitignore 済み。不要なら手で消す。

## 既知の限界

- `diff` は事実（変わった値）だけを出し、想定内か外かは人が仕分ける。
- `check-import` の価格の突合は `tax_type=excluded` かつ `round_off`、または `tax_type=included` の店舗だけ（他の丸めでは価格の行を飛ばす）。
- 商品レベルの重量・単位・表示順・`soldout_display` などは ColorMe の作成・更新 API が受け付けないため、`seed-shop` では作れない（管理画面で設定する）。
