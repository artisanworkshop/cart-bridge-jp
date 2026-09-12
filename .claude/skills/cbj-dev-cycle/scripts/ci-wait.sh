#!/usr/bin/env bash
# push 直後に、PR の HEAD コミットに対する CI（check-run）が登録されるのを待ってから完了まで watch する。
# 使い方: ci-wait.sh <PR番号> [--timeout=秒]   （run_in_background で使う想定）
# 終了コード: gh pr checks --watch の結果（失敗した check があれば非 0）。
#   3 = HEAD を取得できない／API エラーが連続／登録待ちのタイムアウト。
# 実装メモ: `gh pr checks` は pending 中に終了コード 8 を返し、`set -o pipefail` 下では
# 「pending の件数 >= 1」のパイプラインが常に失敗する。そのため登録状況は終了コードに依存しない
# check-runs API（HEAD sha に紐づく check の総数）で判定する。全 check が先に完了していてもハングしない。
# API の失敗は「未登録（0 件）」と区別し、連続したら即座に非ゼロで終了する（誤った原因で 600 秒待たない）。
set -uo pipefail
PR=${1:?usage: ci-wait.sh <pr> [--timeout=600]}
TIMEOUT=600; INTERVAL=15; MAX_API_FAILS=3; FAIL_INTERVAL=5
for arg in "${@:2}"; do
  case "$arg" in
    --timeout=*) TIMEOUT=${arg#*=} ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done
SHA=$(gh pr view "$PR" --json headRefOid --jq .headRefOid) || { echo "could not resolve PR #$PR" >&2; exit 3; }
if [ -z "$SHA" ]; then echo "could not resolve the head sha of PR #$PR" >&2; exit 3; fi
deadline=$((SECONDS + TIMEOUT))
api_fails=0
while :; do
  if total=$(gh api "repos/{owner}/{repo}/commits/$SHA/check-runs" --jq '.total_count' 2>&1); then
    api_fails=0
    if [ "${total:-0}" -ge 1 ]; then break; fi
    wait_for=$INTERVAL
  else
    api_fails=$((api_fails + 1))
    echo "gh api failed ($api_fails/$MAX_API_FAILS): $total" >&2
    if [ "$api_fails" -ge "$MAX_API_FAILS" ]; then exit 3; fi
    wait_for=$FAIL_INTERVAL
  fi
  remaining=$((deadline - SECONDS))
  if [ "$remaining" -le 0 ]; then echo "no check-runs registered for $SHA within ${TIMEOUT}s" >&2; exit 3; fi
  # 指定した上限を守るため、残り時間と通常間隔の小さい方だけ待つ。
  sleep $(( remaining < wait_for ? remaining : wait_for ))
done
gh pr checks "$PR" --watch --fail-fast
