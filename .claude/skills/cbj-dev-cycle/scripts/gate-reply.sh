#!/usr/bin/env bash
# レビュースレッドに返信する（Resolve はしない）。本文は引数または標準入力（-）。
# 使い方: gate-reply.sh <PR番号> <最初のコメントの databaseId> "<本文>" | gate-reply.sh <PR> <dbid> - <<'EOF' ... EOF
set -euo pipefail
PR=${1:?usage: gate-reply.sh <pr> <comment-database-id> <text|->}
DBID=${2:?missing comment database id}
TEXT=${3:?missing text (or - for stdin)}
[ "$TEXT" = "-" ] && TEXT=$(cat)
gh api "repos/{owner}/{repo}/pulls/$PR/comments" -f body="$TEXT" -F in_reply_to="$DBID" --jq '.html_url'
