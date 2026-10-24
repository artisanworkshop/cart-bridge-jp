---
paths:
  - ".claude/skills/**/scripts/**"
  - ".claude/skills/**/templates/**"
  - ".claude/skills/**/examples/**"
  - ".claude/skills/**/php/**"
  - ".claude/wporg-screenshots/**"
  - ".claude/pro-manual-screenshots/**"
  - "bin/**"
  - ".github/workflows/**"
---

# `.claude/skills/` 配下のスクリプト・テンプレート・example の落とし穴

`ci-wait.sh`・`bot-wait.sh`・`mock-adapter.sh` などの開発補助スクリプト・`bin/` のリリースの検査・`.github/workflows/` が対象（プラグイン本体ではない）。
PR #50 では同種の指摘を Copilot・Codex から計 4 ラウンド受けた。

- **判定の前提になる取得（PR の HEAD・ブランチ名・存在確認など）を `|| true` で握りつぶさない**。一時的な API 失敗が
  「変化なし」「ガード不要」に化けて、古い HEAD のチェック結果を成功と報告する（`ci-wait.sh` の最終 HEAD 取得と起動時の
  ブランチ名取得で連続して指摘された）。既存の失敗ポリシー（`MAX_API_FAILS` 回まで再試行し、超えたら非ゼロで終了）で扱う。
  `|| true` を付けてよいのは、失敗しても結論が変わらない取得だけ（アーキテクチャ原則 9「フェイルクローズ」のスクリプト版）
- **`set -e` 下で `out=$(cmd)` を単独の代入文にしない**。`cmd` が失敗した時点でスクリプトごと終了し、次行で出力を表示する前に
  エラー本文が消える（終了コードだけが残る）。`if out=$(cmd); then rc=0; else rc=$?; fi` で受けてから出力し、`return "$rc"` する
  （`mock-adapter.sh` の `wp()` 参照）。新しく書く**テストスクリプトにも同じ規約を適用する**（`test-gate-bodies.sh` の `load` ヘルパー参照。PR #64 で、この規約を追加したばかりの PR のテスト自身が違反し、Codex に P1 で指摘された）。
  出力を `>/dev/null`・`--quiet` で捨てるのも同じで、CI・release のログにエラー本文が残らない。受けておき、失敗したときだけ全文を出す（`bin/build-zip.sh`。PR #113 R1-3）
- **`npx wp-env` はリポジトリのルートで実行する**（カレントの `.wp-env.json` を探すので、プラグインのディレクトリで走らせると `docker-compose.yml` が無いという無関係に見えるエラーになる）。
  プラグインのディレクトリへ `cd` するスクリプトは `(cd "$ROOT" && npx wp-env "$@")` を通す（`bin/i18n.sh` の `wp_env()`）。リポジトリのファイルを `wp eval-file` で使うときは
  `--env-cwd=wp-content/cbjp-dev`（ルートのマウント。プラグインのマウントからは `../../cbjp-dev/…`）。R3-7
- **`f || rc=1` の形で呼ぶ関数の中では `set -e` が効かない**（`||` の左辺は errexit が外れる）。関数の中の失敗は `cmd || return 1` で明示的に返す。
  逆に EXIT トラップの中では `set -e` が効くので、片付けを並べると最初の失敗で残りが走らない。1 つずつ `|| rc=1` で受けて最後まで続ける
  （共有スキル `wporg-screenshots` の `capture.sh` の `remove_installed`・`finish`。PR #112 のプロジェクトスキルだったころ、`rm` が失敗しても「removed」と 0 を返していた。R1-2）
- **mu-plugin テンプレートは致命的エラーを出さない**。mu-plugin の fatal は開発サイトの全リクエストと、WP を起動する
  `mock-adapter.sh run`/`inspect`（＝ seed の cleanup）を落とす（`uninstall` はホスト側の `rm` なので効く）。
  オプション等から読む値は型を確認し、想定外の値は読み飛ばす（`templates/mu-plugin-mock-adapter.php` の `$rows()` 参照）。
  新しい読み取りを `$rows()` の外へ足すときも `is_array( $seed ) && is_array( $seed['k'] ?? null )` の二段階にする
  （`$seed['k'] ?? null` だけでは `$seed` が stdClass のとき Error。PR #80 G3-2 で Copilot が High として指摘）
- **API のモック（mu-plugin）を外すのは、外すと実 API に出うる状態を片付けた後にする**。偽のトークン・開いた run・キューに残ったアクションが
  あると、モックを外した後で管理画面・WP-Cron・プラグインの再有効化から実 API へ通信が出る。片付けられないとき（プラグインが無効など。無効化はデータを消さない）は
  モックを残して失敗する。片付けは自分の作ったものだけにし、他人のトークン・run には触れない（トークンは生の option で持ち主を肯定形で確かめる。
  復号できない値は `TokenStore::get()` が null を返すので、null を「無い」と読まない）。`.claude/wporg-screenshots/lib.php` の `cbjp_shot_guard_teardown()`。PR #112 の G1〜G3 で 1 段ずつ指摘された
- **tests サイトを書き換えるフック（setup・teardown）は、記録してから変え、記録と照合できたものだけを戻す**（PR #152 で Copilot が 3 ラウンド・13 件、すべてこの形）。
  書き換える option は前の DB の生の値を控えて戻し、消した・戻したことも DB の行で確かめる（`delete_option()` は DB の削除が失敗しても `notoptions` に「無い」と書く）。
  消す・上書きするのは、記録にあり持ち主の印（偽のトークン・名前・SHA-256・自分が始めた run の ID）が一致するものだけ（記録の名前だけでは、言語パックの更新が置き換えたファイルを上書きする。
  `wp_delete_term()` が既定を守るのは `category` と `default_term` を登録したタクソノミーだけで、WooCommerce の既定の商品カテゴリー〔`default_product_cat`〕は消える。
  その option にはインストールが term_taxonomy_id を、管理画面の「既定にする」が term_id を書くので、両方と比べる）。
  記録（中の一覧・ID も。option の控えは名前の集合まで）が壊れていれば空と読まずに止まり、照会の失敗（`$wpdb->last_error`）でも止まる。
  run のキャンセルの REST（`POST /runs/{id}/cancel`）は DB の書き換えの結果を見ずに 200 を返すので、ジョブが閉じたことを照会し直してから記録を消す（PR #154）。プラグインが無効で片付けられない経路も、トークン・ライセンス・開いた job の行を直接読み、残っていれば止まる。
  `.claude/pro-manual-screenshots/shared/lib.php`・`.claude/wporg-screenshots/lib.php`（無料版の撮影も同じ形にした）
- **wp-env の tests サイト（10011）と PHPUnit は同じ DB・同じ接頭辞を使う**。PHPUnit が起動時に戻すのはコアのテーブル（オプション・投稿・ターム・ユーザー）だけで、
  プラグイン・WooCommerce の独自テーブル（`cbjp_jobs` などと Action Scheduler・配送ゾーン）の行は残る。tests サイトで検証の run を開いたまま残すと、
  `colorme` で run の排他を通るテストが 409 になりうる（PR #112 R1-3・R1-4）
- **bot レビュー本文の整形（`gate-bodies.sh`）は、本文の形式が変わっても指摘を握りつぶさない側に倒す**。Copilot の本文は旧形式（`Suppressed comments`）と新形式（`ccr-overview-v2`: `Open`/`Previously missed`/`What changed in this PR` の `<details>`）が混在し、`Previously missed` はスレッドの無い新規指摘なのに旧版は整形出力に出さず、`--raw` で読まなければ見落とすところだった（PR #61 G2）。未知の `<details>` 節は出す側に倒し、ノイズと分かっている節（ファイル要約の表）だけ明示的に除外する。整形ロジックの回帰テストは `scripts/test-gate-bodies.sh`（`--format` に fixtures の本文を流す。ネットワーク不要。`quality.sh` と CI の `dev-tooling` ジョブで実行される）
- スクリプトは手元の macOS（bash 3.2・BSD awk）と CI の `dev-tooling` ジョブ（Ubuntu の bash 5・mawk）の**両方で動く**書き方にする。`test-gate-bodies.sh`・`test-gate-turn.sh`・`test-gate-record.sh` は `quality.sh` の末尾と CI で実行される（PR #64・#66・#68）。他の補助スクリプトにテストを足すときも同ジョブに追加する。手元で Ubuntu の挙動を確かめるには `docker run --rm -v "$PWD":/repo:ro -w /repo ubuntu:24.04 bash -c '…'`（`jq perl git` を入れ、`git config --global --add safe.directory /repo`）。Copilot は macOS の BSD `find` に `-maxdepth` が無いと誤って指摘する（ある。`man find` と実行で確かめて返信する。PR #152）
- **bash のダブルクォート内で `$変数` の直後に全角文字（`（` など）を置かない**（`$RC（期待…）` は `unbound variable` で `set -u` 下のスクリプトを FAIL も出さずに異常終了させる。`${RC}` と書く）。テスト自身のメッセージで起きやすく、`test-gate-turn.sh` とフックのテストで実際に踏んだ。ミューテーションで「FAIL が明示的に報告されること」まで確かめると気付ける。
  **さらに、`set -e` と `trap … EXIT` の両方を持つテストスクリプトは、macOS の bash 3.2 だとこの異常終了が終了コード 0 になる**（`set -euo pipefail` +
  トラップ〔中身は `:` でも `rm -rf` でも〕=0、トラップ無し=1、`set -uo pipefail`〔`-e` なし〕+ トラップ=1 を実測。`rc=$?` を保存する書き方でも 0）。
  `quality.sh` も `mutate-check.sh` も終了コードだけを見るので、テストが途中で止まっても「成功」「通った」と判定される
  （`test-gate-record.sh` の承認テストで、変異が「NOT CAUGHT: tests passed」と出て発覚）。テストの最後で `DONE=1` を立て、トラップは `DONE` が 1 でなければ
  明示的に `exit 1` する（`test-gate-record.sh`・`test-gate-turn.sh` 参照）。新しい `set -e` + EXIT トラップ付きテストにも同じ完了マーカーを付ける。
  ミューテーションで `ok()` に `$WORK）` のような展開を仕込み、完了マーカーが異常終了を非ゼロにすることまで確かめる。
  **限界**: 終了コードを消費する形のコマンド置換（`out=$(f) || RC=$?`・`if out=$(f)`。`run_gr` がこの形）の中で起きた致命的エラーは、サブシェルが落ちるだけで本体は続くため、完了マーカーでは検出できない（アサーションで出力を検査する。`set -e` 下で単独の代入文として書いた `out=$(f)` なら本体が止まり、完了マーカーが検出する）
- シェルのテスト（`test-*.sh`）の変異は `mutate-check.sh --failure-line '^FAIL '` で確かめ、`--expect` にはテスト名ではなく FAIL 行の文言の一部を渡す
  （違うと、狙ったテストが落ちても NOT CAUGHT BY THE NAMED TEST になる。PR #129）。未コミットの修正には `--allow-dirty` を付ける
- **数値オプションは `^[0-9]+$` の検証だけでは足りない**。先頭が 0 の値（`0600`）は後続の `$(( ))` と子スクリプトで 8 進数として解釈される（`0600` は 384、`0900` は `value too great for base` で算術エラー）。検証後に `n=$((10#$n))` で基数 10 に正規化してから計算・子スクリプトへ渡す（`gate-turn.sh` は対応済み。`bot-wait.sh`/`ci-wait.sh` を直接呼ぶ場合は未対応で backlog、PR #66 G2-2）
- **空になりうる変数でパスを組み立てない**。`TMP=$(mktemp -d)` が失敗したまま進むと `$TMP/ci.out` が `/ci.out`（ルート直下）になる。`set -e` を使わないスクリプトは `if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then …; exit 3; fi` で止める（PR #66 G1）。macOS の `mktemp -d` は `TMPDIR` を見ないので、テストでは PATH 上のスタブで失敗させる
- **`bot-wait.sh` はレビュー・「Didn't find any major issues」・👍 を提出時刻（T より後）だけで数え、`commit_id` を照合しない**（commit を照合するのは Codex のまとめのコメントだけ。issue #128）。T を CI 待ちより前に取る呼び出し（`gate-turn.sh --first`）は、待つ間に PR の HEAD が動くと旧 HEAD への自動レビューを応答と誤認する。CI 待ちの前後で HEAD を比べ、動いていたら待たずに終える（HEAD は T より先に読む。PR #66 G2-1）
- **bot の応答・API の書き込みの結果は、知っている成功の形を肯定形で確かめる**（アーキテクチャ原則 9 のスクリプト版。PR #129）。`bot-wait.sh` は、まとめ以外の Codex のコメントをすべて応答と数えていた。
  そのため PR の本文の `@codex` に付いた環境作成の定型コメントで DONE になり、レビューを待たずに先へ進んだ（R1 で backlog に送った Low がそのまま起きた）。GraphQL の `resolveReviewThread` は、
  成功しても `isResolved` が true とは限らないので、`gate-resolve.sh` は応答の値を確かめて 1 で終わる（G3-B1）。push の直後は PR の `headRefOid` が数秒遅れるので、ローカルの commit が PR の HEAD に
  含まれるかを確かめる処理は、止まるときに再実行を案内する（`gate-record.sh post`）
- Copilot は bash/jq の挙動を誤って断定することがある（`!` が `$?` を反転して `rc` が常に 0、`index([$x])` が要素を見つけない、など。PR #66 G3-1・PR #68 G2）。該当の形が実際にあるかを grep し、`bash -c` / `jq -n` で実測してから判断する（`if f; … else rc=$?` は 3、`if ! f; then rc=$?` は 0）。この誤りは自分でも書きやすい（PR #129 R2 で `if ! summary=$(…); then rc=$?` と書き、失敗の終了コードが常に 0 になった。G1 で Copilot が指摘）。既存テストが指摘の挙動を既に否定していないかも確認する。**主張が誤りでも、指摘が突いたテストの弱点は直す**
- **人が手で編集するファイル（`G<n>.md` など）を読むパーサーは、読めなかったものを黙って捨てず、必須の項目が欠けたら止める**。`gate-record.sh` は 3 ラウンドで、スレッド欄の欠落・階層違いの見出し・空の要旨・空の検証欄・別 PR の記録（`--file`）・閉じていないコメント／フェンスが、いずれも「通って何かを黙って落とす／別の指摘を上書きする」穴として指摘された（PR #68）。見出しに見える行は形式が違えばエラー、識別子（PR 番号・ラウンド）は引数と照合、値は HTML コメントを除いた後の文字列から取る
- **Perl の裸の `\p{Han}`・`\p{Hiragana}` は Script_Extensions の意味（5.26+）で、`。` `、`（U+3001/3002）にも一致する**。「判定語の直後に漢字・かなが続かない」境界には `\p{Script=Han}` を使う（`判定: 修正。…` が「不明な判定」として拒否された。フィクスチャを `**修正**。` と太字で書いていたため、実際に使うまでテストが通っていた。入力の書式はフィクスチャにも実際の書き方で入れる）
- **bash 3.2（macOS 標準）は `$(cat <<'X' … X)` の中の引用符・バッククォートを誤解釈してパースに失敗する**。埋め込みの perl/awk は別ファイルに切り出す（`gate-record-parse.pl`）
- **重複除去・集計のテストは、値が全て同一のフィクスチャだと部分一致で素通りする**（全 sha が同一で、畳み込みを外しても `- 対応コミット: \`sha\`` の部分一致が通った。PR #68 G2）。行全体の一致（`grep -x`）にして、異なる値を混ぜたケースを足す。複数スレッドを持つ指摘の件数も、修正側だけでなく保留側でも検証する（ミューテーションで未検出になって発覚）
- **手編集される記録の「値」を読むときは、許可リスト・最初の 1 つ・重複拒否の 3 点で fail-closed にする**（PR #81・#82 で、承認行と `対象 HEAD` の読み方を 5 ラウンド連続で指摘された）。(1) 値の直後は行末・空白・括弧など**許可する区切りだけ**を許し、`Z-junk`・`abc1234!` のように記号が直接続く値は読めないものとして扱う。否定形（英数字でなければ可）は次の穴を生む。括弧の補足は開きと閉じの種類を対応させる。(2) 同名のラベルが 1 行に複数あるときは、正規表現の全走査で後ろを拾わず、**最初のラベルの直後だけ**を検証する（不正なら空に倒す）。(3) 同名の行がヘッダに複数ある記録は、先頭だけ読まず曖昧として拒否する（先頭の古い `auto-commit` が後ろの時刻承認を隠す）。文法を厳しくするときは、既存の記録を全件パースして読み取りが変わらないことを確認する（`対象 HEAD: fa7b40b（…）` のように、括弧の補足を付けた既存の書式を壊さないため）
- **実データを書く・消す example（`examples/**`）は、最初に「mock が登録され、OAuth トークンが無い」を肯定形で確認してから始める**（`AdapterRegistry::get( $platform ) instanceof MockPlatformAdapter`）。「実アダプタでなければ可」と否定形で書くと、mock を uninstall した後の未登録 key が素通りする（PR #81 G2-3・G3-1）。開始前の残り検査と cleanup の `left` は、cleanup が消すもの（intent・mapping・job・ログに加え、example が書く seed のキー・platform 単位のオプション〔サンプル・レート制限〕）と同じ範囲を数える（片方だけだと、別の検証の途中の状態を上書き・削除する／`delete_option()` の失敗を「完全に戻った」と報告する。PR #88 G1-1・G2-2）。共有オプションは丸ごと上書き・削除せず、自分が書くキーだけを差し替える。**cleanup は子の行（ジョブに紐づくログ・dry-run 明細）を親（ジョブ）より先に消す**（親を先に消すと、間で止まったとき次回は親の ID から辿れず子が残る。PR #88 G3-1）
- **オプションの削除・書込みが効いたかは、同じリクエストの `get_option()` では確かめられないことがある**: `delete_option()` は DB の削除に失敗しても
  キャッシュを消して notoptions に載せる（`wp-includes/option.php`）ので、`get_option()` は「無い」と答える。cleanup の `left` は `$wpdb->options` を直接数える
  （`push-intent-resolution/cleanup.php`。PR #136 G3-B1）。`update_option()` は失敗するとキャッシュを変えないので、書込みの確認は `get_option()` の読み直しでよい
  （戻り値は同じ値でも false なので使わない。同 G2-B1）
- **example の判定は dev サイトの既存データに隠されないようにする**。集計（totals）だけで判定すると、既存商品の結果が混ざって自分が作った実体の退行を見逃す。作った実体ごとに dry-run の明細（export 方向は `existing_local_id`）で確かめる。特定の実体だけを作成経路に通したい example は、ほかの export できる実体を先に仮の remote_id で結んで更新経路に回す（`examples/partial-push/`。R3-6a より前は無料版のサンプルを自分の ID に固定していた。PR #88 G1-2・G2-1）
- **WP-CLI（`wp eval-file`）でジョブや Writer を動かす検証は、本番の実行条件を明示的に再現する**（R3-1）。WP-CLI は `--user` が無いと `init` の優先度 11 に
  `kses_remove_filters` を登録して kses を外す（実測。`--user` を付けると登録しない）ので、未ログインのままでも kses はかからず、既に 0 のユーザーに `wp_set_current_user( 0 )` しても
  `set_current_user` が発火しないので戻らない。`wp action-scheduler run` で処理したジョブも同じ理由で kses がかからない。WP-Cron（未ログインの HTTP）と同じ条件は
  `wp_set_current_user( 0 ); kses_init_filters();`、戻すときは `kses_remove_filters()`（`rehearse-colorme` の `run.php` の `context=cron|admin`）。
  管理者のまま処理した最初のリハーサルは、商品名の `&`・`<…>` が変わる問題（issue #99）を隠していた。
  **PHP の致命的エラーも WP-CLI と Web の要求で違う**: WordPress の致命的エラーハンドラーは `'exit' => false` で `wp_die()` を呼び、Web の要求ではその後に `shutdown` アクション
  （Action Scheduler の `FatalErrorMonitor` → `action_scheduler_unexpected_shutdown`）が走るが、WP-CLI の `wp_die` のハンドラーはそこで終了するので走らない。`wp action-scheduler run` で
  fatal を起こして「フックが走らない」と結論しない。Web の要求の挙動は、Web の要求の中でキューを動かす一時の mu-plugin で測る（R3-9 R1-L1: WP-CLI だけで測り、「既定の設定では走らない」と docs に誤って書いた）
- **Action Scheduler の claim をグループで絞らない**。移行途中の `ActionScheduler_HybridStore::stake_claim()` は旧ストア（`wpPostStore`）にも問い合わせ、そのグループの term が無いと
  `InvalidArgumentException: The group "…" does not exist` を投げる（実測）。フック名だけで claim し、アクションの引数（job_id）を照合する。他の run のアクションは、そのジョブが開いていれば `unclaim_action()` で手放し、
  閉じている（完了・失敗・キャンセル）か存在しなければ処理して片付ける（キャンセルした run のアクションはキューに残るので、手放すだけだと毎回それを claim して自分の番が来ない。
  `rehearse-colorme` の `run.php`）。claim を取ってから `ActionScheduler::runner()->process_action()` で処理すると、管理画面を開いたままでも WP-Cron／非同期ランナーと二重に処理しない
- **検証スクリプトの判定は、文字列の有無・出現回数で推測せず、独立に作った期待値と完全一致で比べる**（PR #106: `check-import` の script/style の残りの判定を `str_contains` → 出現回数 → 完全一致と作り直し、
  Copilot に G1〜G3 の 3 ラウンド指摘された）。推測の判定は、本文に同じ文字列がある正しい取込みを失敗にし、前後がつながった漏れ（`a<script>ab</script>b` → `aabb`）を見逃す。
  期待値は対象のコードと別の実装（WP の HTML API `WP_HTML_Tag_Processor` など）で作り、保存時の変換（kses の再適用・`wp_unslash()`）も両側にそろえてから比べる
  （`rehearse-colorme` の `_lib.php` の `cbjp_rh_script_style_check()`）。WC の値から期待値を作るときは、WC が値を選ぶ規則まで書き写す（税率は全行の合計ではなく、優先度ごとに最も具体的な 1 行と
  複合税率の上乗せ。`cbjp_rh_jp_reduced_classes()`。PR #107 G1-1）
- **開発サイトの状態を書き換える道具（`rehearse-colorme` の `tax-classes`・`reset-local` など）は、書き換える前にすべてを確かめてから始める**（PR #107 で G1-3・G2-B1・G3-1 と 1 項目ずつ指摘された）。
  (1) 進行中のジョブに加え、処理中の `cbjp_process_job` アクション（キャンセル直後のページがまだ書いている）を拒否する（`reset-local.php` と同じ確認）。
  (2) 複数の対を順に移す処理は、途中で止めて半分だけ移った状態を残さないよう、最初に全部の対の前提（移し先が空か・消えた移し元をまだ指す参照が無いか）を確かめる。
  (3) WC の API が黙って値を置き換える箇所（`WC_Tax::_update_tax_rate()` は一覧に無いスラッグを `''` にする）の後は、移した件数を移し先で数え直してから先へ進む
- **リリースを止める検査は、テストだけでなく zip を作るスクリプトにも置く**。タグの push で走る release の workflow（`release.yml`・`release-pro.yml`）は CI のテストを通らないので、
  PHPUnit だけで止めている条件（Pro の版 1.0.0 以上での接続先・製品 ID・`Update URI`）は、未設定のままでもタグから公開できてしまう（PR #125 G1-1）。`bin/build-zip.sh` から呼ぶ検査
  （`bin/check-pro-zip.php`）に同じ規則を置き、テストも同じ関数で読む。テストから `require` する CLI のスクリプトは、本体を `realpath( $argv[0] ) === __FILE__` のときだけ実行する
- **PHP のソースから宣言・設定（名前空間・`define()` の既定値）を読む検査は、正規表現・grep ではなく `token_get_all( $src, TOKEN_PARSE )` で読む**。正規表現は、コメントアウトした `define()`・
  複数行に分けた `namespace` の宣言・式の値を読み違え、フェイルクローズのはずの検査を通す（PR #125 で G1-B1・G2-B2・G3-B1 と 3 ラウンド続けて指摘された）。読めない形（同じ名前の定義が 2 つ・
  値や名前が式・メソッドの `define`）は「未設定」として止め、ほかのリテラルの定義を拾わない（`bin/check-pro-zip.php` の `cbjp_zipcheck_define()`）
- **外部のパーサーと同じ判定をする検査（wordpress.org の readme の節・ヘッダーなど）は、似た正規表現で近づけて書かない**。そのパーサーのソースの規則を書き写し、元のパーサーを同じ入力で動かして突き合わせる。
  `bin/readme-release.php` は最初、`== X ==` の形だけを節の見出しにしたため、wordpress.org が見出しとして扱う行頭の空白・閉じの無い `== X`・`## X` を Changelog の続きとして読み、
  Upgrade Notice の `= 1.0.0 =` を changelog の節として通した（フェイルオープン。R3-4a R1-1）。R2 で Plugin Check 同梱の `includes/Lib/Readme/Parser.php` を WP 関数のスタブ付きでホストの PHP から動かして比べると、
  行の区切り（UTF-8 なら `\R` を Unicode で分ける）・`empty()` の空の行（`0` だけの行も空）・`##` だけの行の差がさらに見つかった
- **`npx wp-env run` は中のコマンドの終了コードを 1 にまとめる**（`exit( 3 )` も 1）。PHP の結果の種類は出力の印（`missing tables:`・`PRODUCT_ID=`・`LEFTOVERS=`）で見分ける。
  `set -o pipefail` の下で `printf '%s\n' "$大きな出力" | grep -q …` は、grep が先に閉じると printf が SIGPIPE になり、正しい入力でもパイプが失敗しうる。文字列は `case "$out"$'\n' in *"…"$'\n'*)` で照合する（`measure-api-manager` の zip の一覧。PR #127 G3-1）
- **Plugin Check の CLI（2.1.0）はディレクトリのパスと `--slug=` を受ける**（`is_dir()` → `Plugin_Request_Utility::is_directory_valid_plugin()`。1.1.0+）。配布 zip の検査は `wp-content/plugins/` に別名で置かず、
  ルートのマウント（`wp-content/cbjp-dev`）の下に展開してパスで渡す（別名だとフォルダ名由来の `WordPress.WP.I18n.TextDomainMismatch`・`textdomain_mismatch` が混ざる）。結果は `--format=strict-json --fields=file,line,…` で受ける
  （既定の fields に `file` が無く、`json` は `FILE:` の行が混ざる）。見つかったものが無いと JSON ではなく `Success: Checks complete. No errors found.` の行になり、終了コードは ERROR があっても 0。
  コンテナの中でファイルに受けて jq で読み、読めない形・知らない種類は「確かめられない」として止める（`bin/plugin-check-zip.sh`）。
  `--ignore-errors`・`--ignore-warnings` は `empty( $assoc_args[…] )` で見るので `--ignore-errors=false` でも隠す（WP-CLI は `false` を文字列のまま渡す。`=0`・`=`・`--no-ignore-errors` は隠さない。実測）。
  渡さない引数を `case` で拒否するときは `--flag | --flag=*` と値付きの形まで書く（`--ignore-errors` だけだと `--ignore-errors=true` が素通りする。PR #141 Codex）
- **zip を展開するスクリプトは、展開の前に `unzip -Z1` で名前を検査する**（絶対パス・`..` の要素・最上位が 1 つか）。`unzip` は展開先の外へ出る名前を黙って飛ばし、
  シンボリックリンクはそのまま作る（展開後に `find -type l` で拒否する）。作業ディレクトリは実行ごとに `mktemp -d dist/<name>.XXXXXX` で作り、消すのは自分のものだけにする
  （共有の固定名だと、同時に回した別の実行の展開物・結果を消す。PR #141 Codex）
- **中断で壊れると復旧できないファイル**（mu-plugin・追跡しているファイル・状態ファイル）**はその場で書き戻さない**（`cat tmp > file`・`file_put_contents( $path )` は途中で止まると空や途中までのファイルを残す。
  読みかけの mu-plugin は dev サイトの全要求を fatal にし、teardown も動かなくなる）。同じディレクトリの一時ファイル（`cp -p` で権限を引き継ぐ。mu-plugins の中では拡張子を `.php` にしない。WordPress が読み込むため）に書き、
  中身（目印・`php -l`）を確かめてから `mv -f`／`rename()` で置き換える。中断で残った一時ファイルは後片付けが消す（PR #127 G1-5・G2-3・G3-3・G3-4）。
  一時ファイルの名前は呼び出しごとに `mktemp`（同じディレクトリ・`.<名前>.XXXXXX`）で作る。固定名だと、同時に走った別の呼び出しの中身を検査して置き、残しておいた目印の無いファイルも上書きする
  （PR #143 Codex: 固定名で 6 本同時に置くと 5〜6 本が失敗した）。後片付けは名前の頭（`.<名前>.*`）で探し、目印つきだけを消す。`mktemp` は 0600 で作るので、コンテナが読むファイル（mu-plugin・`wp eval-file` に渡す PHP）は 644 にする
  （wp-env は cli・apache をホストの uid で動かすので通常は読めるが、rootless Docker・userns-remap では読めない。PR #143 Codex）
- **`set -e` の下でヒアドキュメントの書き込み（`cat > "$tmp" <<'PHP'`）が失敗すると、一時ファイルを残したまま終わる**（名前が毎回違うと溜まる）。`cat > "$tmp" <<'PHP' || { rm -f "$tmp"; …; exit 1; }` と書く。
  何かを置いた後の確認が失敗したときは、置いたものが残っていることと外し方を必ず出す（黙って非ゼロで終わると、置かれていないと読まれる。`verify-with-mock-adapter` の `pro-unlock`。PR #143 Codex）
- **dev サイトにデータを作る道具は、後片付けの漏れと中断を前提に作る**（`measure-api-manager`。PR #127 の R1〜G3 で、ほぼすべての指摘がこの形だった）。
  (1) 状態ファイルを DB とトークン（option と状態ファイルの両方。`random_bytes()`）で結び付け、一致しなければ消さない（DB を作り直した後の古い状態ファイルで、確かめていない DB を消さない）。
  (2) 保存してから ID を記録するまでの間の中断に備え、目印（meta・ログイン名）を最初の保存で一緒に書き、後片付けは記録に無い目印つきのものも回収する。
  (3) 消し残しを数える範囲と消す範囲は同じ関数にする。消した後は読み直して確かめる（`update()` の 0 は失敗ではない。値を読み直す）。
  (4) 実装中に一度、setup の前と teardown の後で DB を丸ごと（全テーブルの行数・option・transient・cron・予定・ファイルの一覧）比べ、数え方の漏れを探す（WC が副作用で積む属性の索引の予定と、注文のメールのログが見つかった）
- **PHP は関数をファイルの読み込み時に宣言する**ので、先頭の `if ( function_exists( 'x' ) ) { return; }` の守りは初回の読み込みから真になり、後ろの `const` の定義を飛ばす（`Undefined constant`）。
  定数を持つ補助ファイルは守りを書かず、`require_once` だけで読む（`measure-api-manager` の `php/anon.php`）
- **GitHub Actions のワークフロー**（R3-4a の `deploy-wporg.yml`）: (1) 入力・タグ名は `env:` で渡し、`run:` に `${{ }}` で埋め込まない。
  (2) secrets を使う step は `npm ci`（依存のスクリプトが動く）と同じジョブに置かない（同じジョブの前の step は、取得済みの action のスクリプトを書き換えて資格情報を読める）。
  secrets の無いジョブで作った物を artifact で渡し（v4 は既定でドットで始まるファイルを落とすので `include-hidden-files: true`）、checkout は `persist-credentials: false`。
  (3) Environment の「デプロイできるブランチ・タグ」の制限は、ワークフローを実行した ref に掛かり、入力で選んで checkout したタグには掛からない（タグだけを許すと main からの手動実行が拒否される。R3-4a R2-3）。
  (4) `deploy-wporg.yml` は `.wordpress-org/` の assets をタグの commit から取り、10up の action は SVN に `tags/<版>` が既にあると assets も送らずに終わる。画像を変えたら次の版のタグで送る（手動実行では届かない。PR #148）
