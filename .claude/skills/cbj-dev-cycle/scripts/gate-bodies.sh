#!/usr/bin/env bash
# レビュー**本文**に書かれた指摘（系統 B）を抽出する。
# 使い方: gate-bodies.sh <PR番号> [T] [--raw]
#   T を省略すると全レビュー本文が対象。--raw で本文をそのまま出力（整形しない）。
#
# なぜ必要か: Copilot は判定が「🔵 Needs a closer look」のとき、インラインコメントを 1 件も
# 投稿せず（Comments generated: 0 new）、指摘を本文の `Suppressed comments` に畳むことがある。
# gate-threads.sh（reviewThreads）だけを見ると、この指摘を丸ごと取り逃す（PR #35 で実際に発生）。
# 本文指摘にはスレッドが無いため Resolve できない。処理結果はラウンド記録と PR サマリコメントに残すこと。
set -euo pipefail
PR=${1:?usage: gate-bodies.sh <pr> [T] [--raw]}
T=""; RAW=0
for arg in "${@:2}"; do
  case "$arg" in
    --raw) RAW=1 ;;
    *) T=$arg ;;
  esac
done
OWNER=$(gh repo view --json owner --jq .owner.login)
NAME=$(gh repo view --json name --jq .name)
REVIEWS=$(gh api graphql -f query="
query {
  repository(owner: \"$OWNER\", name: \"$NAME\") {
    pullRequest(number: $PR) {
      reviews(last: 30) {
        nodes { databaseId url state submittedAt body author { login } comments(first: 1) { totalCount } }
      }
    }
  }
}" --jq '.data.repository.pullRequest.reviews.nodes')

FILTERED=$(jq -c --arg t "$T" '[.[]
  | select((.author.login) as $l | $l == "copilot-pull-request-reviewer" or $l == "chatgpt-codex-connector")
  | select($t == "" or .submittedAt > $t)
  | select((.body // "") != "")]' <<<"$REVIEWS")

if [ "$(jq length <<<"$FILTERED")" -eq 0 ]; then
  echo "no bot review bodies (after T='${T:-any}')"; exit 0
fi

if [ "$RAW" -eq 1 ]; then
  jq -r '.[] | "----- \(.author.login) \(.submittedAt) inline=\(.comments.totalCount) \(.url)\n\(.body)"' <<<"$FILTERED"
  exit 0
fi

# 判定見出し・インライン件数・Suppressed comments（`**path:line**` + `* 本文`）を抜き出す。
# 本文に現れない区切り（CBJPGATE）でレビュー境界を示し、awk で整形する。
jq -r '.[] | "CBJPGATE\t\(.author.login)\t\(.submittedAt)\t\(.comments.totalCount)\t\(.url)\n\(.body)"' <<<"$FILTERED" |
awk '
  /^CBJPGATE\t/ {
    split($0, f, "\t");
    printf "\n===== %s  %s  inline=%s\n%s\n", f[2], f[3], f[4], f[5];
    verdict=""; in_sup=0; next
  }
  verdict == "" && /^### / { verdict=$0; printf "判定: %s\n", substr($0, 5); next }
  /^#+ *Suppressed comments/ { in_sup=1; printf "-- %s\n", $0; next }
  /^- \*\*(Files reviewed|Comments generated|Review effort)/ { print "   " $0; in_sup=0; next }
  in_sup { print "   " $0 }
'
echo
echo "※ 本文指摘（Suppressed comments / File summaries の重大度付き指摘）は Resolve できない。"
echo "   gate-threads.sh の結果と path:line で重複排除し、処理結果は G<n>.md と PR サマリコメントに残すこと。"
