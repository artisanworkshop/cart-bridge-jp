#!/usr/bin/env bash
# レビュースレッドを Resolve する（修正済みスレッドのみに使う。保留スレッドは Resolve しない）。
# 使い方: gate-resolve.sh <threadId>...   （threadId は gate-threads.sh の id=）
# 終了コード: 0 = すべて isResolved が true / 1 = 応答が true でないスレッドがある（GraphQL が成功しても Resolve されていないことがある。
#   0 で終えると、呼び出し側〔gate-record.sh post〕が Resolve できたものとしてサマリを投稿する。PR #129 G3-B1）/ gh の失敗はその終了コード。
set -euo pipefail
[ $# -ge 1 ] || { echo "usage: gate-resolve.sh <threadId>..." >&2; exit 2; }
unresolved=0
for id in "$@"; do
  r=$(gh api graphql -f query="mutation { resolveReviewThread(input: {threadId: \"$id\"}) { thread { id isResolved } } }" --jq '.data.resolveReviewThread.thread.isResolved')
  echo "$id resolved=$r"
  if [ "$r" != true ]; then
    echo "$id was not resolved (isResolved: ${r:-empty})" >&2
    unresolved=1
  fi
done
exit "$unresolved"
