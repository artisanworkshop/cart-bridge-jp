#!/usr/bin/env bash
# push 直後に、PR の HEAD コミットに対する CI（check-run）が登録されるのを待ってから完了まで watch する。
# 使い方: ci-wait.sh <PR番号> [--timeout=秒]   （run_in_background で使う想定）
# 終了コード: 最終的な HEAD に対する `gh pr checks --watch` の結果（失敗した check があれば非 0）。
#   3 = HEAD を取得できない／API エラーが連続／登録待ちのタイムアウト。
#
# 実装メモ（過去の失敗から）:
# - `gh pr checks` は pending 中に終了コード 8 を返し、`set -o pipefail` 下では「pending の件数 >= 1」の
#   パイプラインが常に失敗する。そのため登録状況は終了コードに依存しない check-runs API（HEAD sha に
#   紐づく check の総数）で判定する。全 check が先に完了していてもハングしない。
# - API の失敗は「未登録（0 件）」と区別し、連続したら即座に非ゼロで終了する（誤った原因で 600 秒待たない）。
# - **push 直後は `gh pr view` が古い HEAD を返すことがある**（GitHub 側の反映遅れ）。古い HEAD の check-run は
#   すでに登録済みなので、そのまま `gh pr checks` に進むと新しい HEAD には check が無く "no checks reported"
#   で抜けてしまう（PR #48 の G2 で実際に起きた）。ローカルの HEAD がこの PR のブランチなら、PR の HEAD が
#   それに一致するまで待つ。
# - **後続の push で先行する run が cancelled になる**（concurrency）。cancelled を「失敗」と報告せず、
#   監視が終わった時点の PR の HEAD が変わっていれば新しい HEAD を監視し直す。
set -uo pipefail
PR=${1:?usage: ci-wait.sh <pr> [--timeout=600]}
TIMEOUT=600; INTERVAL=15; MAX_API_FAILS=3; FAIL_INTERVAL=5; MAX_REWATCH=3
for arg in "${@:2}"; do
  case "$arg" in
    --timeout=*) TIMEOUT=${arg#*=} ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

pr_head() { gh pr view "$PR" --json headRefOid --jq .headRefOid 2>/dev/null; }

SHA=$(gh pr view "$PR" --json headRefOid --jq .headRefOid) || { echo "could not resolve PR #$PR" >&2; exit 3; }
if [ -z "$SHA" ]; then echo "could not resolve the head sha of PR #$PR" >&2; exit 3; fi

# ローカルの HEAD がこの PR のブランチなら、PR の HEAD が追いつくまで待つ（push 直後の古い HEAD を掴まない）。
PR_BRANCH=$(gh pr view "$PR" --json headRefName --jq .headRefName 2>/dev/null || true)
LOCAL_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)
LOCAL_SHA=$(git rev-parse HEAD 2>/dev/null || true)
if [ -n "$PR_BRANCH" ] && [ "$PR_BRANCH" = "$LOCAL_BRANCH" ] && [ -n "$LOCAL_SHA" ] && [ "$SHA" != "$LOCAL_SHA" ]; then
  echo "PR head ($SHA) is behind the local HEAD ($LOCAL_SHA); waiting for GitHub to catch up" >&2
  for _ in $(seq 1 12); do
    sleep 5
    SHA=$(pr_head || true)
    if [ "$SHA" = "$LOCAL_SHA" ]; then break; fi
  done
  if [ "$SHA" != "$LOCAL_SHA" ]; then
    echo "warning: PR head still differs from the local HEAD after 60s; watching $SHA" >&2
  fi
fi

deadline=$((SECONDS + TIMEOUT))
rewatch=0
while :; do
  # 1) この SHA の check-run が登録されるまで待つ。
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

  # 2) 完了まで watch する。check の集計がまだ反映されておらず "no checks reported" になる場合は、
  #    その終了コードを**信用せず**、期限まで再試行する。数回で諦めて最後の終了コードで抜けると、
  #    check の登録前に「失敗（または成功）」と報告してしまい、この修正の元の不具合が再発する。
  no_checks=1
  while [ "$no_checks" -eq 1 ]; do
    out=$(gh pr checks "$PR" --watch --fail-fast 2>&1); rc=$?
    printf '%s\n' "$out"
    case "$out" in
      *"no checks reported"*)
        if [ $((deadline - SECONDS)) -le 0 ]; then
          echo "checks for $SHA still not reported within ${TIMEOUT}s" >&2; exit 3
        fi
        sleep 10 ;;
      *) no_checks=0 ;;
    esac
  done

  # 3) 監視中に後続の push があった（先行の run は cancelled になりうる）なら、新しい HEAD を監視し直す。
  NEW=$(pr_head || true)
  if [ -n "$NEW" ] && [ "$NEW" != "$SHA" ] && [ "$rewatch" -lt "$MAX_REWATCH" ]; then
    echo "PR head moved $SHA -> $NEW while watching; watching the new head" >&2
    SHA=$NEW; rewatch=$((rewatch + 1))
    deadline=$((SECONDS + TIMEOUT))
    continue
  fi
  exit "$rc"
done
