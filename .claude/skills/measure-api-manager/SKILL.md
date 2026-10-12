---
name: measure-api-manager
description: >
  販売サイトのライセンスサーバー（WooCommerce API Manager）の zip を wp-env の dev サイトに一時的に入れ、Cart Bridge JP Pro が使う
  API（有効化・無効化・状態確認・update・information）の生の応答を測って Pro のフィクスチャ（`tests/fixtures/api-manager/`）と比べ、
  更新の配信の実機確認（E2E）も行い、最後に入れたもの・作ったものを完全に消して消し残しが 0 であることを確かめる手順。
  「API Manager の新しい版で応答を測り直して」「販売サイトに入れる版で実測して」「ライセンス・更新の処理を変えたので実機で確かめて」
  「API Manager のフィクスチャを取り直して」などと言われたら使う。R3-8a（3.6.0）と R3-8c（3.8.1）で手で行った手順をスクリプトにしたもの。
---

# /measure-api-manager — 手元の API Manager での実測

R3-8a・R3-8c で手で組んだ「API Manager を dev サイトに入れて測り、完全に消す」手順をまとめたもの（issue #126）。**dev サイト（10010）だけを対象にし、
tests サイト（10011）と本番の販売サイトには触れない**。API Manager は有料のプラグインなので、zip はリポジトリに入れない（手元の zip のパスを渡す）。

## いつ使うか / 使わないか

- 使う: API Manager の新しい版が出たとき（応答の形・文・振る舞いが変わっていないか）。販売サイトに入れる版を決めるとき。
  Pro のライセンス・更新の処理（`plugins/cart-bridge-jp-pro/includes/License/**`）を変えたとき（E2E）。フィクスチャを取り直すとき。
- 使わない: 本番の販売サイトでの確かめ（下の「本番の販売サイトで確かめること」を人が手で行う）。tests サイト・PHPUnit の中（使うのはフィクスチャ）。

## 安全規則

- **dev サイトでだけ動く**: PHP は `home_url()` が `.wp-env.json` の `port` の URL と一致し、環境が `local` のときだけ動く。
- **始める前に dev サイトがきれいであることを確かめる**（`preflight`）: API Manager（プラグイン・テーブル・option・cron・予定）、Pro のキーの記録、前回の製品・注文・顧客、
  このスキルの mu-plugin、Pro のヘッダーの一時的な行が 1 つでもあれば、何もしないで止まる。残っているものは、中身を確かめてから手で消す（このスキルは自分の状態ファイルにあるものしか消さない）。
- **作ったものは状態ファイル `dist/api-manager/state.json` に作るたびに書く**。状態ファイルには手元の API Manager のキーが入る（`dist/` は gitignore 済みで、HTTP で読めないことを毎回確かめる）。
- **teardown が消すもの**: 製品・ページ・注文・顧客は、状態ファイルの ID と目印（投稿・注文の meta `_cbjp_am_probe`、顧客のログイン名 `cbjp-am-buyer`）が一致するときだけ消す。
  API Manager のテーブル・option・usermeta・cron・予定と、Pro のキーの記録（`cbjp_pro_license_*`）は**名前で消す**。setup の前に 0 だったことを preflight で確かめているので、消えるのは setup の後にできたもの。
  **setup の間に dev サイトの Pro の設定画面で保存したキーも消える**（その間、Pro の接続先は手元の API Manager）。
- **状態ファイルとサイトの DB をトークン**（option `cbjp_am_probe_token`）**で結び付ける**。DB を作り直した（`wp-env clean` など）後に古い状態ファイルが残っていても、それで消さない。
  **状態ファイルが無い・DB と結び付かないのに残りがあれば、推測で消さずに止まる**。
- **mu-plugin は後片付けがすべて成功した後に外す**（`wporg-screenshots` と同じ順）。目印の無い同名のファイルは上書きも削除もしない。
- **更新の実行（`wp plugin update`・管理画面の「今すぐ更新」= Plugin_Upgrader）はしない**。プラグインのディレクトリはリポジトリのマウントで、上書きすると作業ツリーが消える。mu-plugin が
  `upgrader_pre_install` と自動更新を止めている。package は「取得できる URL か」まで（必要なら手で curl して zip の中身を見る）。
- **E2E は Pro のメインファイルを一時的に書き換える**（`Update URI` が空の間だけ。下の手順 5）。始める前にそのファイルが clean であることを確かめ、`e2e stop`・`teardown` で
  足した行だけを消して、無くなったことを確かめる。戻し忘れたまま品質チェックを走らせると、`LicenseConfigTest`（ヘッダーの `Update URI` と接続先の host の一致）が止める。**この状態でコミットしない**。

## 手順

リポジトリのルートで実行する（`A=.claude/skills/measure-api-manager/scripts/am.sh`）。wp-env が起動していて、dev サイトで WooCommerce・Cart Bridge JP・Pro が有効であること。

1. `$A status` — 状態ファイル・消し残し（`LEFTOVERS=0` なら何も無い）・mu-plugin・Pro のヘッダーを見る。
2. `$A setup --zip ~/Downloads/woocommerce-api-manager.<版>.zip [--new-version X.Y.Z]`
   - preflight → `bin/build-zip.sh --plugin cart-bridge-jp-pro` で Pro の zip を作り、版を上げた写し（既定は今の版の patch+1。WP が更新として出す版）を作る。
   - API Manager を入れ、テーブルが無ければインストーラーを HTTP の要求で起こす。
   - API の製品（版・`tested`・説明と変更履歴のページ・ダウンロードのファイル）・顧客・完了した注文 2 件・API のリソース 2 つ（キー A・B）を作る。
   - 製品 ID を埋めた mu-plugin を置き、手元の API Manager が状態確認に答えることを確かめる。
   - 途中で止まったら `$A teardown`（作ったところまでを消す）。
3. `$A measure all`（または `$A measure before,activate`）— 下の「測る場面」の要求を送り、応答を `dist/api-manager/out/<API Manager の版>/<名前>.json` に保存する。
   各グループの初めにリソースを戻すので、どのグループからでも、何度でも測れる。
4. `$A compare` — 今の setup で測った応答（`out/<版>/run.json` で見分ける）を匿名化して Pro のフィクスチャと比べる。実行時間・日付・期限・版は比べない。
   - 違いがあれば差分を出して exit 1。匿名化した応答は `dist/api-manager/anon/<版>/` に置き（前回の分は消してから書く）、手元の値（URL・ID・ハッシュ・キー。配列のキーも）が残っていれば書かない。
   - 前の setup の応答は比べられない（その setup の ID を知らないので匿名化できない）。別の版と比べるなら、teardown → その版で setup → measure → compare。
   - **フィクスチャは書き換えない**。採るなら差分を読んでから `cp` で写し、Pro のテストと `docs/03` §10.7 を直す。
   - 有効化・無効化・状態確認のフィクスチャは 3.6.0（R3-8a。3.8.1 でも同じ）、update・information は 3.8.1（R3-8c）で測った。3.6.0 で比べると information の節の名前が違う（`changelog` が小文字・説明の節が無い）。
5. E2E（更新の配信の実機確認）:
   - `$A e2e start`: リソースと Pro のキーの記録を戻し、Pro のヘッダーに ` * Update URI: http://wordpress/` を足す。ヘッダーに販売サイトの `Update URI` が既に入っていれば触らない
     （要求は mu-plugin の接続先の定数で手元へ行く）。
   - `$A e2e check`: 未有効化では販売サイトへの要求が 0 件で、Pro の行が出ない。WP が wordpress.org の確認に失敗して Update URI の段まで進まなかったときは、
     何も言えないので exit 1（コンテナが外に出られないときなど）。
   - `$A e2e activate` → `$A e2e check`: 更新の一覧（`response`）に新しい版と package が出る。
   - `$A e2e details`（「詳細を表示」の `plugins_api`。節は小文字の名前）、`$A e2e rows`（プラグインの一覧の更新の行）。
   - `$A e2e expire` → `$A e2e check` → `$A e2e rows`: package が空で `disable_autoupdate`、更新の行に「自動更新は利用できません」と Pro の説明が出る。
   - `$A e2e forget` → `$A e2e check`: 手元の有効化の記録を消すと要求が 0 件に戻る。
   - `$A e2e stop`: 足した行を消し、無くなったことを確かめる。ほかの未コミットの変更（E2E の間に自分で直したもの）は残して警告する。
6. `$A teardown` — 次の順で行い、最後に消し残しを数え直す。0 でなければ exit 1。何度実行してもよい（途中で止まったら原因を直してもう一度）。
   1. Pro のヘッダーを戻す
   2. 注文 → 製品・ページ（WC が積んだ属性の索引の更新の予定も）→ 顧客、Pro のキーの記録・要求の記録・WP の更新の情報、承認済みディレクトリのモード、アップロード
   3. API Manager を無効化・削除する
   4. API Manager のテーブル・option・usermeta・cron・予定を消し（数える範囲と同じ関数）、ほかに残りが無ければ DB のトークンを消す
   5. mu-plugin を外す
   - 状態ファイルと写した zip は消し、応答（`out/`・`anon/`）は残す。
7. `$A status` で `LEFTOVERS=0`・`mu-plugin: absent`・`Pro header: no temporary Update URI line`、`git status` が clean であることを確かめる。

## 測る場面（`php/measure.php`）

| グループ | 要求（出力の名前） | 確かめること |
|---|---|---|
| `before` | 未有効化の状態確認・update・information、キー無しの update、存在しない製品 ID（99999）の update と有効化、未知のキーの update と有効化 | 未有効化では package が空。エラーも HTTP 200 で `success:false` |
| `activate` | 有効化・同じ instance での再有効化・状態確認・update（古い版と最新の版）・information | 有効化済み・期限内だけ package が付く。information の節の名前は訳した表示名 |
| `exchange` | キー A で有効化 → 同じ instance でキー B を有効化 → 両方の状態確認 → B の無効化 | 3.8.x は期限内でも通る（3.6.0 は断った）。R3-8a の処理はこの差を前提にしている |
| `deactivate` | 2 つの instance で有効化 → 1 つを無効化 → 状態確認 → 無効化のやり直し | 無効化しても別のサイトの有効化は残る。やり直しは失敗 |
| `expired_update` | 有効化 → 期限を 1 時間前に → update が先 → information・状態確認 | 期限後の update でサーバーが有効化を消し、package を空で返す |
| `expired_status` | 有効化 → 期限切れ → 状態確認が先 → 無効化・update、猶予期間の中の新しい instance の有効化、期限後のキーの交換 | 状態確認で inactive。期限後の無効化は失敗 |
| `after_grace` | 有効化 → 期限を 30 日前に → 状態確認・update・有効化・無効化 | **近似**: 猶予期間の後にリソースを消す API Manager の背景処理は走らないので、本番とは違いうる |

期限は API Manager のテーブル `wc_am_api_resource.access_expires` の書き換えで作る。API Manager の応答のキャッシュ（`wc_am_` の transient。本番では既定で 60 分）は、
グループの初めと期限を変えた直後に捨てる。本番では期限の直後もしばらく古い応答が返りうる（`docs/03` §10.7「R3-8c の実装」）。

## 仕組み

| ファイル | 役割 |
|---|---|
| `scripts/am.sh` | 入口。サイトの URL・mu-plugin の場所（`npx wp-env install-path`）・出力の置き場所の確認、Pro の zip、Pro のヘッダーの出し入れ、PHP の呼び出しの順序 |
| `php/_lib.php` | 引数・サイトの確認・状態ファイル・**消し残しの数え方**（preflight と teardown が同じ範囲を数える）・リソースのリセット・期限切れ |
| `php/preflight.php` / `php/status.php` | 始める前の確認（何も変えない）／状態と消し残しの表示 |
| `php/setup.php` | `step=init\|tables\|data\|verify`。init が DB のトークンを書き、作るたびに状態ファイルへ書く |
| `php/measure.php` | 生の要求（Pro と同じく本文で送り、転送しない）と応答の保存。どの setup で測ったかを `run.json` に書く |
| `php/anon.php` | WP を使わない匿名化・比較・漏れの検出とフィクスチャとの対応表。`php/test-anon.php` がホストの php で確かめる（`quality.sh`・CI の dev-tooling） |
| `php/compare.php` | 匿名化してフィクスチャと比べる |
| `php/e2e.php` | 更新の配信の実機確認（`wp_update_plugins()`・`plugins_api()`・`wp_plugin_update_row()`） |
| `php/teardown.php` | `step=data`（API Manager が有効な間）と `step=am`（削除した後） |
| `templates/mu-plugin-api-manager.php` | Pro の接続先・製品 ID の定数、`wordpress` の host を外部として許す、更新・インストールの実行を止める、要求の記録 |

## 落とし穴（R3-8c で踏んだもの。スクリプトは対処済み）

- **WP-CLI で API Manager を有効化しても、テーブルが作られない**（3.6.0・3.8.1 とも）。インストーラーは `init` の `check_version` で走るので、HTTP の要求（`admin-ajax.php`）で起こす。
- **ダウンロードのファイルが承認済みディレクトリで弾かれる**（承認済みディレクトリに足しても、製品の保存で弾かれた）。setup の間は承認のモードを外し、teardown で setup の前の値に戻す。
- 注文を完了にしてもリソースが作られないことがある（テーブルが無いまま完了にした場合）。無ければ `WC_AM_ORDER()->update_order()` を呼ぶ。
- **3.8.1 のアンインストーラーは `wc_am_secure_hash` と option しか消さない**。残り 5 つのテーブル・cron・予定は teardown が消す。API Manager が有効なまま option を消すと、
  次の要求でインストーラーがテーブルを作り直すので、無効化・削除してから消す。
- 製品の保存・削除で WooCommerce が属性の索引の更新の予定（`woocommerce_run_product_attribute_lookup_update_callback`、引数が製品 ID）を積む。teardown が消す（R3-8c の手での片付けでは残った）。
- 注文の作成・完了で WooCommerce がメールを送る（dev サイトのメールのログに残る）。setup の間は WooCommerce のメールを止める。
- `npx wp-env run` は中のコマンドの終了コードを 1 にまとめる。am.sh は PHP の出力（`missing tables:`・`PRODUCT_ID=`・`LEFTOVERS=`）で見分ける。
- `wp eval-file` の作業ディレクトリは `--env-cwd`（リポジトリのルートのマウント）なので、`wp plugin install` の zip はルートからの相対パスで渡す。
- コンテナの名前 `wordpress` はプライベートなアドレスに解決され、`wp_safe_remote_post()` が「A valid URL was not provided.」で断る。mu-plugin が `http_request_host_is_external` で許す。
  mu-plugin のテンプレートに小文字の `'wordpress'` を文字列で書くと、WPCS の CapitalPDangit の自動修正が `'WordPress'` に変える（接続先の定数から host を取る）。
- 残るもの（害は無い）: teardown は WP の更新の情報（`update_plugins` の site transient。手元の package が入る）を消すので、次の画面の表示で更新を確かめ直す。
  WooCommerce が HTTP の要求で作るゲストのセッション（2 日で消える）と、製品のキャッシュの版の transient が残ることがある。

## 本番の販売サイトで確かめること（人が手で行う。`docs/10` R3-8「販売サイト」）

このスキルは本番に要求を送らない。販売サイトを公開する前に、テスト用の製品・キーで次を確かめる（その時点の API Manager の版で）。
- 有効化・状態確認・無効化・update・information の応答の形と文が、このスキルで測った同じ版の応答と同じか（とくに information の節の名前。販売サイトの言語で訳される）
- package の URL が https で、販売サイトの host か（Pro は接続先が https なら https の package しか使わない）
- 猶予期間（`woocommerce_api_manager_grace_period`。既定 14 日）の設定と、その後に有効化・状態確認がどうなるか（このスキルでは近似しか測れない）
- 有効期限の起点を初回の有効化にするカスタマイズ（D14）が、`access_expires` と状態確認の期限の表示に効いているか
- API Manager の応答のキャッシュ（既定 60 分）の設定

## してはいけないこと

- 更新の実行（`wp plugin update cart-bridge-jp-pro`・管理画面の「今すぐ更新」）、`wp plugin delete cart-bridge-jp-pro`・`cart-bridge-jp`（リポジトリのマウント）。
- tests サイトでの実行（PHPUnit と同じ DB。tests サイトの mu-plugin は PHPUnit にも読み込まれる）。
- `dist/api-manager/` の状態ファイル・応答をリポジトリやプラグインのディレクトリに写すこと（キー・手元の ID を含む）。フィクスチャに採るのは `anon/` の匿名化済みのものだけ。
- `e2e start` の後、ヘッダーの一時的な行が入ったままコミットすること。
- 状態ファイルを手で消して teardown を通すこと（消し残しを推測で消すことになる）。

## うまくいかないとき

- `preflight` が止まる: 出力の項目（`am_tables`・`am_options`・`pro_license_options`・`probe_token` など）を `$A status` で見て、前回の残りなら状態ファイルがあるか確かめ、あれば `$A teardown`。
  無いのに残っているもの（手で入れた API Manager・自分で保存した Pro のキーなど）は、中身を確かめてから手で消す。
- `the state file does not belong to this dev-site database`: DB を作り直した後に古い状態ファイルが残っている。サイトに残りが無ければ `$A teardown` が状態ファイルを片付ける。
  残りがあれば止まるので、中身を確かめてから手で消す。
- `setup` が途中で止まる: 出力の最後の行に理由が出る。`$A teardown` で作ったところまでを消してから、原因を直してやり直す。
- `teardown` が `LEFTOVERS` で止まる: `$A status` の `leftovers` の 0 でない項目を見る。原因を直してもう一度 `$A teardown`（済んだところは何もしない）。
- `e2e start` が止まる: Pro のメインファイルに未コミットの変更がある（コミットするか退避してから）。setup が済んでいない。
- `the dev site … answered HTTP …` / `install-path failed`: `npx wp-env start`。
