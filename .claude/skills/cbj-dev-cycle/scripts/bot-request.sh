#!/usr/bin/env bash
# レビューボットへの依頼を投稿し、依頼時刻 T（UTC, ISO8601）を標準出力に返す。
# 使い方: bot-request.sh <PR番号> [both|copilot|codex]
#   Copilot はレビュアー指名（gh pr edit --add-reviewer @copilot）、
#   Codex は "@codex review" コメント（このリポジトリでは push 時の自動レビューが無効）で起動する。
set -euo pipefail
PR=${1:?usage: bot-request.sh <pr> [both|copilot|codex]}
WHO=${2:-both}
T=$(date -u +%Y-%m-%dT%H:%M:%SZ)
case "$WHO" in
  copilot|both) gh pr edit "$PR" --add-reviewer @copilot >/dev/null ;;
esac
case "$WHO" in
  codex|both) gh pr comment "$PR" --body "@codex review" >/dev/null ;;
esac
echo "$T"
