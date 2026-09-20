#!/usr/bin/env bash
# 依頼時刻 T 以降のボット応答（Copilot: review / Codex: review または issue コメント）を待つ。
# 使い方: bot-wait.sh <PR番号> <T> [--copilot=0|1] [--codex=0|1] [--timeout=秒] [--codex-nudge=秒]
# 終了コード: 0=両方応答（DONE）, 1=タイムアウト, 3=API エラーが連続（認証切れ・PR 不在・ネットワーク断など）。
# Bash の run_in_background で使う想定。
#
# --codex-nudge=秒: Codex の PR 作成時の自動レビューは**発火しないことがある**（PR #37・#48 で実績あり）。
#   Codex を待っているのに、この秒数を過ぎても応答（レビュー／コメント）が無ければ `@codex review` を
#   **1 回だけ**投稿して待ち続ける（15 分待ち切ってから再依頼する無駄を省く）。この投稿は Codex への
#   依頼 1 回として数える。**G1（自動レビューを待つ最初のラウンド）でだけ指定する**こと。G2 以降は
#   bot-request.sh が既に `@codex review` を投稿しているため、指定すると二重依頼になる。既定は無効。
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
count_codex_comments() {
  local out
  out=$(gh api --paginate "repos/{owner}/{repo}/issues/$PR/comments?since=$T" \
    --jq '[.[] | select(.user.login=="chatgpt-codex-connector[bot]")] | length' 2>&1) || { echo "$out" >&2; return 1; }
  printf '%s\n' "$out" | awk '{s+=$1} END {print s+0}'
}

started=$SECONDS
deadline=$((SECONDS + TIMEOUT))
api_fails=0
while :; do
  if c=$(count_reviews "copilot-pull-request-reviewer[bot]") \
     && x=$(count_reviews "chatgpt-codex-connector[bot]") \
     && xc=$(count_codex_comments); then
    api_fails=0
    echo "copilot=$c codex=$((x + xc))"
    if { [ "$WAIT_COPILOT" -eq 0 ] || [ "$c" -ge 1 ]; } && { [ "$WAIT_CODEX" -eq 0 ] || [ $((x + xc)) -ge 1 ]; }; then
      echo DONE; exit 0
    fi
    wait_for=$INTERVAL
    # Codex の自動レビューが発火していなければ、一度だけ `@codex review` で起こす（--codex-nudge）。
    if [ "$NUDGE" -gt 0 ] && [ "$NUDGED" -eq 0 ] && [ "$WAIT_CODEX" -eq 1 ] && [ $((x + xc)) -eq 0 ] \
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
