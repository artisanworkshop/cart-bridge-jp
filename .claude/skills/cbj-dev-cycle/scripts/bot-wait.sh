#!/usr/bin/env bash
# 依頼時刻 T 以降のボット応答（Copilot: review / Codex: review または issue コメント）を待つ。
# 使い方: bot-wait.sh <PR番号> <T> [--copilot=0|1] [--codex=0|1] [--timeout=秒]
# 終了コード: 0=両方応答（DONE）, 1=タイムアウト, 3=API エラーが連続（認証切れ・PR 不在・ネットワーク断など）。
# Bash の run_in_background で使う想定。
set -uo pipefail
PR=${1:?usage: bot-wait.sh <pr> <T> [--copilot=1] [--codex=1] [--timeout=900]}
T=${2:?missing T (UTC ISO8601, e.g. 2026-09-10T16:14:36Z)}
WAIT_COPILOT=1; WAIT_CODEX=1; TIMEOUT=900; INTERVAL=60
MAX_API_FAILS=3; FAIL_INTERVAL=5
for arg in "${@:3}"; do
  case "$arg" in
    --copilot=*) WAIT_COPILOT=${arg#*=} ;;
    --codex=*)   WAIT_CODEX=${arg#*=} ;;
    --timeout=*) TIMEOUT=${arg#*=} ;;
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
