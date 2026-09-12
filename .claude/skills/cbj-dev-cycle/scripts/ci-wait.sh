#!/usr/bin/env bash
# push 直後に CI ジョブが登録されるのを待ってから、完了まで watch する。
# 使い方: ci-wait.sh <PR番号>   （run_in_background で使う想定。終了コードは gh pr checks に従う）
set -uo pipefail
PR=${1:?usage: ci-wait.sh <pr>}
sleep 20
until gh pr checks "$PR" --json name,state --jq '[.[] | select(.state=="PENDING" or .state=="QUEUED" or .state=="IN_PROGRESS")] | length' 2>/dev/null | grep -qE '^[1-9]'; do
  sleep 15
done
gh pr checks "$PR" --watch --fail-fast
