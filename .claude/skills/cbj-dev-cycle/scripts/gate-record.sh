#!/usr/bin/env bash
# ゲートラウンドの記録 docs/reviews/<branch>/G<n>.md を「唯一の情報源」にして、骨組みの作成・記入漏れの検査・
# PR のサマリコメントとスレッド返信の生成を行う（同じ内容を記録・返信・サマリの 3 回書き直さないため）。
# 使い方:
#   gate-record.sh init    <PR> <n> [--since=T] [--force] [--file=PATH]   骨組みを作る（判断が要る欄は TODO(記入)）
#   gate-record.sh check   <PR> <n> [--file=PATH]                        記入漏れを検査する
#   gate-record.sh summary <PR> <n> [--file=PATH]                        PR のサマリコメント本文を標準出力へ
#   gate-record.sh replies <PR> <n> [--file=PATH] [--out=DIR]            スレッドごとの返信ファイルと実行コマンドを出す
#   gate-record.sh approve <PR> <n> [--auto-commit] [--force] [--file=PATH]   確認ゲート（AskUserQuestion）を通した印をヘッダに書く
# --file を省くと PR の head ブランチから docs/reviews/<branch>/G<n>.md を決める。--since は init だけ（gate-threads.sh の T）。
#
# 確認ゲート → commit の順序（メモに書いても PR #79 G1・PR #80 G1〜G3 の計 4 回、確認前に commit した。原因は「修正」の指摘に commit の
# sha を要求する check が、記録を完成させるための先行 commit を誘うこと）を、記録の `- 承認:` 行で機械的に検査する:
#   修正 → 品質チェック（commit しない）→ AskUserQuestion → 承認後に `approve`（現在の UTC 時刻を書く。auto-commit 指定時は `--auto-commit`）
#   → `git commit` → 記録に sha を書く → `check`（push の前に実行する）。
# `check`/`summary`/`replies` は、判定が「修正」の指摘が 1 件でもあるとき、承認行が無い・読めない、または修正の指摘が挙げた commit の
# committer 時刻が承認時刻より前（＝確認前に commit した）なら非ゼロで止める。`- 承認: auto-commit` は時刻の検査を飛ばす。
# ただし記録の「対象 HEAD」（このラウンドのレビューが対象にした commit）の祖先の commit は検査しない（レビューが届く前からあった commit
# ＝前のラウンドで直した指摘を bot が再指摘した場合など。祖先と確かめられなければ検査する）。承認行の書式は UTC 時刻（2000〜2099 年）か auto-commit。
# `approve --force --auto-commit` で時刻承認を auto-commit に置き換えることは拒否する（1 コマンドで違反を合格に変えられるため）。
# 限界: 検出であって防止ではない。`approve` を人の回答より前に実行する、`- 承認:` 行を手で遡った時刻に書く、`CBJ_GATE_NOW`（テスト用の
# 時刻の上書き。使うと警告する）を本番で使う、といった偽装までは防げない。`git commit --amend`／rebase は committer 時刻を変えるので、
# 承認後に作り直した commit は通る。対象 HEAD が修正の commit 自身かその子孫のとき（修正を push した後に init した、記録を手で書き換えた）は、
# 祖先の除外で検査を飛ばす（除外した commit は note として stderr に出す）。順序違反が見つかったら、未 push の commit を `git reset --soft` で戻して承認からやり直す。
# 導入前の記録（承認行の無い過去の G<n>.md）を再検査すると、判定が「修正」のものは承認なしで止まる。
# init は指摘を取得した直後（修正の前）に実行するのが確実: 修正を push した後だと、修正済みのスレッドは outdated になって行番号が `?` になる。
# --since を付けるとレビューは「T 以降に提出されたもの」を拾い、対象の commit を併記する（付けなければ現在の HEAD へのレビュー）。
#
# 記録の書式（init が作る形。既存の G ファイルの書式 `判定:` / `**対応:**` / `コミット:` / `スレッド:` も読める）:
#   ### [G<n>-<k>][<bot>][<path>:<line>]      ← 指摘 1 件。本文指摘（スレッド無し）も同じ形で手で足す
#   要旨: / 判定: / 対応: / コミット: / スレッド:   ← 値は複数行にできる（次のラベルか見出しまで）
#   判定は「修正」（「修正済み」も可）「保留」「対応不要」のどれかで始める（「修正不要」「保留中」のように漢字・かなが続く語は別の語として
#   拒否する）。対応不要のスレッドを Resolve するのはユーザー承認済みのときだけで、判定に固定の印「【承認済み】」を書き添えたときに限り
#   replies が Resolve のコマンドを出す（書かなければ返信のみ。「未承認」「承認待ち」などの語や HTML コメント内の語では出さない）。
# 記録の PR 番号（`- PR: #<n>`）とラウンド（`# ゲートラウンド G<n>`）が引数と違う記録は止める（--file で別 PR の記録を渡して、
# 別 PR へ返信するコマンドやラベルの違うサマリを作らないため）。修正した指摘があるのに検証欄（## 検証）が空の記録も止める。
# 指摘が 0 件のラウンドは、最初の指摘の見出しより前に「指摘なし: <確認した内容>」を書いて明示する（見出しが無いだけでは止める）。
# 本文指摘（スレッド無し）は `スレッド: なし（[review <id>](…#pullrequestreview-<id>)）` と書くと、サマリの表に元のレビューへのリンクが出る。
# 要旨・対応などの本文に `TODO(記入)` という文字列そのものは書けない（未記入のマーカーとして止まる。HTML コメントの中は数えない）。
# 記入漏れ（TODO(記入) の残り・判定なし・不明な判定・対応なし・要旨なし・修正なのに sha なし・存在しない sha・見出しの崩れ・ID や thread の重複・
# スレッドの欄が無い/壊れている（discussion_r<dbid> のリンクか、本文指摘を示す「なし」で始まる値のどちらかが必須）・
# 閉じていない HTML コメント／コードフェンス）は check/summary/replies が非ゼロで止める（記入途中のものを PR に投稿しない）。
# sha はこのリポジトリのローカルに存在するかだけを確認する（push 済みか・PR に含まれるかは見ない）。replies は返信ファイルを作って
# 実行コマンドを表示するだけで、投稿・Resolve はしない（コマンドを確認して実行する）。
#
# 終了コード: 0=成功 / 1=記録の記入漏れ・スレッドが見つからない / 2=引数不正・上書きの拒否 / 3=API・ファイルの取得失敗
# テスト用に CBJ_GATE_SCRIPTS_DIR（gate-threads.sh 等の置き場）と CBJ_RECORD_ROOT（docs/ を置くルート）を差し替えられる。
set -uo pipefail

usage() {
  echo "usage: gate-record.sh <init|check|summary|replies|approve> <pr> <n> [--since=T] [--force] [--auto-commit] [--file=PATH] [--out=DIR]" >&2
  exit 2
}

CMD=${1:-}
case "$CMD" in
  init | check | summary | replies | approve) ;;
  *) usage ;;
esac
PR=${2:-}
N=${3:-}
[[ "$PR" =~ ^[0-9]+$ ]] || usage
[[ "$N" =~ ^[0-9]+$ ]] || usage
# 先頭 0 は 8 進数として読まれうる（`$(( ))` を使わなくても G03 と G3 が別ファイルになる）ので 10 進数へ正規化する。
PR=$((10#$PR))
N=$((10#$N))

SINCE=""
FORCE=0
AUTO=0
FILE=""
OUT=""
for arg in "${@:4}"; do
  case "$arg" in
    --since=*) SINCE=${arg#*=} ;;
    --force) FORCE=1 ;;
    --auto-commit) AUTO=1 ;;
    --file=*) FILE=${arg#*=} ;;
    --out=*) OUT=${arg#*=} ;;
    *) echo "unknown option: $arg" >&2; usage ;;
  esac
done
if [ -n "$SINCE" ] && [ "$CMD" != "init" ]; then
  echo "--since is for init only" >&2
  exit 2
fi
if [ "$FORCE" -eq 1 ] && [ "$CMD" != "init" ] && [ "$CMD" != "approve" ]; then
  echo "--force is for init and approve only" >&2
  exit 2
fi
if [ "$AUTO" -eq 1 ] && [ "$CMD" != "approve" ]; then
  echo "--auto-commit is for approve only" >&2
  exit 2
fi
if [ -n "$OUT" ] && [ "$CMD" != "replies" ]; then
  echo "--out is for replies only" >&2
  exit 2
fi
if [ -n "$SINCE" ] && ! [[ "$SINCE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]; then
  echo "--since must be a UTC time like 2026-09-25T01:23:45Z (got: '$SINCE')" >&2
  exit 2
fi

DIR=${CBJ_GATE_SCRIPTS_DIR:-$(cd "$(dirname "$0")" && pwd)}

# 記録ファイルの場所を REC に決める。
resolve_file() {
  if [ -n "$FILE" ]; then
    REC=$FILE
    return
  fi
  local root branch
  if [ -n "${CBJ_RECORD_ROOT:-}" ]; then
    root=$CBJ_RECORD_ROOT
  elif ! root=$(git rev-parse --show-toplevel) || [ -z "$root" ]; then
    echo "could not find the repository root" >&2
    exit 3
  fi
  if ! branch=$(gh pr view "$PR" --json headRefName --jq .headRefName) || [ -z "$branch" ]; then
    echo "could not resolve the head branch of PR #$PR" >&2
    exit 3
  fi
  case "$branch" in
    *..* | /*)
      echo "unsafe branch name: $branch" >&2
      exit 3
      ;;
  esac
  REC="$root/docs/reviews/$branch/G$N.md"
}

# 記録を JSON にするパーサー（perl。日本語のラベルを扱うので awk ではなく UTF-8 で読む）。
# bash 3.2 は `$(cat <<'X' … )` の中身の引用符・バッククォートを誤解釈するため、別ファイルに置く。
PARSER=$(cd "$(dirname "$0")" && pwd)/gate-record-parse.pl

# 記録を読んで検査し、JSON を REC_JSON に入れる。記入漏れがあれば内容を出して 1 を返す。
load_record() {
  resolve_file
  if [ ! -f "$REC" ]; then
    echo "record not found: $REC (run: gate-record.sh init $PR $N)" >&2
    exit 3
  fi
  if ! REC_JSON=$(perl "$PARSER" "$REC") || [ -z "$REC_JSON" ]; then
    echo "could not parse $REC" >&2
    exit 3
  fi
  local errs
  if ! errs=$(jq -r --argjson pr "$PR" --argjson round "$N" '
    ( if .pr_number == null then "record header lacks \"- PR: #<n>\" (needed to check that the record belongs to this PR)"
      elif .pr_number != $pr then "record is for PR #\(.pr_number) but the command says PR #\($pr)" else empty end ),
    ( if .round == null then "record title lacks \"# ゲートラウンド G<n>\" (needed to check the round)"
      elif .round != $round then "record is for round G\(.round) but the command says G\($round)" else empty end ),
    ( if ([.findings[] | select(.kind == "fixed")] | length) > 0 and (.verify | length) == 0 then "検証 needs at least one line (test / lint / mutation results) when a finding is 修正" else empty end ),
    ( if ([.findings[] | select(.kind == "fixed")] | length) > 0 then
        ( if .approval == "" then "承認 is missing: a 修正 needs the confirm gate before the commit. Right after the user approves (before git commit) run: gate-record.sh approve <PR> <n>  (--auto-commit only when the user invoked auto-commit)"
          elif .approval == "bad" then "承認 must be \"- 承認: <UTC time like 2026-09-28T04:12:33Z>\" or \"- 承認: auto-commit\" (got: \(.approval_raw | .[0:40]))"
          else empty end )
      else empty end ),
    ( .todo_lines | if length > 0 then "TODO(記入) remains at line(s): " + (map(tostring) | join(", ")) else empty end ),
    ( .bad_headings | if length > 0 then "malformed finding heading (expected ### [ID][bot][path:line], ID = letters, digits, . _ -) at line(s): " + (map(tostring) | join(", ")) else empty end ),
    ( .unterminated | if . != "" then "unterminated \(.) (an opening <!-- or code fence is never closed; everything after it is ignored)" else empty end ),
    ( if (.has_findings_section | not) then "no \"## 指摘\" section"
      elif (.findings | length) == 0 then
        ( if .no_findings then ( if .no_findings_text == "" then "指摘なし needs a note of what was checked (e.g. 指摘なし: gate-threads.sh 0 件・gate-bodies.sh の本文指摘 0 件を確認した)" else empty end )
          else "no findings under \"## 指摘\": for a clean round write 指摘なし: <what you checked>, for body-only findings add ### [G<n>-B1][bot][path:line] blocks" end )
      elif .no_findings then "指摘なし conflicts with the findings below (remove one of them)"
      else empty end ),
    ( .findings | group_by(.id)[] | select(length > 1) | "duplicate finding id: " + .[0].id ),
    ( [.findings[] | .id as $id | .dbids[] | {d: ., id: $id}] | group_by(.d)[] | select(length > 1) | "thread r\(.[0].d) is used by more than one finding: " + (map(.id) | join(", ")) ),
    ( .findings[] | .id as $id | (
        if .kind == "missing" then "\($id): 判定 is empty"
        elif .kind == "unknown" then "\($id): 判定 must start with 修正 / 保留 / 対応不要 (got: \(.verdict | .[0:30]))"
        else empty end ),
      ( if .action == "" then "\($id): 対応 is empty" else empty end ),
      ( if .summary == "" then "\($id): 要旨 is empty" else empty end ),
      ( if (.dbids | length) == 0 and (.no_thread | not) then "\($id): スレッド must hold a discussion_r<dbid> link, or start with なし for a body-only finding (a lost link would drop a live thread from replies)" else empty end ),
      ( if .kind == "fixed" and (.commits | length) == 0 then "\($id): 修正 needs a commit sha in コミット" else empty end ) )
  ' <<<"$REC_JSON"); then
    echo "internal error while checking $REC" >&2
    exit 3
  fi
  local bad=0 id kind sha ct approved_epoch approval_kind target_head
  if [ -n "$errs" ]; then
    printf '%s\n' "$errs" >&2
    bad=1
  fi
  approval_kind=$(jq -r '.approval' <<<"$REC_JSON") || exit 3
  approved_epoch=$(jq -r '.approval_epoch // empty' <<<"$REC_JSON") || exit 3
  # 「対象 HEAD」（このラウンドのレビューが対象にした commit）。その祖先の commit は、レビューが届く前からあった commit なので、このラウンドの修正ではない
  # （前のラウンドで直した指摘を bot が再指摘した場合など）。記録できなくなるのを避けるため、順序検査から外す。祖先と確かめられなければ検査する（フェイルクローズ）。
  target_head=$(jq -r '.target_head' <<<"$REC_JSON") || exit 3
  while IFS=$'\t' read -r id kind sha; do
    [ -n "$sha" ] || continue
    if ! git cat-file -e "$sha^{commit}" 2>/dev/null; then
      echo "$id: commit $sha does not exist in this repository" >&2
      bad=1
      continue
    fi
    # 確認ゲートは commit より前。判定が「修正」の指摘が挙げた commit の committer 時刻が承認時刻より前なら、確認前に commit している
    # （保留・対応不要が引用する過去の sha は対象外。承認が auto-commit・行なし・不正のときは上の jq が扱うのでここでは見ない）。
    if [ "$kind" = "fixed" ] && [ "$approval_kind" = "time" ] && [ -n "$approved_epoch" ]; then
      # 対象 HEAD が無い・存在しない・祖先でない（merge-base は 1 か 128 を返す）ときは除外せず、下で検査する。
      # `--is-ancestor X X` は 0 なので、対象 HEAD が修正の commit そのもの・その子孫のとき（修正を push した後に init した、記録を手で書き換えた）も
      # 除外される。区別できないので、除外したことを毎回 stderr に出して目で確かめられるようにする。
      if [ -n "$target_head" ] && git merge-base --is-ancestor "$sha^{commit}" "$target_head^{commit}" 2>/dev/null; then
        echo "$id: note: commit ${sha:0:7} is an ancestor of (or equal to) the target HEAD ${target_head}, so the approval-order check was skipped for it" >&2
        continue
      fi
      if ! ct=$(git show -s --format=%ct "$sha^{commit}") || ! [[ "$ct" =~ ^[0-9]+$ ]]; then
        echo "$id: could not read the commit time of $sha" >&2
        bad=1
      elif [ "$ct" -lt "$approved_epoch" ]; then
        echo "$id: commit ${sha:0:7} ($(jq -rn --argjson t "$ct" '$t | todate')) predates the approval ($(jq -rn --argjson t "$approved_epoch" '$t | todate')): the confirm gate must come before the commit (git reset --soft the unpushed commit, run approve, commit again)" >&2
        bad=1
      fi
    fi
  done < <(jq -r '.findings[] | .id as $id | .kind as $k | .commits[] | "\($id)\t\($k)\t\(.)"' <<<"$REC_JSON")
  if [ "$bad" -ne 0 ]; then
    echo "record is incomplete: $REC" >&2
    return 1
  fi
  return 0
}

cmd_check() {
  load_record || exit 1
  jq -r 'if (.findings | length) == 0 then "OK: 0 findings (指摘なし: \(.no_findings_text))"
    else "OK: \(.findings | length) findings (修正 \([.findings[] | select(.kind == "fixed")] | length) / 保留 \([.findings[] | select(.kind == "held")] | length) / 対応不要 \([.findings[] | select(.kind == "dismissed")] | length))" end' <<<"$REC_JSON"
}

cmd_summary() {
  load_record || exit 1
  jq -r --arg n "$N" '
    def uniq: reduce .[] as $x ([]; if (index([$x]) != null) then . else . + [$x] end);
    def kindlabel: if .kind == "fixed" then "修正" elif .kind == "held" then "保留" else "対応不要" end;
    def cell: gsub("\\|"; "\\|") | gsub("\\s*\n\\s*"; "<br>");
    def resolves: (.kind == "fixed" or (.kind == "dismissed" and .approved));
    def threadcell: if (.links | length) > 0 then ((.links + .review_links) | join(", "))
      elif (.review_links | length) > 0 then "なし（" + (.review_links | join(", ")) + "）" else "なし" end;
    "## レビュー指摘への対応サマリ（G\($n)）",
    "",
    (.header[]),
    (([.findings[].commits[]] | uniq) as $c | if ($c | length) > 0 then "- 対応コミット: " + ($c | map("`" + .[0:7] + "`") | join(", ")) else empty end),
    (if (.findings | length) == 0 then
      "",
      "- 指摘なし（Resolve したスレッド 0 件）: \(.no_findings_text)"
    else
    "",
    "| ID | 出所 | 場所 | スレッド | 内容 | 処理 |",
    "|---|---|---|---|---|---|",
    (.findings[] | "| \(.id | cell) | \(.bot | cell) | `\(.where | cell)` | \(threadcell) | \(.summary | cell) | **\(kindlabel)**\(if (.commits | length) > 0 then "（" + (.commits | map("`" + .[0:7] + "`") | join(", ")) + "）" else "" end)。\(.action | cell) |"),
    "",
    (([.findings[] | select(resolves) | .dbids | length] | add // 0) as $r
    | ([.findings[] | select(resolves | not) | .dbids | length] | add // 0) as $o
    | ([.findings[] | select(.dbids | length == 0)] | length) as $b
    | "- Resolve したスレッド: \($r) 件 / 未解決のまま残すスレッド（保留・承認の記録がない対応不要）: \($o) 件 / 本文指摘（スレッド無し。このコメントと G\($n).md が処理済みの記録）: \($b) 件")
    end),
    (if (.verify | length) > 0 then "", "検証:", (.verify[]) else empty end)
  ' <<<"$REC_JSON"
}

cmd_replies() {
  load_record || exit 1
  local threads out missing=0
  if ! threads=$("$DIR/gate-threads.sh" "$PR" --json); then
    echo "gate-threads.sh failed" >&2
    exit 3
  fi
  if [ -n "$OUT" ]; then
    out=$OUT
    mkdir -p "$out" || exit 3
  elif ! out=$(mktemp -d) || [ -z "$out" ]; then
    echo "could not create a temporary directory" >&2
    exit 3
  fi
  echo "# reply files: $out"
  local count idx id kind approved action commits opener plan note file dbid tid extra
  if ! extra=$(jq -r --argjson known "$(jq -c '[.findings[].dbids[]]' <<<"$REC_JSON")" \
    '[.[] | .comments.nodes[0].databaseId | select((. as $d | $known | index($d)) == null) | "r\(.)"] | join(", ")' <<<"$threads"); then
    echo "could not compare the threads with the record" >&2
    exit 3
  fi
  if [ -n "$extra" ]; then
    echo "note: unresolved bot thread(s) not in this record: $extra (expected for threads held in earlier rounds; otherwise add them to the record)" >&2
  fi
  count=$(jq '.findings | length' <<<"$REC_JSON")
  for ((idx = 0; idx < count; idx++)); do
    id=$(jq -r ".findings[$idx].id" <<<"$REC_JSON")
    kind=$(jq -r ".findings[$idx].kind" <<<"$REC_JSON")
    approved=$(jq -r ".findings[$idx].approved" <<<"$REC_JSON")
    action=$(jq -r ".findings[$idx].action" <<<"$REC_JSON")
    commits=$(jq -r ".findings[$idx].commits | map(.[0:7]) | join(\", \")" <<<"$REC_JSON")
    if [ "$(jq ".findings[$idx].dbids | length" <<<"$REC_JSON")" -eq 0 ]; then
      echo "# $id: 本文指摘（スレッド無し）— 返信・Resolve の対象なし"
      continue
    fi
    note=""
    case "$kind" in
      fixed)
        opener="ご指摘のとおりです（${commits} で修正）。"
        plan=resolve
        ;;
      held)
        opener="保留とします。"
        plan=reply
        ;;
      *)
        opener="対応不要と判断しました。"
        if [ "$approved" = "true" ]; then
          plan=resolve
        else
          plan=reply
          note=" — reply only: 対応不要 without 【承認済み】 in 判定 (ask the user before resolving)"
          echo "warning: $id is 対応不要 without 【承認済み】 in 判定; replying without resolving" >&2
        fi
        ;;
    esac
    file="$out/reply-$(printf '%s' "$id" | tr -c 'A-Za-z0-9._-' '_').md"
    printf '%s\n\n%s\n' "$opener" "$action" >"$file" || exit 3
    while IFS= read -r dbid; do
      tid=$(jq -r --argjson d "$dbid" 'first(.[] | select(.comments.nodes[0].databaseId == $d) | .id) // empty' <<<"$threads")
      if [ -z "$tid" ]; then
        echo "$id: the thread of comment $dbid was not found among the unresolved bot threads (already resolved, or a wrong dbid)" >&2
        echo "# $id: SKIPPED (thread of comment $dbid not found)"
        missing=1
        continue
      fi
      echo "# $id ($kind): $plan$note"
      printf '%q %q %q - < %q\n' "$DIR/gate-reply.sh" "$PR" "$dbid" "$file"
      if [ "$plan" = "resolve" ]; then
        printf '%q %q\n' "$DIR/gate-resolve.sh" "$tid"
      fi
    done < <(jq -r ".findings[$idx].dbids[]" <<<"$REC_JSON")
  done
  [ "$missing" -eq 0 ] || exit 1
}

cmd_init() {
  resolve_file
  if [ -e "$REC" ] && [ "$FORCE" -eq 0 ]; then
    echo "$REC already exists (use --force to overwrite)" >&2
    exit 2
  fi
  local meta threads reviews url head revlines findings counts tmp
  if ! meta=$(gh pr view "$PR" --json url,headRefOid); then
    echo "gh pr view failed" >&2
    exit 3
  fi
  url=$(jq -r .url <<<"$meta") || exit 3
  head=$(jq -r .headRefOid <<<"$meta") || exit 3
  if [ -z "$url" ] || [ -z "$head" ]; then
    echo "could not resolve the url / head of PR #$PR" >&2
    exit 3
  fi
  if ! threads=$("$DIR/gate-threads.sh" "$PR" ${SINCE:+"$SINCE"} --json); then
    echo "gate-threads.sh failed" >&2
    exit 3
  fi
  if ! reviews=$(gh api --paginate "repos/{owner}/{repo}/pulls/$PR/reviews") || ! reviews=$(jq -s 'add // []' <<<"$reviews"); then
    echo "could not read the reviews of PR #$PR" >&2
    exit 3
  fi
  if ! revlines=$(jq -r --arg head "$head" --arg since "$SINCE" --arg url "$url" '
    [ .[] | select(.user.login | IN("copilot-pull-request-reviewer[bot]", "chatgpt-codex-connector[bot]"))
      | select(if $since == "" then .commit_id == $head else .submitted_at > $since end)
      | (.user.login | startswith("copilot")) as $cp
      | "[\(if $cp then "Copilot" else "Codex" end) #\(.id)](\($url)#pullrequestreview-\(.id))（"
        + (if $cp then ((.body // "") | split("\n") | map(select(test("^### "))) | (.[0] // "") | sub("^### *"; "") | if . == "" then "" else . + "、" end) else "" end)
        + "対象 " + .commit_id[0:7] + "）" ]
    | if length == 0 then (if $since == "" then "なし（\($head[0:7]) への bot レビューが見つからない）" else "なし（\($since) 以降の bot レビューが見つからない）" end) else join(" / ") end' <<<"$reviews"); then
    echo "could not format the reviews" >&2
    exit 3
  fi
  if ! findings=$(jq -r --argjson n "$N" '
    def bot: (.comments.nodes[0].author.login | if startswith("copilot") then "Copilot" else "Codex" end);
    def sev: ([.comments.nodes[0].body // "" | capture("!\\[(?<p>P[0-9]) Badge\\]") | .p] | .[0] // "");
    def excerpt: (.comments.nodes[0].body // "" | gsub("\\s+"; " ") | gsub("-->"; "--&gt;") | .[0:300]);
    to_entries[] | .key as $i | .value
    | "### [G\($n)-\($i + 1)][\(bot)\(sev | if . == "" then "" else " " + . end)][\(.path):\(.line // "?")]",
      "要旨: TODO(記入)",
      "<!-- 判定は 修正 / 保留 / 対応不要 のいずれかで始める。対応不要のスレッドを Resolve するにはユーザー承認を得たうえで判定に 【承認済み】 と書き添える -->",
      "判定: TODO(記入)",
      "対応: TODO(記入)",
      "コミット: —",
      "スレッド: [r\(.comments.nodes[0].databaseId)](\(.comments.nodes[0].url))",
      "<!-- 原文: \(excerpt) -->",
      ""' <<<"$threads"); then
    echo "could not format the threads" >&2
    exit 3
  fi
  if ! counts=$(jq -r '[ (map(select(.comments.nodes[0].author.login | startswith("chatgpt"))) | length),
                         (map(select(.comments.nodes[0].author.login | startswith("copilot"))) | length) ] | @tsv' <<<"$threads"); then
    echo "could not count the threads" >&2
    exit 3
  fi
  local codex_n copilot_n
  codex_n=${counts%%$'\t'*}
  copilot_n=${counts#*$'\t'}
  mkdir -p "$(dirname "$REC")" || exit 3
  tmp="$REC.tmp.$$"
  {
    printf '# ゲートラウンド G%s\n' "$N"
    printf -- '- PR: #%s / 対象 HEAD: %s\n' "$PR" "${head:0:7}"
    printf -- '- レビュー: %s\n' "$revlines"
    printf -- '- 再依頼: TODO(記入)\n'
    printf -- '<!-- 修正した指摘があるなら、ユーザーの承認の直後・git commit の前に: gate-record.sh approve %s %s（auto-commit 指定時は --auto-commit）。承認前に commit すると check が止まる -->\n' "$PR" "$N"
    printf '\n## 指摘\n'
    if [ -n "$findings" ]; then
      printf '%s\n\n' "$findings"
    else
      printf '<!-- 未解決のボットスレッドはありません。本文指摘（gate-bodies.sh）も 0 件なら、次の行を「指摘なし: <確認した内容>」に書き換える。本文指摘があるなら次の行を消し、`### [G%s-B1][Copilot][path:line]` を手で足す（`スレッド: なし（[review <id>](…#pullrequestreview-<id>)）` と書く） -->\n指摘なし: TODO(記入)\n\n' "$N"
    fi
    printf '## 検証\n- TODO(記入)（テスト・lint・ミューテーションの結果）\n\n'
    printf '## 収束判定\n| bot | 依頼回数 | 新規スレッド | 状態 |\n|---|---|---|---|\n'
    printf '| Codex | TODO(記入) | %s | TODO(記入) |\n' "$codex_n"
    printf '| Copilot | TODO(記入) | %s | TODO(記入) |\n' "$copilot_n"
  } >"$tmp" || { rm -f "$tmp"; exit 3; }
  mv "$tmp" "$REC" || { rm -f "$tmp"; exit 3; }
  echo "wrote $REC"
  echo "next: fix the findings WITHOUT committing, get the user's approval (AskUserQuestion), run: gate-record.sh approve $PR $N, then git commit"
  echo "then: fill in every TODO(記入) (コミット: needs the sha) and run, before git push: gate-record.sh check $PR $N"
}

# 確認ゲート（AskUserQuestion）を通した印を、記録のヘッダ（`- PR:` 行の直後）に書く。ユーザーが承認した**直後・git commit の前**に実行する。
# 既に承認行があれば --force なしでは上書きしない（黙って時刻を新しくして、先行 commit を承認済みに見せかけないため）。
# 挿入・置換の位置と、既存の承認の有無は、パーサ（load_record と同じ）が返すヘッダの行番号で決める（コードフェンス・HTML コメントの中の
# `- PR:`／`- 承認:` を書き手が別の意味に読まない）。記録の PR 番号・ラウンドも引数と照合する（別 PR の記録に承認を書かない）。
# 時刻承認を --force で auto-commit に置き換えることは拒否する（check の「承認より前の commit」を 1 コマンドで合格に変えられてしまうため）。
cmd_approve() {
  resolve_file
  if [ ! -f "$REC" ]; then
    echo "record not found: $REC (run: gate-record.sh init $PR $N)" >&2
    exit 3
  fi
  local json errs now stamp line tmp pr_line approval_line approval_kind
  if ! json=$(perl "$PARSER" "$REC") || [ -z "$json" ]; then
    echo "could not parse $REC" >&2
    exit 3
  fi
  if ! errs=$(jq -r --argjson pr "$PR" --argjson round "$N" '
    ( if .pr_number == null then "record header lacks \"- PR: #<n>\" (needed to put the 承認 line after it, and to check that the record belongs to this PR)"
      elif .pr_number != $pr then "record is for PR #\(.pr_number) but the command says PR #\($pr)" else empty end ),
    ( if .round == null then "record title lacks \"# ゲートラウンド G<n>\" (needed to check the round)"
      elif .round != $round then "record is for round G\(.round) but the command says G\($round)" else empty end )' <<<"$json"); then
    echo "internal error while checking $REC" >&2
    exit 3
  fi
  if [ -n "$errs" ]; then
    printf '%s\n' "$errs" >&2
    exit 1
  fi
  pr_line=$(jq -r '.pr_line' <<<"$json") || exit 3
  approval_line=$(jq -r '.approval_line // 0' <<<"$json") || exit 3
  approval_kind=$(jq -r '.approval' <<<"$json") || exit 3
  if [ "$approval_kind" != "" ] && [ "$FORCE" -eq 0 ]; then
    echo "$REC already has a 承認 line (use --force to restamp)" >&2
    exit 2
  fi
  if [ "$AUTO" -eq 1 ] && [ "$approval_kind" = "time" ]; then
    echo "refusing to replace a timed 承認 with auto-commit: that would turn a commit-before-approval into a pass. Re-run approve --force without --auto-commit, then commit again" >&2
    exit 2
  fi
  if [ "$AUTO" -eq 1 ]; then
    stamp="auto-commit（確認ゲートを省略。commit 時刻の検査はしない）"
  else
    if [ -n "${CBJ_GATE_NOW:-}" ]; then
      echo "warning: CBJ_GATE_NOW is set (a test-only override); the 承認 time is not the real time" >&2
    fi
    now=${CBJ_GATE_NOW:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}
    if ! [[ "$now" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]; then
      echo "could not get the current UTC time (got: '$now')" >&2
      exit 3
    fi
    stamp="${now}（確認ゲートを通した時刻。これより後の commit だけが有効）"
  fi
  line="- 承認: $stamp"
  tmp="$REC.tmp.$$"
  awk -v add="$line" -v pl="$pr_line" -v al="$approval_line" '
    NR == al { next }
    { print }
    NR == pl { print add }
  ' "$REC" >"$tmp" || { rm -f "$tmp"; exit 3; }
  mv "$tmp" "$REC" || { rm -f "$tmp"; exit 3; }
  echo "approved: $line"
  echo "next: git commit the fixes, write コミット: in $REC, then run (before git push): gate-record.sh check $PR $N"
}

case "$CMD" in
  init) cmd_init ;;
  check) cmd_check ;;
  summary) cmd_summary ;;
  replies) cmd_replies ;;
  approve) cmd_approve ;;
esac
