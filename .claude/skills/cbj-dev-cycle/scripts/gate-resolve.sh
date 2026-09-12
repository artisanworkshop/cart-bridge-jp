#!/usr/bin/env bash
# レビュースレッドを Resolve する（修正済みスレッドのみに使う。保留スレッドは Resolve しない）。
# 使い方: gate-resolve.sh <threadId>...   （threadId は gate-threads.sh の id=）
set -euo pipefail
[ $# -ge 1 ] || { echo "usage: gate-resolve.sh <threadId>..." >&2; exit 2; }
for id in "$@"; do
  r=$(gh api graphql -f query="mutation { resolveReviewThread(input: {threadId: \"$id\"}) { thread { id isResolved } } }" --jq '.data.resolveReviewThread.thread.isResolved')
  echo "$id resolved=$r"
done
