#!/usr/bin/env bash
# gate-bodies.sh の整形部分（--format）の回帰テスト。ネットワーク不要。
# 使い方: .claude/skills/cbj-dev-cycle/scripts/test-gate-bodies.sh
#
# fixtures/gate-bodies/ の copilot-v2-*.md は PR #61 の実際の Copilot レビュー本文（公開コメント）。
# copilot-old-suppressed-comments.md は旧形式の見た目を模した合成データ（実物ではない）。
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
FIX="$HERE/fixtures/gate-bodies"
FAILS=0

# format <fixture> : 本文ファイルを bot レビュー JSON に包み、gate-bodies.sh --format の出力を返す。
format() {
  jq -n --rawfile b "$1" \
    '[{author: {login: "copilot-pull-request-reviewer"}, submittedAt: "2026-09-24T04:33:24Z", comments: {totalCount: 0}, url: "https://example.test/pull/1#pullrequestreview-1", body: $b}]' |
    "$HERE/gate-bodies.sh" --format
}

# expect <label> <output> <fixed-string> : 出力に文字列が含まれること。
expect() {
  if grep -qF -- "$3" <<<"$2"; then echo "ok   $1"; else echo "FAIL $1: 出力に見つからない: $3"; FAILS=$((FAILS + 1)); fi
}

# expect_absent <label> <output> <fixed-string> : 出力に文字列が含まれないこと。
expect_absent() {
  if grep -qF -- "$3" <<<"$2"; then echo "FAIL $1: 出力に含まれてはならない: $3"; FAILS=$((FAILS + 1)); else echo "ok   $1"; fi
}

# 新形式: Previously missed（スレッドの無い新規指摘）を取り逃さない
out=$(format "$FIX/copilot-v2-previously-missed.md")
expect "previously-missed: 判定見出し" "$out" "判定: 🔵 Needs a closer look"
expect "previously-missed: 節の見出し" "$out" "-- Previously missed (1)"
expect "previously-missed: 指摘のタイトル" "$out" "Explicitly clear stale prices when sale conversion fails"
expect "previously-missed: path:line（ゼロ幅スペース除去済み）" "$out" '`includes/Adapters/ColorMe/ColorMeAdapter.php:1101`'
expect "previously-missed: 指摘の本文" "$out" "omits both price fields but still sends the variant update"
expect "previously-missed: 重要度アイコンを [Medium] に置換" "$out" "Findings: 3 [Medium]"
expect "previously-missed: Open の項目（dbid 突合用）" "$out" "#discussion_r4089899212"
expect_absent "previously-missed: <picture> タグが残らない" "$out" "<picture>"

# 新形式: Open の New 印と、ファイル要約の表（What changed）を出さない
out=$(format "$FIX/copilot-v2-open-new-with-file-table.md")
expect "open-new: 判定見出し" "$out" "判定: 🟡 Changes recommended"
expect "open-new: Open の見出し" "$out" "-- Open (3)"
expect "open-new: New の印" "$out" "· New"
expect "open-new: Open の項目（dbid）" "$out" "#discussion_r4089899143"
expect_absent "open-new: ファイル要約の表を出さない" "$out" "TaxInclusivePriceTest.php"
expect_absent "open-new: What changed の見出しを出さない" "$out" "What changed in this PR"

# 新形式: Findings が複数の重要度を持つ行、New と既存スレッドの混在
out=$(format "$FIX/copilot-v2-findings-and-open.md")
expect "findings-and-open: Findings 行（重要度ごとの件数）" "$out" "Findings: 1 [High] · 3 [Medium]"
expect "findings-and-open: New 印付きの Open 項目" "$out" "#discussion_r4090024208) · New"
expect "findings-and-open: Open (4)" "$out" "-- Open (4)"
expect "findings-and-open: 新規スレッドのリンク" "$out" "#discussion_r4090024208"

# 旧形式: Suppressed comments を従来どおり出す
out=$(format "$FIX/copilot-old-suppressed-comments.md")
expect "old: 判定見出し" "$out" "判定: 🔵 Needs a closer look"
expect "old: Suppressed comments の見出し" "$out" "-- ## Suppressed comments (1)"
expect "old: 指摘の path:line" "$out" "**src/Example.php:42**"
expect "old: 指摘の本文" "$out" "The guard is skipped when the value is an empty string."

if [ "$FAILS" -ne 0 ]; then
  echo "test-gate-bodies: $FAILS 件失敗"
  exit 1
fi
echo "test-gate-bodies: all ok"
