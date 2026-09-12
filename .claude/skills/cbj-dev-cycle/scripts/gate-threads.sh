#!/usr/bin/env bash
# PR の未解決レビュースレッドのうち、ボット（Copilot / Codex）が T 以降に起こしたものを列挙する。
# 使い方: gate-threads.sh <PR番号> [T] [--json]
#   T を省略すると未解決のボットスレッドを全て出す。--json で GraphQL の生ノード（JSON配列）を出力。
# 出力（既定）: === [n] <author> <path>:<line> id=<threadId> dbid=<最初のコメントID> の後に URL と本文。
#   threadId は gate-resolve.sh に、dbid は gate-reply.sh に渡す。
set -euo pipefail
PR=${1:?usage: gate-threads.sh <pr> [T] [--json]}
T=""; JSON=0
for arg in "${@:2}"; do
  case "$arg" in
    --json) JSON=1 ;;
    *) T=$arg ;;
  esac
done
OWNER=$(gh repo view --json owner --jq .owner.login)
NAME=$(gh repo view --json name --jq .name)
CURSOR=""; ALL="[]"
while :; do
  AFTER=""; [ -n "$CURSOR" ] && AFTER=", after: \"$CURSOR\""
  PAGE=$(gh api graphql -f query="
query {
  repository(owner: \"$OWNER\", name: \"$NAME\") {
    pullRequest(number: $PR) {
      reviewThreads(first: 50$AFTER) {
        pageInfo { hasNextPage endCursor }
        nodes {
          id isResolved isOutdated path line
          comments(first: 5) { nodes { databaseId body author { login } createdAt url } }
        }
      }
    }
  }
}")
  ALL=$(jq -c --argjson a "$ALL" '$a + .data.repository.pullRequest.reviewThreads.nodes' <<<"$PAGE")
  if [ "$(jq -r '.data.repository.pullRequest.reviewThreads.pageInfo.hasNextPage' <<<"$PAGE")" != "true" ]; then break; fi
  CURSOR=$(jq -r '.data.repository.pullRequest.reviewThreads.pageInfo.endCursor' <<<"$PAGE")
done
FILTERED=$(jq -c --arg t "$T" '[.[] | select(.isResolved == false)
  | select((.comments.nodes[0].author.login) as $l | $l == "copilot-pull-request-reviewer" or $l == "chatgpt-codex-connector")
  | select($t == "" or .comments.nodes[0].createdAt > $t)]' <<<"$ALL")
if [ "$JSON" -eq 1 ]; then echo "$FILTERED"; exit 0; fi
echo "threads: $(jq length <<<"$ALL") / unresolved bot threads (after T='${T:-any}'): $(jq length <<<"$FILTERED")"
jq -r 'to_entries[] | (.key + 1) as $n | .value | .comments.nodes[0] as $c
  | "\n=== [\($n)] \($c.author.login) \(.path):\(.line) id=\(.id) dbid=\($c.databaseId)\n\($c.url)\n\($c.body)"' <<<"$FILTERED"
