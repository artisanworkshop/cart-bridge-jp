#!/usr/bin/env bash
# レビュー**本文**に書かれた指摘（系統 B）を抽出する。
# 使い方: gate-bodies.sh <PR番号> [T] [--raw]
#         gate-bodies.sh --format   （標準入力の bot レビュー JSON 配列を整形して出す。テスト用）
#   T を省略すると全レビュー本文が対象。--raw で本文をそのまま出力（整形しない）。
#
# なぜ必要か: Copilot は判定が「🔵 Needs a closer look」のとき、インラインコメントを 1 件も
# 投稿せず（Comments generated: 0 new）、指摘を本文に畳むことがある。gate-threads.sh
# （reviewThreads）だけを見ると、この指摘を丸ごと取り逃す（PR #35 で実際に発生）。
# 本文指摘にはスレッドが無いため Resolve できない。処理結果はラウンド記録と PR サマリコメントに残すこと。
#
# 本文の形式は 2 系統ある（どちらも整形して出す）:
#   - 旧形式: `Suppressed comments` の節（`**path:line**` + `* 本文`）
#   - 新形式（`<!-- ccr-overview-v2 -->`）: `**Findings:**` の件数、`<details>` の節
#       `Open (N)`（既存スレッドへのリンク `#discussion_r<dbid>` と `· New` の印）、
#       `Previously missed (N)`（**スレットの無い新規指摘**。変更していないコードへの指摘）。
#       `What changed in this PR`（ファイル要約の表）はノイズなので出さない。
#     未知の `<details>` 節は出す側に倒す（指摘を握りつぶさない）。
set -euo pipefail

# 標準入力の JSON 配列（[{author,submittedAt,comments,url,body}]）を整形する。
# `<picture>` の重要度アイコンは `[Medium]` 等に置換し、パスに混ざるゼロ幅スペース（U+200B）は取り除く
# （path:line を既存スレッドと突合できるようにするため）。本文に現れない区切り（CBJPGATE）でレビュー境界を示す。
format_bodies() {
  jq -r '.[] | "CBJPGATE\t\(.author.login)\t\(.submittedAt)\t\(.comments.totalCount)\t\(.url)\n\(.body)"' |
  perl -CSD -pe 's#<picture>.*?alt="(\w+) severity".*?</picture>#[$1]#g; s/\x{200B}//g' |
  awk '
    /^CBJPGATE\t/ {
      split($0, f, "\t");
      printf "\n===== %s  %s  inline=%s\n%s\n", f[2], f[3], f[4], f[5];
      verdict=""; in_sup=0; depth=0; skip=0; next
    }
    verdict == "" && /^### / { verdict=$0; printf "判定: %s\n", substr($0, 5); next }
    /^\*\*(Review effort|Findings):\*\*/ { t=$0; gsub(/\*\*/, "", t); sub(/ +$/, "", t); printf "   %s\n", t; next }
    /^#+ *Suppressed comments/ { in_sup=1; printf "-- %s\n", $0; next }
    /^- \*\*(Files reviewed|Comments generated|Review effort)/ { print "   " $0; in_sup=0; next }
    /^<details/ { depth++; next }
    /^<\/details>/ { depth--; if (skip && depth < skip_depth) skip=0; next }
    /^<summary>/ {
      t=$0; gsub(/<[^>]*>/, "", t);
      if (t ~ /^What changed in this PR/) { skip=1; skip_depth=depth; next }
      if (skip) next
      if (depth <= 1) printf "-- %s\n", t; else printf "   * %s\n", t;
      next
    }
    skip { next }
    depth >= 1 && NF { print "   " $0; next }
    in_sup && NF { print "   " $0 }
  '
}

if [ "${1:-}" = "--format" ]; then
  format_bodies
  exit 0
fi

PR=${1:?usage: gate-bodies.sh <pr> [T] [--raw] | gate-bodies.sh --format}
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

format_bodies <<<"$FILTERED"
echo
echo "※ 本文指摘（Suppressed comments / Previously missed / File summaries の重大度付き指摘）は Resolve できない。"
echo "   Open の各項目（#discussion_r<dbid>）は gate-threads.sh の dbid と突合し、既存スレッドなら重複として扱う。"
echo "   Previously missed はスレッドが無い新規指摘。処理結果は G<n>.md と PR サマリコメントに残すこと。"
