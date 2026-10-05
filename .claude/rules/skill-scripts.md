---
paths:
  - ".claude/skills/**/scripts/**"
  - ".claude/skills/**/templates/**"
  - ".claude/skills/**/examples/**"
  - ".claude/skills/**/php/**"
---

# `.claude/skills/` 配下のスクリプト・テンプレート・example の落とし穴

`ci-wait.sh`・`bot-wait.sh`・`mock-adapter.sh` などの開発補助スクリプトが対象（プラグイン本体ではない）。
PR #50 では同種の指摘を Copilot・Codex から計 4 ラウンド受けた。

- **判定の前提になる取得（PR の HEAD・ブランチ名・存在確認など）を `|| true` で握りつぶさない**。一時的な API 失敗が
  「変化なし」「ガード不要」に化けて、古い HEAD のチェック結果を成功と報告する（`ci-wait.sh` の最終 HEAD 取得と起動時の
  ブランチ名取得で連続して指摘された）。既存の失敗ポリシー（`MAX_API_FAILS` 回まで再試行し、超えたら非ゼロで終了）で扱う。
  `|| true` を付けてよいのは、失敗しても結論が変わらない取得だけ（アーキテクチャ原則 9「フェイルクローズ」のスクリプト版）
- **`set -e` 下で `out=$(cmd)` を単独の代入文にしない**。`cmd` が失敗した時点でスクリプトごと終了し、次行で出力を表示する前に
  エラー本文が消える（終了コードだけが残る）。`if out=$(cmd); then rc=0; else rc=$?; fi` で受けてから出力し、`return "$rc"` する
  （`mock-adapter.sh` の `wp()` 参照）。新しく書く**テストスクリプトにも同じ規約を適用する**（`test-gate-bodies.sh` の `load` ヘルパー参照。PR #64 で、この規約を追加したばかりの PR のテスト自身が違反し、Codex に P1 で指摘された）
- **mu-plugin テンプレートは致命的エラーを出さない**。mu-plugin の fatal は開発サイトの全リクエストと、WP を起動する
  `mock-adapter.sh run`/`inspect`（＝ seed の cleanup）を落とす（`uninstall` はホスト側の `rm` なので効く）。
  オプション等から読む値は型を確認し、想定外の値は読み飛ばす（`templates/mu-plugin-mock-adapter.php` の `$rows()` 参照）。
  新しい読み取りを `$rows()` の外へ足すときも `is_array( $seed ) && is_array( $seed['k'] ?? null )` の二段階にする
  （`$seed['k'] ?? null` だけでは `$seed` が stdClass のとき Error。PR #80 G3-2 で Copilot が High として指摘）
- **bot レビュー本文の整形（`gate-bodies.sh`）は、本文の形式が変わっても指摘を握りつぶさない側に倒す**。Copilot の本文は旧形式（`Suppressed comments`）と新形式（`ccr-overview-v2`: `Open`/`Previously missed`/`What changed in this PR` の `<details>`）が混在し、`Previously missed` はスレッドの無い新規指摘なのに旧版は整形出力に出さず、`--raw` で読まなければ見落とすところだった（PR #61 G2）。未知の `<details>` 節は出す側に倒し、ノイズと分かっている節（ファイル要約の表）だけ明示的に除外する。整形ロジックの回帰テストは `scripts/test-gate-bodies.sh`（`--format` に fixtures の本文を流す。ネットワーク不要。`quality.sh` と CI の `dev-tooling` ジョブで実行される）
- スクリプトは手元の macOS（bash 3.2・BSD awk）と CI の `dev-tooling` ジョブ（Ubuntu の bash 5・mawk）の**両方で動く**書き方にする。`test-gate-bodies.sh`・`test-gate-turn.sh`・`test-gate-record.sh` は `quality.sh` の末尾と CI で実行される（PR #64・#66・#68）。他の補助スクリプトにテストを足すときも同ジョブに追加する。手元で Ubuntu の挙動を確かめるには `docker run --rm -v "$PWD":/repo:ro -w /repo ubuntu:24.04 bash -c '…'`（`jq perl git` を入れ、`git config --global --add safe.directory /repo`）
- **bash のダブルクォート内で `$変数` の直後に全角文字（`（` など）を置かない**（`$RC（期待…）` は `unbound variable` で `set -u` 下のスクリプトを FAIL も出さずに異常終了させる。`${RC}` と書く）。テスト自身のメッセージで起きやすく、`test-gate-turn.sh` とフックのテストで実際に踏んだ。ミューテーションで「FAIL が明示的に報告されること」まで確かめると気付ける。
  **さらに、`set -e` と `trap … EXIT` の両方を持つテストスクリプトは、macOS の bash 3.2 だとこの異常終了が終了コード 0 になる**（`set -euo pipefail` +
  トラップ〔中身は `:` でも `rm -rf` でも〕=0、トラップ無し=1、`set -uo pipefail`〔`-e` なし〕+ トラップ=1 を実測。`rc=$?` を保存する書き方でも 0）。
  `quality.sh` も `mutate-check.sh` も終了コードだけを見るので、テストが途中で止まっても「成功」「通った」と判定される
  （`test-gate-record.sh` の承認テストで、変異が「NOT CAUGHT: tests passed」と出て発覚）。テストの最後で `DONE=1` を立て、トラップは `DONE` が 1 でなければ
  明示的に `exit 1` する（`test-gate-record.sh`・`test-gate-turn.sh` 参照）。新しい `set -e` + EXIT トラップ付きテストにも同じ完了マーカーを付ける。
  ミューテーションで `ok()` に `$WORK）` のような展開を仕込み、完了マーカーが異常終了を非ゼロにすることまで確かめる。
  **限界**: 終了コードを消費する形のコマンド置換（`out=$(f) || RC=$?`・`if out=$(f)`。`run_gr` がこの形）の中で起きた致命的エラーは、サブシェルが落ちるだけで本体は続くため、完了マーカーでは検出できない（アサーションで出力を検査する。`set -e` 下で単独の代入文として書いた `out=$(f)` なら本体が止まり、完了マーカーが検出する）
- **数値オプションは `^[0-9]+$` の検証だけでは足りない**。先頭が 0 の値（`0600`）は後続の `$(( ))` と子スクリプトで 8 進数として解釈される（`0600` は 384、`0900` は `value too great for base` で算術エラー）。検証後に `n=$((10#$n))` で基数 10 に正規化してから計算・子スクリプトへ渡す（`gate-turn.sh` は対応済み。`bot-wait.sh`/`ci-wait.sh` を直接呼ぶ場合は未対応で backlog、PR #66 G2-2）
- **空になりうる変数でパスを組み立てない**。`TMP=$(mktemp -d)` が失敗したまま進むと `$TMP/ci.out` が `/ci.out`（ルート直下）になる。`set -e` を使わないスクリプトは `if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then …; exit 3; fi` で止める（PR #66 G1）。macOS の `mktemp -d` は `TMPDIR` を見ないので、テストでは PATH 上のスタブで失敗させる
- **`bot-wait.sh` は応答を提出時刻（`submitted_at > T`）だけで判定し、`commit_id` を照合しない**。T を CI 待ちより前に取る呼び出し（`gate-turn.sh --first`）は、待つ間に PR の HEAD が動くと旧 HEAD への自動レビューを応答と誤認する。CI 待ちの前後で HEAD を比べ、動いていたら待たずに終える（HEAD は T より先に読む。PR #66 G2-1）
- Copilot は bash/jq の挙動を誤って断定することがある（`!` が `$?` を反転して `rc` が常に 0、`index([$x])` が要素を見つけない、など。PR #66 G3-1・PR #68 G2）。該当の形が実際にあるかを grep し、`bash -c` / `jq -n` で実測してから判断する（`if f; … else rc=$?` は 3、`if ! f; then rc=$?` は 0）。既存テストが指摘の挙動を既に否定していないかも確認する。**主張が誤りでも、指摘が突いたテストの弱点は直す**
- **人が手で編集するファイル（`G<n>.md` など）を読むパーサーは、読めなかったものを黙って捨てず、必須の項目が欠けたら止める**。`gate-record.sh` は 3 ラウンドで、スレッド欄の欠落・階層違いの見出し・空の要旨・空の検証欄・別 PR の記録（`--file`）・閉じていないコメント／フェンスが、いずれも「通って何かを黙って落とす／別の指摘を上書きする」穴として指摘された（PR #68）。見出しに見える行は形式が違えばエラー、識別子（PR 番号・ラウンド）は引数と照合、値は HTML コメントを除いた後の文字列から取る
- **Perl の裸の `\p{Han}`・`\p{Hiragana}` は Script_Extensions の意味（5.26+）で、`。` `、`（U+3001/3002）にも一致する**。「判定語の直後に漢字・かなが続かない」境界には `\p{Script=Han}` を使う（`判定: 修正。…` が「不明な判定」として拒否された。フィクスチャを `**修正**。` と太字で書いていたため、実際に使うまでテストが通っていた。入力の書式はフィクスチャにも実際の書き方で入れる）
- **bash 3.2（macOS 標準）は `$(cat <<'X' … X)` の中の引用符・バッククォートを誤解釈してパースに失敗する**。埋め込みの perl/awk は別ファイルに切り出す（`gate-record-parse.pl`）
- **重複除去・集計のテストは、値が全て同一のフィクスチャだと部分一致で素通りする**（全 sha が同一で、畳み込みを外しても `- 対応コミット: \`sha\`` の部分一致が通った。PR #68 G2）。行全体の一致（`grep -x`）にして、異なる値を混ぜたケースを足す。複数スレッドを持つ指摘の件数も、修正側だけでなく保留側でも検証する（ミューテーションで未検出になって発覚）
- **手編集される記録の「値」を読むときは、許可リスト・最初の 1 つ・重複拒否の 3 点で fail-closed にする**（PR #81・#82 で、承認行と `対象 HEAD` の読み方を 5 ラウンド連続で指摘された）。(1) 値の直後は行末・空白・括弧など**許可する区切りだけ**を許し、`Z-junk`・`abc1234!` のように記号が直接続く値は読めないものとして扱う。否定形（英数字でなければ可）は次の穴を生む。括弧の補足は開きと閉じの種類を対応させる。(2) 同名のラベルが 1 行に複数あるときは、正規表現の全走査で後ろを拾わず、**最初のラベルの直後だけ**を検証する（不正なら空に倒す）。(3) 同名の行がヘッダに複数ある記録は、先頭だけ読まず曖昧として拒否する（先頭の古い `auto-commit` が後ろの時刻承認を隠す）。文法を厳しくするときは、既存の記録を全件パースして読み取りが変わらないことを確認する（`対象 HEAD: fa7b40b（…）` のように、括弧の補足を付けた既存の書式を壊さないため）
- **実データを書く・消す example（`examples/**`）は、最初に「mock が登録され、OAuth トークンが無い」を肯定形で確認してから始める**（`AdapterRegistry::get( $platform ) instanceof MockPlatformAdapter`）。「実アダプタでなければ可」と否定形で書くと、mock を uninstall した後の未登録 key が素通りする（PR #81 G2-3・G3-1）。開始前の残り検査と cleanup の `left` は、cleanup が消すもの（intent・mapping・job・ログに加え、example が書く seed のキー・platform 単位のオプション〔サンプル・レート制限〕）と同じ範囲を数える（片方だけだと、別の検証の途中の状態を上書き・削除する／`delete_option()` の失敗を「完全に戻った」と報告する。PR #88 G1-1・G2-2）。共有オプションは丸ごと上書き・削除せず、自分が書くキーだけを差し替える。**cleanup は子の行（ジョブに紐づくログ・dry-run 明細）を親（ジョブ）より先に消す**（親を先に消すと、間で止まったとき次回は親の ID から辿れず子が残る。PR #88 G3-1）
- **example の判定は dev サイトの既存データに隠されないようにする**。集計（totals）だけで判定すると、既存商品の結果が混ざって自分が作った実体の退行を見逃す。作った実体ごとに dry-run の明細（export 方向は `existing_local_id`）で確かめる。無料版のサンプル（`cbjp_export_sample_{platform}` 等）は、受注が 10 件以上ある dev サイトでは受注の商品だけで決まる（補充されない）ので、特定の実体を export させたい example はサンプルを自分の ID に固定する（PR #88 G1-2・G2-1。`examples/upsell-notice/` 参照）
- **WP-CLI（`wp eval-file`）でジョブや Writer を動かす検証は、本番の実行条件を明示的に再現する**（R3-1）。WP-CLI は `--user` が無いと `init` の優先度 11 に
  `kses_remove_filters` を登録して kses を外す（実測。`--user` を付けると登録しない）ので、未ログインのままでも kses はかからず、既に 0 のユーザーに `wp_set_current_user( 0 )` しても
  `set_current_user` が発火しないので戻らない。`wp action-scheduler run` で処理したジョブも同じ理由で kses がかからない。WP-Cron（未ログインの HTTP）と同じ条件は
  `wp_set_current_user( 0 ); kses_init_filters();`、戻すときは `kses_remove_filters()`（`rehearse-colorme` の `run.php` の `context=cron|admin`）。
  管理者のまま処理した最初のリハーサルは、商品名の `&`・`<…>` が変わる問題（issue #99）を隠していた
- **Action Scheduler の claim をグループで絞らない**。移行途中の `ActionScheduler_HybridStore::stake_claim()` は旧ストア（`wpPostStore`）にも問い合わせ、そのグループの term が無いと
  `InvalidArgumentException: The group "…" does not exist` を投げる（実測）。フック名だけで claim し、アクションの引数（job_id）を照合して他の run のものは `unclaim_action()` で手放す
  （`rehearse-colorme` の `run.php`）。claim を取ってから `ActionScheduler::runner()->process_action()` で処理すると、管理画面を開いたままでも WP-Cron／非同期ランナーと二重に処理しない
