#!/usr/bin/env bash
# push 直後に、PR の HEAD コミットに対する CI（check-run）が登録されるのを待ってから完了まで watch する。
# 使い方: ci-wait.sh <PR番号> [--timeout=秒]   （run_in_background で使う想定）
# 終了コード: gh pr checks --watch の結果（失敗した check があれば非 0）。登録待ちのタイムアウトは 3。
# 実装メモ: `gh pr checks` は pending 中に終了コード 8 を返し、`set -o pipefail` 下では
# 「pending の件数 >= 1」のパイプラインが常に失敗する。そのため登録状況は終了コードに依存しない
# check-runs API（HEAD sha に紐づく check の総数）で判定する。全 check が先に完了していてもハングしない。
set -uo pipefail
PR=${1:?usage: ci-wait.sh <pr> [--timeout=600]}
TIMEOUT=600
for arg in "${@:2}"; do
  case "$arg" in
    --timeout=*) TIMEOUT=${arg#*=} ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done
SHA=$(gh pr view "$PR" --json headRefOid --jq .headRefOid)
deadline=$((SECONDS + TIMEOUT))
while :; do
  total=$(gh api "repos/{owner}/{repo}/commits/$SHA/check-runs" --jq '.total_count' 2>/dev/null || echo 0)
  if [ "${total:-0}" -ge 1 ]; then break; fi
  if [ "$SECONDS" -ge "$deadline" ]; then echo "no check-runs registered for $SHA within ${TIMEOUT}s" >&2; exit 3; fi
  sleep 15
done
gh pr checks "$PR" --watch --fail-fast
