#!/usr/bin/env bash
# gate-record.sh の回帰テスト（ネットワーク不要）。gh と gate-threads.sh をスタブに差し替え、骨組みの生成・記入漏れの検出・
# サマリと返信の生成を検証する。
# 使い方: .claude/skills/cbj-dev-cycle/scripts/test-gate-record.sh
set -euo pipefail
if ! HERE=$(cd "$(dirname "$0")" && pwd) || [ -z "$HERE" ]; then
  echo "test-gate-record: could not resolve the script directory" >&2
  exit 1
fi
GR="$HERE/gate-record.sh"
FX="$HERE/fixtures/gate-record"
FAILS=0
if ! SHA=$(git -C "$HERE" rev-parse HEAD) || [ -z "$SHA" ]; then
  echo "test-gate-record: git rev-parse HEAD failed (run inside the repository)" >&2
  exit 1
fi
SHA7=${SHA:0:7}

if ! WORK=$(mktemp -d) || [ -z "$WORK" ]; then
  echo "test-gate-record: could not create a temporary directory (mktemp failed)" >&2
  exit 1
fi
trap 'rm -rf "$WORK"' EXIT
GITREPO="$WORK/gitrepo"
GITC=(-c user.name=test -c user.email=test@example.com -c commit.gpgsign=false)
if ! git init -q "$GITREPO" || ! git -C "$GITREPO" "${GITC[@]}" commit -q --allow-empty -m one || ! git -C "$GITREPO" "${GITC[@]}" commit -q --allow-empty -m two; then
  echo "test-gate-record: could not create the scratch git repository" >&2
  exit 1
fi
if ! SHA_B=$(git -C "$GITREPO" rev-parse HEAD) || ! SHA_A=$(git -C "$GITREPO" rev-parse HEAD~1) || [ -z "$SHA_A" ] || [ -z "$SHA_B" ]; then
  echo "test-gate-record: could not read the scratch repository commits" >&2
  exit 1
fi
STUBS="$WORK/stubs"
LOG="$WORK/calls.log"
mkdir -p "$STUBS/bin" "$WORK/root"

# gate-threads.sh: --json で STUB_THREADS_FILE（既定 fixtures の threads.json）を返す。
cat >"$STUBS/gate-threads.sh" <<'EOS'
#!/usr/bin/env bash
echo "gate-threads $*" >> "$STUB_LOG"
if [ "${STUB_RC_THREADS:-0}" -ne 0 ]; then exit "$STUB_RC_THREADS"; fi
cat "${STUB_THREADS_FILE:-$FX/threads.json}"
EOS
chmod +x "$STUBS/gate-threads.sh"

# gh: `pr view --json headRefName` はブランチ名、`pr view --json url,headRefOid` は PR のメタ、`api` はレビュー一覧を返す。
#   STUB_RC_GH は全て、STUB_RC_GH_META / STUB_RC_GH_API はそれぞれ PR のメタ・レビュー一覧の取得だけを失敗させる。
cat >"$STUBS/bin/gh" <<'EOS'
#!/usr/bin/env bash
echo "gh $*" >> "$STUB_LOG"
if [ "${STUB_RC_GH:-0}" -ne 0 ]; then exit "$STUB_RC_GH"; fi
case "$*" in
  *headRefName*) printf '%s\n' "${STUB_BRANCH:-chore/66-example}" ;;
  "pr view"*)
    if [ "${STUB_RC_GH_META:-0}" -ne 0 ]; then exit "$STUB_RC_GH_META"; fi
    printf '%s\n' '{"url":"https://example.com/o/r/pull/66","headRefOid":"1111111111111111111111111111111111111111"}'
    ;;
  api*)
    if [ "${STUB_RC_GH_API:-0}" -ne 0 ]; then exit "$STUB_RC_GH_API"; fi
    cat "${STUB_REVIEWS_FILE:-$FX/reviews.json}"
    ;;
  *) exit 1 ;;
esac
EOS
chmod +x "$STUBS/bin/gh"

# run_gr <環境変数の代入...> -- <gate-record.sh の引数...> : 標準出力を $OUT に、標準エラーを $ERR に、終了コードを $RC に入れる。
run_gr() {
  local envs=()
  while [ "$1" != "--" ]; do envs+=("$1"); shift; done
  shift
  : >"$LOG"
  RC=0
  OUT=$(env CBJ_GATE_SCRIPTS_DIR="$STUBS" CBJ_RECORD_ROOT="$WORK/root" STUB_LOG="$LOG" FX="$FX" PATH="$STUBS/bin:$PATH" "${envs[@]+"${envs[@]}"}" "$GR" "$@" 2>"$WORK/err.txt") || RC=$?
  ERR=$(cat "$WORK/err.txt")
}

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1"; FAILS=$((FAILS + 1)); }
assert_rc() { if [ "$RC" -eq "$2" ]; then ok "$1"; else fail "$1: 終了コード ${RC}（期待 $2）"; fi; }
assert_out() { if grep -qF -- "$2" <<<"$OUT"; then ok "$1"; else fail "$1: 標準出力に見つからない: $2"; fi; }
assert_out_line() { if grep -qxF -- "$2" <<<"$OUT"; then ok "$1"; else fail "$1: 標準出力に完全一致する行が見つからない: $2"; fi; }
assert_err() { if grep -qF -- "$2" <<<"$ERR"; then ok "$1"; else fail "$1: 標準エラーに見つからない: $2"; fi; }
assert_no_out() { if grep -qF -- "$2" <<<"$OUT"; then fail "$1: 標準出力にあってはならない: $2"; else ok "$1"; fi; }
assert_file_has() { if grep -qF -- "$3" "$2"; then ok "$1"; else fail "$1: ファイルに見つからない: $3"; fi; }
assert_log() { if grep -qxF -- "$2" "$LOG"; then ok "$1"; else fail "$1: 呼び出しログに見つからない: $2"; fi; }

# mkrec <名前> [perl の置換式] : sha を埋めた記入済みの記録を $WORK/rec-<名前>.md に作り、必要なら壊す。
mkrec() {
  sed "s/@SHA@/$SHA/g" "$FX/filled.md" >"$WORK/rec-$1.md"
  if [ -n "${2:-}" ]; then perl -0pi -e "$2" "$WORK/rec-$1.md"; fi
}
REC_DIR="$WORK/root/docs/reviews/chore/66-example"

# ---- init ----
run_gr STUB_THREADS_FILE="$FX/init-threads.json" -- init 66 9 --since=2026-09-25T00:00:00Z
assert_rc "init: 終了コード 0" 0
REC="$REC_DIR/G9.md"
if [ -f "$REC" ]; then ok "init: docs/reviews/<branch>/G9.md を作る（ブランチ名のスラッシュは階層）"; else fail "init: 記録ファイルができていない: $REC"; fi
assert_file_has "init: 見出し" "$REC" "# ゲートラウンド G9"
assert_file_has "init: PR と HEAD の短縮 sha" "$REC" "- PR: #66 / 対象 HEAD: 1111111"
assert_file_has "init: Codex のレビューへのリンクと対象 commit" "$REC" "[Codex #9001](https://example.com/o/r/pull/66#pullrequestreview-9001)（対象 1111111）"
assert_file_has "init: Copilot のレビューへのリンクと判定見出しと対象 commit" "$REC" "[Copilot #9002](https://example.com/o/r/pull/66#pullrequestreview-9002)（🟡 Changes recommended、対象 1111111）"
assert_file_has "init --since: 現在の HEAD 以外へのレビューも T 以降なら拾い、対象 commit を併記する" "$REC" "[Copilot #9005](https://example.com/o/r/pull/66#pullrequestreview-9005)（🔵 Needs a closer look、対象 3333333）"
if grep -qE '9003|9004' "$REC"; then fail "init --since: T より前のレビューと人間のレビューを含めてはならない"; else ok "init --since: T より前のレビューと bot 以外のレビューを除く"; fi
assert_file_has "init: Codex の重大度バッジを見出しに入れる" "$REC" "### [G9-1][Codex P1][.claude/skills/x/gate-turn.sh:78]"
assert_file_has "init: 行が無い（outdated）ときは ? を入れる" "$REC" "### [G9-2][Copilot][.claude/skills/x/gate-turn.sh:?]"
assert_file_has "init: スレッドへのリンク（dbid）" "$REC" "スレッド: [r2001](https://example.com/pull/66#discussion_r2001)"
assert_file_has "init: 判断が要る欄は TODO(記入)" "$REC" "判定: TODO(記入)"
assert_file_has "init: 収束表に Codex の新規スレッド数" "$REC" "| Codex | TODO(記入) | 1 | TODO(記入) |"
if grep -B1 -x -- "## 検証" "$REC" | head -n 1 | grep -q .; then fail "init: 最後の指摘と ## 検証 の間に空行が無い"; else ok "init: 指摘の節と ## 検証 の間に空行を入れる"; fi
assert_file_has "init: 収束表に Copilot の新規スレッド数" "$REC" "| Copilot | TODO(記入) | 1 | TODO(記入) |"
if grep -F '原文:' "$REC" | grep -F -- '-->' | grep -qF -- '--&gt;'; then ok "init: 原文中の --> は HTML コメントを壊さないよう無害化する"; else fail "init: 原文コメントの --> が無害化されていない"; fi
if [ "$(grep -F '原文:' "$REC" | head -n 1 | grep -o -- '-->' | wc -l | tr -d ' ')" -eq 1 ]; then ok "init: 原文コメントの終端 --> は 1 つだけ"; else fail "init: 原文コメントの --> が複数ある"; fi
assert_log "init: --since を gate-threads.sh へ渡す" "gate-threads 66 2026-09-25T00:00:00Z --json"

run_gr STUB_THREADS_FILE="$FX/init-threads.json" -- init 66 13
assert_rc "init（--since なし）: 0" 0
assert_file_has "init（--since なし）: 現在の HEAD へのレビューを拾う" "$REC_DIR/G13.md" "[Copilot #9002]"
if grep -qE '9003|9004|9005' "$REC_DIR/G13.md"; then fail "init（--since なし）: 現在の HEAD 以外へのレビュー・bot 以外のレビューを含めてはならない"; else ok "init（--since なし）: 現在の HEAD 以外へのレビューと bot 以外のレビューを除く"; fi
echo '[]' >"$WORK/no-reviews.json"
run_gr STUB_REVIEWS_FILE="$WORK/no-reviews.json" -- init 66 14 --since=2026-09-25T00:00:00Z
assert_file_has "init --since: レビューが無いときは T 以降と示す" "$REC_DIR/G14.md" "なし（2026-09-25T00:00:00Z 以降の bot レビューが見つからない）"
run_gr STUB_REVIEWS_FILE="$WORK/no-reviews.json" -- init 66 15
assert_file_has "init（--since なし）: レビューが無いときは HEAD 名で示す" "$REC_DIR/G15.md" "なし（1111111 への bot レビューが見つからない）"

run_gr -- check 66 9
assert_rc "骨組みのままの check は非ゼロ（記入途中のものを通さない）" 1
assert_err "骨組みのまま: TODO(記入) の残りを行番号つきで報告" "TODO(記入) remains at line(s):"

run_gr -- init 66 9
assert_rc "init: 既存の記録は上書きしない: 2" 2
assert_err "init: 上書きを拒否する理由" "already exists"
run_gr -- init 066 09
assert_rc "init: 先頭 0 の PR・ラウンド番号も同じファイルとして扱う（上書き拒否: 2）" 2
run_gr STUB_THREADS_FILE="$FX/init-threads.json" -- init 66 9 --force
assert_rc "init --force: 上書きできる" 0

run_gr STUB_BRANCH='../evil' -- init 66 10
assert_rc "init: 危険なブランチ名: 3" 3
if [ -e "$WORK/root/docs/reviews/../evil" ] || [ -e "$WORK/root/docs/evil" ]; then fail "init: ルート外へ書いてはならない"; else ok "init: 危険なブランチ名のとき何も書かない"; fi
run_gr STUB_RC_THREADS=1 -- init 66 10
assert_rc "init: gate-threads.sh の失敗: 3" 3
if [ -e "$REC_DIR/G10.md" ]; then fail "init: 取得に失敗したら記録ファイルを作ってはならない"; else ok "init: 取得に失敗したら記録ファイルを作らない"; fi
run_gr STUB_RC_GH=1 -- init 66 10
assert_rc "init: gh の失敗: 3" 3
run_gr STUB_RC_GH_META=1 -- init 66 10
assert_rc "init: PR のメタ（url・head）を取得できない: 3" 3
run_gr STUB_RC_GH_API=1 -- init 66 10
assert_rc "init: レビュー一覧を取得できない: 3" 3
if [ -e "$REC_DIR/G10.md" ]; then fail "init: レビュー一覧の取得に失敗しても記録ファイルができている"; else ok "init: レビュー一覧・メタの取得に失敗したら記録ファイルを作らない"; fi
echo '[]' >"$WORK/no-threads.json"
run_gr STUB_THREADS_FILE="$WORK/no-threads.json" -- init 66 11
assert_rc "init: スレッドが無くても骨組みを作る: 0" 0
assert_file_has "init: スレッド無しのときは本文指摘を手で足す案内" "$REC_DIR/G11.md" "未解決のボットスレッドはありません"
run_gr -- init 66 12 --since=yesterday
assert_rc "init: --since の形式不正: 2" 2

# ---- 記入済みの記録: check / summary / replies ----
mkrec ok
run_gr -- check 66 9 --file="$WORK/rec-ok.md"
assert_rc "check: 記入済みは 0" 0
assert_out "check: 件数の内訳" "OK: 6 findings (修正 3 / 保留 1 / 対応不要 2)"

run_gr -- summary 66 9 --file="$WORK/rec-ok.md"
assert_rc "summary: 終了コード 0" 0
assert_out "summary: 見出し" "## レビュー指摘への対応サマリ（G9）"
assert_out "summary: 記録の先頭の箇条書きをそのまま入れる（レビューへのリンク）" "[Codex #1](https://example.com/pull/64#pullrequestreview-1)"
assert_out_line "summary: 対応コミットは重複を畳んで 1 件（同じ sha が 4 か所に書いてあっても、行全体が一致する）" "- 対応コミット: \`${SHA7}\`"
sed "s/@SHA@/$SHA_A/g" "$FX/filled.md" >"$WORK/rec-2c.md"
perl -0pi -e 's/(### \[G9-B1\].*?コミット: )[0-9a-f]{40}/${1}'"$SHA_B"'/s' "$WORK/rec-2c.md"
run_gr GIT_DIR="$GITREPO/.git" -- summary 66 9 --file="$WORK/rec-2c.md"
assert_rc "summary: 別々の 2 つの commit（別リポジトリの sha）: 0" 0
assert_out_line "summary: 異なる commit は初出の順に並べ、同じ commit は畳む" "- 対応コミット: \`${SHA_A:0:7}\`, \`${SHA_B:0:7}\`"
run_gr -- summary 66 9 --file="$WORK/rec-ok.md"
assert_out "summary: 表の | をエスケープし、複数行の要旨は <br> でつなぐ" '表の \| を含む要旨。<br>2 行目の要旨。'
assert_out "summary: 旧書式の判定と対応（**修正**・**対応:**）も読め、スレッドのリンク列を持つ" "| G9-1 | Codex P1 | \`src/a.sh:10\` | [r1001](https://example.com/pull/64#discussion_r1001) | \`--first\` が旧 HEAD の自動レビューを応答と誤認する。 | **修正**（\`${SHA7}\`）。CI 待ちの前後で HEAD を比べる。 |"
assert_out "summary: 各行にスレッドへのリンク（#discussion_r<dbid>）を入れる" "[r1002](https://example.com/pull/64#discussion_r1002)"
assert_out "summary: 本文指摘（スレッド無し）の行はスレッド欄が「なし」" "| G9-B1 | Copilot | \`src/f.sh:60\` | なし | 本文指摘（スレッド無し）。 |"
mkrec plainlink 's/\[r1005\]\(https:\/\/example.com\/pull\/64#discussion_r1005\)/discussion_r1005/'
run_gr -- check 66 9 --file="$WORK/rec-plainlink.md"
assert_rc "スレッドが URL の無い discussion_r<dbid> だけでも check は通る" 0
run_gr -- summary 66 9 --file="$WORK/rec-plainlink.md"
assert_out "summary: URL の無い参照は r<dbid> の文字だけで表に出す" "| G9-5 | Copilot | \`src/e.sh:50\` | r1005 |"
assert_out "summary: 保留" "**保留**。Low のため backlog へ。"
assert_out "summary: スレッドの Resolve 件数（修正 + 承認済みの対応不要 = 3、保留 + 承認記録なし = 2、本文指摘 = 1）" "- Resolve したスレッド: 3 件 / 未解決のまま残すスレッド（保留・承認の記録がない対応不要）: 2 件 / 本文指摘（スレッド無し。このコメントと G9.md が処理済みの記録）: 1 件"
assert_out "summary: 検証欄をそのまま入れる" "- テスト: 86 項目すべて ok"
assert_no_out "summary: HTML コメント中の TODO(記入) は数えず、出力にも出ない" "TODO(記入)"

OUTDIR="$WORK/out dir"
run_gr -- replies 66 9 --file="$WORK/rec-ok.md" --out="$OUTDIR"
assert_rc "replies: 終了コード 0" 0
assert_log "replies: 未解決スレッドを gate-threads.sh から取る" "gate-threads 66 --json"
assert_out "replies: 修正のスレッドは返信のあと Resolve（dbid → threadId）" "gate-reply.sh 66 1001 - < "
assert_out "replies: 返信ファイルのパスは %q で引用する（空白を含む出力先）" 'out\ dir/reply-G9-1.md'
assert_out "replies: G9-1 を Resolve" "gate-resolve.sh PRRT_1"
assert_out "replies: 承認済みの対応不要は Resolve" "gate-resolve.sh PRRT_3"
assert_no_out "replies: 承認の記録がない対応不要は Resolve しない" "gate-resolve.sh PRRT_4"
assert_no_out "replies: 保留は Resolve しない" "gate-resolve.sh PRRT_5"
assert_out "replies: 承認の記録がない対応不要は返信だけ" "gate-reply.sh 66 1004 - < "
assert_err "replies: 承認の記録がない対応不要は警告する" "G9-4 is 対応不要 without 【承認済み】"
assert_out "replies: 保留は返信だけ" "gate-reply.sh 66 1005 - < "
assert_out "replies: 本文指摘は返信・Resolve の対象外と示す" "# G9-B1: 本文指摘（スレッド無し）"
if [ "$(grep -c 'gate-resolve.sh' <<<"$OUT")" -eq 3 ]; then ok "replies: Resolve のコマンドは 3 件だけ"; else fail "replies: Resolve のコマンド数が違う: $(grep -c 'gate-resolve.sh' <<<"$OUT")"; fi
if [ "$(grep -c 'gate-reply.sh' <<<"$OUT")" -eq 5 ]; then ok "replies: 返信のコマンドはスレッドのある 5 件だけ"; else fail "replies: 返信のコマンド数が違う: $(grep -c 'gate-reply.sh' <<<"$OUT")"; fi
assert_file_has "replies: 修正の返信ファイルは sha つきの書き出し" "$OUTDIR/reply-G9-2.md" "ご指摘のとおりです（${SHA7} で修正）。"
assert_file_has "replies: 複数行の対応を保つ" "$OUTDIR/reply-G9-2.md" "- 箇条書き B"
assert_file_has "replies: 保留の返信ファイル" "$OUTDIR/reply-G9-5.md" "保留とします。"
assert_file_has "replies: 対応不要の返信ファイル" "$OUTDIR/reply-G9-3.md" "対応不要と判断しました。"
if [ -e "$OUTDIR/reply-G9-B1.md" ]; then fail "replies: 本文指摘の返信ファイルを作ってはならない"; else ok "replies: 本文指摘の返信ファイルは作らない"; fi

# スレッドが見つからない（Resolve 済み・dbid の誤り）: 残りは出しつつ非ゼロ
jq 'map(select(.id != "PRRT_3"))' "$FX/threads.json" >"$WORK/threads-missing.json"
run_gr STUB_THREADS_FILE="$WORK/threads-missing.json" -- replies 66 9 --file="$WORK/rec-ok.md" --out="$WORK/out2"
assert_rc "replies: 未解決スレッドに無い dbid があれば 1" 1
assert_err "replies: 見つからないスレッドの dbid を出す" "comment 1003"
assert_out "replies: 見つかったスレッドのコマンドは出す" "gate-resolve.sh PRRT_1"
run_gr STUB_RC_THREADS=1 -- replies 66 9 --file="$WORK/rec-ok.md" --out="$WORK/out3"
assert_rc "replies: gate-threads.sh の失敗: 3" 3
run_gr -- replies 66 9 --file="$WORK/rec-ok.md"
assert_rc "replies: --out を省くと一時ディレクトリへ（0）" 0
assert_out "replies: 一時ディレクトリの場所を出す" "# reply files: "
jq '. + [{"id":"PRRT_6","path":"src/z.sh","line":1,"comments":{"nodes":[{"databaseId":1006,"body":"x","author":{"login":"copilot-pull-request-reviewer"},"createdAt":"2026-09-25T00:00:00Z","url":"https://example.com/pull/64#discussion_r1006"}]}}]' "$FX/threads.json" >"$WORK/threads-extra.json"
run_gr STUB_THREADS_FILE="$WORK/threads-extra.json" -- replies 66 9 --file="$WORK/rec-ok.md" --out="$WORK/out5"
assert_rc "replies: 記録に無い未解決スレッドがあっても止めない（0）" 0
assert_err "replies: 記録に無い未解決スレッドを通知する" "not in this record: r1006"
run_gr -- replies 66 9 --file="$WORK/rec-ok.md" --out="$WORK/out6"
if grep -qF "not in this record" <<<"$ERR"; then fail "replies: 全てのスレッドが記録にあるときは通知しない"; else ok "replies: 全てのスレッドが記録にあるときは通知しない"; fi
run_gr -- replies 066 09 --file="$WORK/rec-ok.md" --out="$WORK/out4"
assert_log "replies: 先頭 0 の PR 番号は 10 進数の番号で gate-threads.sh へ渡す" "gate-threads 66 --json"
assert_out "replies: 先頭 0 の PR 番号は 10 進数の番号でコマンドへ出す" "gate-reply.sh 66 1001 - < "

# ---- 承認の判定: 固定の印「【承認済み】」だけを、コメントを除いた判定から肯定一致で読む（否定形・隠しコメントで Resolve しない） ----
approval_case() { # <名前> <G9-4 の判定行を書き換える perl の置換式> <G9-4 のスレッドを Resolve するか: yes|no>
  mkrec "$1" "$2"
  run_gr -- replies 66 9 --file="$WORK/rec-$1.md" --out="$WORK/out-$1"
  assert_rc "承認 $1: replies は 0" 0
  if [ "$3" = yes ]; then
    assert_out "承認 $1: Resolve のコマンドを出す" "gate-resolve.sh PRRT_4"
  else
    assert_no_out "承認 $1: Resolve のコマンドを出さない" "gate-resolve.sh PRRT_4"
    assert_out "承認 $1: 返信だけは出す" "gate-reply.sh 66 1004 - < "
  fi
}
approval_case negated 's/判定: 対応不要（誤検知）\n/判定: 対応不要（誤検知。ユーザー未承認・承認待ち）\n/' no
approval_case denied 's/判定: 対応不要（誤検知）\n/判定: 対応不要（誤検知）承認なし\n/' no
approval_case hidden 's/判定: 対応不要（誤検知）\n/判定: 対応不要 <!-- 【承認済み】 -->\n/' no
approval_case marked 's/判定: 対応不要（誤検知）\n/判定: 対応不要（誤検知）【承認済み】\n/' yes
# 判定語の直後の句読点（。、）は許す（裸の \p{Han} は U+3001/3002 にも一致して「修正。」を拒否した）。一方「修正不要」「保留中」は拒否する
mkrec punct 's/判定: 修正\n対応: 1 行目/判定: 修正。実コードで確認した\n対応: 1 行目/; s/判定: 保留/判定: 保留、後日対応/; s/判定: 対応不要（誤検知）\n/判定: 対応不要。誤検知\n/'
run_gr -- check 66 9 --file="$WORK/rec-punct.md"
assert_rc "判定語の直後の句読点（。、）は許す: 0" 0
assert_out "判定語の直後の句読点でも種別は変わらない" "OK: 6 findings (修正 3 / 保留 1 / 対応不要 2)"
mkrec fixeddone 's/判定: 保留/判定: 修正済み/; s/(対応: Low のため backlog へ。\n)コミット: —/$1コミット: '"$SHA"'/'
run_gr -- replies 66 9 --file="$WORK/rec-fixeddone.md" --out="$WORK/out-fixeddone"
assert_rc "判定「修正済み」は修正として受け付ける: 0" 0
assert_out "判定「修正済み」は Resolve のコマンドを出す" "gate-resolve.sh PRRT_5"

mkrec dupwithin 's/(スレッド: \[r1002\]\([^\n]*\))/$1 $1/'
run_gr -- replies 66 9 --file="$WORK/rec-dupwithin.md" --out="$WORK/out-dupwithin"
if [ "$(grep -c 'gate-reply.sh 66 1002 ' <<<"$OUT")" -eq 1 ]; then ok "同じ thread を 1 つの指摘に 2 回書いても返信のコマンドは 1 件"; else fail "同じ thread を 2 回書くと返信が重複する: $(grep -c 'gate-reply.sh 66 1002 ' <<<"$OUT")"; fi

# HTML コメントだけの行は値に混ぜない（複数行の対応の途中に置いても、返信に空行や注釈が入らない）
mkrec cmt 's/- 箇条書き A\n/- 箇条書き A\n<!-- メモ: 返信には出さない -->\n/'
run_gr -- replies 66 9 --file="$WORK/rec-cmt.md" --out="$WORK/out-cmt"
if [ "$(grep -A1 -F -- '- 箇条書き A' "$WORK/out-cmt/reply-G9-2.md" | tail -n 1)" = "- 箇条書き B" ]; then ok "値の途中のコメント行は返信に空行を残さない"; else fail "値の途中のコメント行が返信に空行として残っている"; fi
if grep -qF 'メモ' "$WORK/out-cmt/reply-G9-2.md"; then fail "コメントの中身が返信に入っている"; else ok "コメントの中身は返信に入れない"; fi

# ---- 0 件のラウンド: 「指摘なし: <確認した内容>」で明示したときだけ通す ----
clean_rec() { # <名前> <## 指摘 の中身>
  printf '# ゲートラウンド G9\n- PR: #66\n\n## 指摘\n%s\n\n## 検証\n- テスト ok\n' "$2" >"$WORK/rec-$1.md"
}
clean_rec clean '指摘なし: gate-threads.sh 0 件・gate-bodies.sh の本文指摘 0 件を確認した'
run_gr -- check 66 9 --file="$WORK/rec-clean.md"
assert_rc "0 件のラウンド（指摘なしの明示あり）の check: 0" 0
assert_out "0 件のラウンド: check は確認内容を出す" "OK: 0 findings (指摘なし: gate-threads.sh 0 件・gate-bodies.sh の本文指摘 0 件を確認した)"
run_gr -- summary 66 9 --file="$WORK/rec-clean.md"
assert_rc "0 件のラウンドの summary: 0" 0
assert_out "0 件のラウンド: summary に指摘なしと確認内容を出す" "- 指摘なし（Resolve したスレッド 0 件）: gate-threads.sh 0 件・gate-bodies.sh の本文指摘 0 件を確認した"
assert_no_out "0 件のラウンド: summary に表を出さない" "| ID |"
assert_out "0 件のラウンド: 検証欄は出す" "- テスト ok"
run_gr -- replies 66 9 --file="$WORK/rec-clean.md" --out="$WORK/out-clean"
assert_rc "0 件のラウンドの replies: 0" 0
assert_no_out "0 件のラウンド: 返信のコマンドは出さない" "gate-reply.sh"
clean_rec cleanbare '指摘なし'
run_gr -- check 66 9 --file="$WORK/rec-cleanbare.md"
assert_rc "確認内容のない「指摘なし」: 1" 1
assert_err "確認内容のない「指摘なし」は理由を出す" "指摘なし needs a note of what was checked"
clean_rec cleanunmarked ''
run_gr -- check 66 9 --file="$WORK/rec-cleanunmarked.md"
assert_rc "指摘の見出しも「指摘なし」も無い記録: 1" 1
assert_err "指摘の見出しも「指摘なし」も無い記録は理由を出す" 'no findings under "## 指摘"'
clean_rec cleantodo '指摘なし: TODO(記入)'
run_gr -- check 66 9 --file="$WORK/rec-cleantodo.md"
assert_rc "骨組みのままの「指摘なし: TODO(記入)」: 1" 1
mkrec cleanconflict 's/## 指摘\n/## 指摘\n指摘なし: あり得ない\n/'
run_gr -- check 66 9 --file="$WORK/rec-cleanconflict.md"
assert_rc "指摘があるのに「指摘なし」: 1" 1
assert_err "指摘があるのに「指摘なし」は矛盾として報告" "指摘なし conflicts with the findings below"
run_gr STUB_THREADS_FILE="$WORK/no-threads.json" -- init 66 16
assert_file_has "init: スレッドが無いときの骨組みは「指摘なし」の行を TODO(記入) で置く" "$REC_DIR/G16.md" "指摘なし: TODO(記入)"

# ---- 本文指摘（スレッド無し）に、元のレビューへのリンクを付けられる ----
mkrec bodylink 's/スレッド: なし（本文指摘）/スレッド: なし（[review 5315094015](https:\/\/example.com\/pull\/64#pullrequestreview-5315094015)）/'
run_gr -- summary 66 9 --file="$WORK/rec-bodylink.md"
assert_out "summary: 本文指摘の行に元のレビューへのリンクを出す" "| なし（[review 5315094015](https://example.com/pull/64#pullrequestreview-5315094015)） |"
mkrec bodyplain 's/スレッド: なし（本文指摘）/スレッド: なし（pullrequestreview-777）/'
run_gr -- summary 66 9 --file="$WORK/rec-bodyplain.md"
assert_out "summary: URL の無いレビュー参照は review <id> の文字だけで出す" "| なし（review 777） |"
mkrec threadreview 's/(スレッド: \[r1001\]\([^\n]*\))/$1 [review 9](https:\/\/example.com\/pull\/64#pullrequestreview-9)/'
run_gr -- summary 66 9 --file="$WORK/rec-threadreview.md"
assert_out "summary: スレッドのある指摘にレビューのリンクも書けば両方出す" "| [r1001](https://example.com/pull/64#discussion_r1001), [review 9](https://example.com/pull/64#pullrequestreview-9) |"

# ---- 記入漏れの検出（check / summary / replies のどれも止まる。止まるときは標準出力に何も出さない） ----
expect_incomplete() { # <名前> <perl の置換式> <期待するメッセージ>
  mkrec "$1" "$2"
  local cmd
  for cmd in check summary replies; do
    if [ "$cmd" = "replies" ]; then
      run_gr -- "$cmd" 66 9 --file="$WORK/rec-$1.md" --out="$WORK/out-bad"
    else
      run_gr -- "$cmd" 66 9 --file="$WORK/rec-$1.md"
    fi
    if [ "$RC" -eq 1 ]; then ok "$1: $cmd は 1 で止まる"; else fail "$1: $cmd の終了コード ${RC}（期待 1）"; fi
  done
  run_gr -- check 66 9 --file="$WORK/rec-$1.md"
  assert_err "$1: 理由を出す" "$3"
  run_gr -- summary 66 9 --file="$WORK/rec-$1.md"
  if [ -z "$OUT" ]; then ok "$1: summary は標準出力に何も出さない（記入途中を投稿させない）"; else fail "$1: summary が標準出力に出している"; fi
}
expect_incomplete todo 's/- ミューテーション 10 種を検出/- TODO(記入)/' "TODO(記入) remains at line(s):"
expect_incomplete unknown 's/判定: 保留/判定: 検討中/' "G9-5: 判定 must start with 修正 / 保留 / 対応不要"
expect_incomplete noverdict 's/判定: 保留\n//' "G9-5: 判定 is empty"
expect_incomplete noaction 's/対応: Low のため backlog へ。/対応:/' "G9-5: 対応 is empty"
expect_incomplete nocommit 's/コミット: \Q'"$SHA"'\E\nスレッド: \[r1001\]/コミット: —\nスレッド: [r1001]/' "G9-1: 修正 needs a commit sha"
expect_incomplete badsha 's/コミット: —\nスレッド: \[r1005\]/コミット: 0123456789abcdef0123456789abcdef01234567\nスレッド: [r1005]/' "G9-5: commit 0123456789abcdef0123456789abcdef01234567 does not exist"
expect_incomplete nosummary 's/要旨: 後日対応する Low。\n//' "G9-5: 要旨 is empty"
expect_incomplete dupid 's/\[G9-5\]/[G9-4]/' "duplicate finding id: G9-4"
expect_incomplete dupthread 's/\[r1005\]\(https:\/\/example.com\/pull\/64#discussion_r1005\)/[r1001](https:\/\/example.com\/pull\/64#discussion_r1001)/' "thread r1001 is used by more than one finding: G9-1, G9-5"
expect_incomplete nothreadline 's/スレッド: \[r1005\][^\n]*\n//' "G9-5: スレッド must hold a discussion_r<dbid> link"
expect_incomplete emptythread 's/スレッド: \[r1005\][^\n]*\n/スレッド:\n/' "G9-5: スレッド must hold a discussion_r<dbid> link"
expect_incomplete garbagethread 's/スレッド: \[r1005\][^\n]*\n/スレッド: 後で追記\n/' "G9-5: スレッド must hold a discussion_r<dbid> link"
expect_incomplete notfixed 's/判定: 保留/判定: 修正不要/' "G9-5: 判定 must start with"
expect_incomplete badid 's/### \[G9-5\]/### [G9 5]/' "malformed finding heading"
expect_incomplete treesha 's/コミット: —\nスレッド: \[r1005\]/コミット: '"$(git -C "$HERE" rev-parse 'HEAD^{tree}')"'\nスレッド: [r1005]/' "does not exist in this repository"
expect_incomplete opencomment 's/## 検証\n/## 検証\n<!-- memo\n/' "unterminated comment"
expect_incomplete openfence 's/## 検証\n/## 検証\n```bash\n/' "unterminated fence"
# コードフェンスの中の # 行や ### 行を見出しとして読まない（読むと、以降の指摘が黙って消える）
expect_incomplete fence 's/(スレッド: \[r1004\][^\n]*\n)/$1```bash\n# repro\n## not a section\n### [G9-X][Copilot][a:1]\n```\n/; s/判定: 保留/判定: 検討中/' "G9-5: 判定 must start with"
expect_incomplete badheading 's/### \[G9-5\]\[Copilot\]\[src\/e.sh:50\]/### [G9-5][Copilot]/' "malformed finding heading"
printf '# ゲートラウンド G9\n- PR: #64\n' >"$WORK/rec-nosection.md"
run_gr -- check 66 9 --file="$WORK/rec-nosection.md"
assert_rc "指摘の節が無い記録: 1" 1
assert_err "指摘の節が無い記録: 理由" 'no "## 指摘" section'
run_gr -- check 66 9 --file="$WORK/missing.md"
assert_rc "記録ファイルが無い: 3" 3
assert_err "記録ファイルが無い: init を案内する" "run: gate-record.sh init 66 9"
run_gr -- check 66 9
assert_rc "--file なしで PR のブランチから記録を探す（骨組みのままの G9 は 1）" 1
assert_log "--file なしのとき gh pr view で head ブランチを取る" "gh pr view 66 --json headRefName --jq .headRefName"
run_gr STUB_RC_GH=1 -- check 66 9
assert_rc "ブランチを取得できない: 3" 3

# ---- 引数 ----
run_gr -- ""
assert_rc "サブコマンド省略: 2" 2
run_gr -- bogus 66 9
assert_rc "未知のサブコマンド: 2" 2
run_gr -- check abc 9
assert_rc "PR が数字でない: 2" 2
run_gr -- check 66 x
assert_rc "ラウンドが数字でない: 2" 2
run_gr -- check 66 9 --bogus
assert_rc "未知のオプション: 2" 2
run_gr -- summary 66 9 --file="$WORK/rec-ok.md" --since=2026-09-25T00:00:00Z
assert_rc "--since は init 専用: 2" 2
run_gr -- check 66 9 --file="$WORK/rec-ok.md" --force
assert_rc "--force は init 専用: 2" 2
run_gr -- check 66 9 --file="$WORK/rec-ok.md" --out="$WORK/x"
assert_rc "--out は replies 専用: 2" 2

if [ "$FAILS" -ne 0 ]; then
  echo "test-gate-record: $FAILS 件失敗"
  exit 1
fi
echo "test-gate-record: all ok"
