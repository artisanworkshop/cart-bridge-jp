---
paths:
  - ".claude/skills/**/scripts/**"
  - ".claude/skills/**/templates/**"
---

# `.claude/skills/` 配下のスクリプト・テンプレートの落とし穴

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
  オプション等から読む値は型を確認し、想定外の値は読み飛ばす（`templates/mu-plugin-mock-adapter.php` の `$rows()` 参照）
- **bot レビュー本文の整形（`gate-bodies.sh`）は、本文の形式が変わっても指摘を握りつぶさない側に倒す**。Copilot の本文は旧形式（`Suppressed comments`）と新形式（`ccr-overview-v2`: `Open`/`Previously missed`/`What changed in this PR` の `<details>`）が混在し、`Previously missed` はスレッドの無い新規指摘なのに旧版は整形出力に出さず、`--raw` で読まなければ見落とすところだった（PR #61 G2）。未知の `<details>` 節は出す側に倒し、ノイズと分かっている節（ファイル要約の表）だけ明示的に除外する。整形ロジックの回帰テストは `scripts/test-gate-bodies.sh`（`--format` に fixtures の本文を流す。ネットワーク不要。`quality.sh` と CI の `dev-tooling` ジョブで実行される）
- スクリプトは手元の macOS（bash 3.2・BSD awk）と CI の `dev-tooling` ジョブ（Ubuntu の bash 5・mawk）の**両方で動く**書き方にする。`test-gate-bodies.sh`・`test-gate-turn.sh`・`test-gate-record.sh` は `quality.sh` の末尾と CI で実行される（PR #64・#66・#68）。他の補助スクリプトにテストを足すときも同ジョブに追加する。手元で Ubuntu の挙動を確かめるには `docker run --rm -v "$PWD":/repo:ro -w /repo ubuntu:24.04 bash -c '…'`（`jq perl git` を入れ、`git config --global --add safe.directory /repo`）
- **bash のダブルクォート内で `$変数` の直後に全角文字（`（` など）を置かない**（`$RC（期待…）` は `unbound variable` で `set -u` 下のスクリプトを FAIL も出さずに異常終了させる。`${RC}` と書く）。テスト自身のメッセージで起きやすく、`test-gate-turn.sh` とフックのテストで実際に踏んだ。ミューテーションで「FAIL が明示的に報告されること」まで確かめると気付ける
- **数値オプションは `^[0-9]+$` の検証だけでは足りない**。先頭が 0 の値（`0600`）は後続の `$(( ))` と子スクリプトで 8 進数として解釈される（`0600` は 384、`0900` は `value too great for base` で算術エラー）。検証後に `n=$((10#$n))` で基数 10 に正規化してから計算・子スクリプトへ渡す（`gate-turn.sh` は対応済み。`bot-wait.sh`/`ci-wait.sh` を直接呼ぶ場合は未対応で backlog、PR #66 G2-2）
- **空になりうる変数でパスを組み立てない**。`TMP=$(mktemp -d)` が失敗したまま進むと `$TMP/ci.out` が `/ci.out`（ルート直下）になる。`set -e` を使わないスクリプトは `if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then …; exit 3; fi` で止める（PR #66 G1）。macOS の `mktemp -d` は `TMPDIR` を見ないので、テストでは PATH 上のスタブで失敗させる
- **`bot-wait.sh` は応答を提出時刻（`submitted_at > T`）だけで判定し、`commit_id` を照合しない**。T を CI 待ちより前に取る呼び出し（`gate-turn.sh --first`）は、待つ間に PR の HEAD が動くと旧 HEAD への自動レビューを応答と誤認する。CI 待ちの前後で HEAD を比べ、動いていたら待たずに終える（HEAD は T より先に読む。PR #66 G2-1）
- Copilot は bash/jq の挙動を誤って断定することがある（`!` が `$?` を反転して `rc` が常に 0、`index([$x])` が要素を見つけない、など。PR #66 G3-1・PR #68 G2）。該当の形が実際にあるかを grep し、`bash -c` / `jq -n` で実測してから判断する（`if f; … else rc=$?` は 3、`if ! f; then rc=$?` は 0）。既存テストが指摘の挙動を既に否定していないかも確認する。**主張が誤りでも、指摘が突いたテストの弱点は直す**
- **人が手で編集するファイル（`G<n>.md` など）を読むパーサーは、読めなかったものを黙って捨てず、必須の項目が欠けたら止める**。`gate-record.sh` は 3 ラウンドで、スレッド欄の欠落・階層違いの見出し・空の要旨・空の検証欄・別 PR の記録（`--file`）・閉じていないコメント／フェンスが、いずれも「通って何かを黙って落とす／別の指摘を上書きする」穴として指摘された（PR #68）。見出しに見える行は形式が違えばエラー、識別子（PR 番号・ラウンド）は引数と照合、値は HTML コメントを除いた後の文字列から取る
- **Perl の裸の `\p{Han}`・`\p{Hiragana}` は Script_Extensions の意味（5.26+）で、`。` `、`（U+3001/3002）にも一致する**。「判定語の直後に漢字・かなが続かない」境界には `\p{Script=Han}` を使う（`判定: 修正。…` が「不明な判定」として拒否された。フィクスチャを `**修正**。` と太字で書いていたため、実際に使うまでテストが通っていた。入力の書式はフィクスチャにも実際の書き方で入れる）
- **bash 3.2（macOS 標準）は `$(cat <<'X' … X)` の中の引用符・バッククォートを誤解釈してパースに失敗する**。埋め込みの perl/awk は別ファイルに切り出す（`gate-record-parse.pl`）
- **重複除去・集計のテストは、値が全て同一のフィクスチャだと部分一致で素通りする**（全 sha が同一で、畳み込みを外しても `- 対応コミット: \`sha\`` の部分一致が通った。PR #68 G2）。行全体の一致（`grep -x`）にして、異なる値を混ぜたケースを足す。複数スレッドを持つ指摘の件数も、修正側だけでなく保留側でも検証する（ミューテーションで未検出になって発覚）
