#!/usr/bin/env bash
# 依頼時刻 T 以降のボット応答（Copilot: review / Codex: review・issue コメント・PR への 👍）を待つ。
# 使い方: bot-wait.sh <PR番号> <T> [--copilot=0|1] [--codex=0|1] [--timeout=秒] [--codex-nudge=秒]
# 終了コード: 0=両方応答（DONE）, 1=タイムアウト, 3=API エラーが連続（認証切れ・PR 不在・ネットワーク断など）。
# Bash の run_in_background で使う想定。
#
# --codex-nudge=秒: Codex の PR 作成時の自動レビューは**発火しないことがある**（PR #37・#48 で実績あり）。
#   Codex を待っているのに、この秒数を過ぎても応答（レビュー／コメント）が無ければ `@codex review` を
#   **1 回だけ**投稿して待ち続ける（15 分待ち切ってから再依頼する無駄を省く）。この投稿は Codex への
#   依頼 1 回として数える。**G1（自動レビューを待つ最初のラウンド）でだけ指定する**こと。G2 以降は
#   bot-request.sh が既に `@codex review` を投稿しているため、指定すると二重依頼になる。既定は無効。
#
# Codex の応答の判定（issue #128）: Codex の「Codex Review Summary」は 1 件の issue コメントを書き換えていく形で、状態の行が
#   `🔄 **Running**` → `✅ **Completed**` に変わり、対象 commit の 7 文字の sha が入る。このコメントは、Completed で、かつ commit が PR の
#   HEAD のときだけ応答と数える（Running・前の commit の Completed・状態の読めない形は数えない。PR #127 では Running の段階で DONE になり、
#   指摘を取る前に待ち直していた）。指摘が無いときの「Didn't find any major issues」のコメントは応答と数える。それ以外の Codex のコメント
#   （環境の作成を促す定型コメント・エラーや利用上限の通知など）は応答と数えず、コメントの ID を一度だけ表示する（PR #129 G1 で、`@codex` を含む
#   PR 本文への定型コメントを応答と数えて DONE になり、Codex のレビューを待たずに進むところだった）。知らない形の応答は TIMEOUT になるので、
#   gate-turn.sh が続けて表示する Codex のコメントを読んで判断する。
#   PR への 👍（`chatgpt-codex-connector[bot]` の +1。T より後）も、指摘が無いときの応答として数える。
#   まとめのコメントが Running なら Codex は動いているので、`--codex-nudge` は投稿しない（走行中のレビューへの二重依頼を避ける。PR #128 R1-1）。
#   待たない bot は問い合わせない（進捗の行には `-` を出す。--copilot=0 で Codex の、--codex=0 で Copilot の API の失敗に左右されない。PR #129 G1-1）。
#   限界: commit を照合するのはまとめのコメントだけで、レビュー・ほかのコメント・👍 は提出の時刻で数える（どれもまとめの Completed より
#   数秒早く届く）。待つ間に PR の HEAD が動くと、旧 HEAD への応答で DONE になりうる（gate-turn.sh --first が CI 待ちの前後の HEAD を比べるのはこのため）。
set -uo pipefail
PR=${1:?usage: bot-wait.sh <pr> <T> [--copilot=1] [--codex=1] [--timeout=900]}
T=${2:?missing T (UTC ISO8601, e.g. 2026-09-10T16:14:36Z)}
WAIT_COPILOT=1; WAIT_CODEX=1; TIMEOUT=900; INTERVAL=60; NUDGE=0; NUDGED=0
MAX_API_FAILS=3; FAIL_INTERVAL=5
for arg in "${@:3}"; do
  case "$arg" in
    --copilot=*) WAIT_COPILOT=${arg#*=} ;;
    --codex=*)   WAIT_CODEX=${arg#*=} ;;
    --timeout=*) TIMEOUT=${arg#*=} ;;
    --codex-nudge=*) NUDGE=${arg#*=} ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

# REST は 1 ページ 30 件が既定なので --paginate で全ページを走査し、ページ毎の件数を合算する。
# gh api の失敗は握りつぶさず（0 件扱いにしない）、呼び出し元へ非ゼロで返す。
count_reviews() { # $1 = bot login
  local out
  out=$(gh api --paginate "repos/{owner}/{repo}/pulls/$PR/reviews" \
    --jq "[.[] | select(.user.login==\"$1\" and .submitted_at > \"$T\")] | length" 2>&1) || { echo "$out" >&2; return 1; }
  printf '%s\n' "$out" | awk '{s+=$1} END {print s+0}'
}
# Codex の issue コメント（T 以降に作成・更新されたもの）のうち、応答と数えるものの件数と、Running のまとめのコメントの commit
# （無ければ -）を 1 行目に「<件数> <commit>」で出す。まとめのコメントは Completed かつ commit が PR の HEAD のときだけ数える（上の説明）。
# 数えなかった理由は 2 行目以降に「note <文>」で出す（呼び出し側が、同じ文をポーリングのたびに出さないように一度だけ表示する）。
count_codex_comments() {
  local out head line n=0 sha running=- notes=""
  head=$(gh pr view "$PR" --json headRefOid --jq .headRefOid 2>&1) || { echo "$head" >&2; return 1; }
  [[ "$head" =~ ^[0-9a-f]{40}$ ]] || { echo "unexpected PR head: '$head'" >&2; return 1; }
  out=$(gh api --paginate "repos/{owner}/{repo}/issues/$PR/comments?since=$T" \
    --jq '.[] | select(.user.login=="chatgpt-codex-connector[bot]")
      | if (.body | contains("<!-- codex-pull-request-review-summary -->")) then
          ((.body | capture("(?<status>Completed|Running)\\*\\*[^|]*\\|\\s*`(?<sha>[0-9a-f]{7,40})`")?) // {status: "unknown", sha: "-"})
          | "summary \(.status) \(.sha)"
        elif (.body | contains("t find any major issues")) then "nofindings"
        else "other \(.id)" end' 2>&1) || { echo "$out" >&2; return 1; }
  while IFS= read -r line; do
    case "$line" in
      nofindings) n=$((n + 1)) ;;
      "other "*) notes+=$'\n'"note comment ${line#other } is not a review result; not counted (read it in the PR)" ;;
      "summary Completed "*)
        sha=${line#summary Completed }
        if [ "${head:0:${#sha}}" = "$sha" ]; then n=$((n + 1)); else notes+=$'\n'"note the summary is Completed for ${sha}, not the PR head ${head:0:7}"; fi
        ;;
      "summary Running "*) running=${line#summary Running } ;;
      "summary "*) notes+=$'\n'"note the summary comment has an unrecognized status; not counted" ;;
      '') ;;
      *) echo "codex: unexpected classification '$line'" >&2; return 1 ;;
    esac
  done <<<"$out"
  echo "$n $running$notes"
}
# PR への Codex の 👍（T より後）。指摘が無いときの応答。
count_codex_thumbs() {
  local out
  out=$(gh api --paginate "repos/{owner}/{repo}/issues/$PR/reactions" \
    --jq "[.[] | select(.user.login==\"chatgpt-codex-connector[bot]\" and .content==\"+1\" and .created_at > \"$T\")] | length" 2>&1) || { echo "$out" >&2; return 1; }
  printf '%s\n' "$out" | awk '{s+=$1} END {print s+0}'
}

started=$SECONDS
deadline=$((SECONDS + TIMEOUT))
api_fails=0
NOTED=""
while :; do
  # 待たない bot は問い合わせない（Codex を待たないときは、Codex のレビュー・コメント・👍 と PR の HEAD も）。
  c=-; x=-; cc="0 -"; xt=0
  if { [ "$WAIT_COPILOT" -eq 0 ] || c=$(count_reviews "copilot-pull-request-reviewer[bot]"); } \
     && { [ "$WAIT_CODEX" -eq 0 ] \
          || { x=$(count_reviews "chatgpt-codex-connector[bot]") && cc=$(count_codex_comments) && xt=$(count_codex_thumbs); }; }; then
    api_fails=0
    first=${cc%%$'\n'*}
    running=${first#* }
    [ "$WAIT_CODEX" -eq 0 ] || x=$((x + ${first%% *} + xt))
    # 数えなかった理由は、同じ文を一度だけ出す（毎回出すと進捗の行と交互になり、gate-turn.sh の同じ行の畳み込みが効かない）。
    while IFS= read -r line; do
      case "$line" in
        "note "*) case "$NOTED" in *"|${line#note }|"*) ;; *) NOTED+="|${line#note }|"; echo "codex: ${line#note }" >&2 ;; esac ;;
      esac
    done <<<"$cc"
    # Running は進捗の行に添える（別の行にすると gate-turn.sh の同じ行の畳み込みが効かず、ポーリングのたびに行が増える。R1-9）。
    if [ "$running" = "-" ]; then echo "copilot=$c codex=$x"; else echo "copilot=$c codex=$x (codex review running for $running)"; fi
    if { [ "$WAIT_COPILOT" -eq 0 ] || [ "$c" -ge 1 ]; } && { [ "$WAIT_CODEX" -eq 0 ] || [ "$x" -ge 1 ]; }; then
      echo DONE; exit 0
    fi
    wait_for=$INTERVAL
    # Codex の自動レビューが発火していなければ、一度だけ `@codex review` で起こす（--codex-nudge）。
    if [ "$NUDGE" -gt 0 ] && [ "$NUDGED" -eq 0 ] && [ "$WAIT_CODEX" -eq 1 ] && [ "$x" -eq 0 ] && [ "$running" = "-" ] \
       && [ $((SECONDS - started)) -ge "$NUDGE" ]; then
      if gh pr comment "$PR" --body "@codex review" >/dev/null 2>&1; then
        NUDGED=1
        echo "codex: no response after ${NUDGE}s; posted '@codex review' (counts as Codex request #1)"
      else
        echo "codex nudge failed to post; will retry on the next poll" >&2
      fi
    fi
  else
    # 一時的なネットワーク断は短い間隔で再試行し、連続して失敗したら「応答なし」ではなく API エラーとして終了する。
    api_fails=$((api_fails + 1))
    echo "gh api failed ($api_fails/$MAX_API_FAILS)" >&2
    if [ "$api_fails" -ge "$MAX_API_FAILS" ]; then echo API_ERROR; exit 3; fi
    wait_for=$FAIL_INTERVAL
  fi
  remaining=$((deadline - SECONDS))
  if [ "$remaining" -le 0 ]; then break; fi
  # 指定した上限を守るため、残り時間と通常間隔の小さい方だけ待つ。
  sleep $(( remaining < wait_for ? remaining : wait_for ))
done
echo TIMEOUT; exit 1
