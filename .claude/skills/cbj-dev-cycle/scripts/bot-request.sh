#!/usr/bin/env bash
# レビューボットへの依頼を投稿し、依頼時刻 T（UTC, ISO8601）を標準出力に返す。
# 使い方: bot-request.sh <PR番号> [both|copilot|codex]
#   Copilot はレビュアー指名（gh pr edit --add-reviewer @copilot）、
#   Codex は "@codex review" コメントで起動する（PR 作成時の自動レビュー後の再依頼用）。
set -euo pipefail
PR=${1:?usage: bot-request.sh <pr> [both|copilot|codex]}
WHO=${2:-both}
case "$WHO" in
  both|copilot|codex) ;;
  *) echo "usage: bot-request.sh <pr> [both|copilot|codex] (got: '$WHO')" >&2; exit 2 ;;
esac
T=$(date -u +%Y-%m-%dT%H:%M:%SZ)
case "$WHO" in
  copilot|both) gh pr edit "$PR" --add-reviewer @copilot >/dev/null ;;
esac
case "$WHO" in
  codex|both) gh pr comment "$PR" --body "@codex review" >/dev/null ;;
esac
echo "$T"
