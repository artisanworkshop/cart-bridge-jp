#!/usr/bin/env bash
# gate-bodies.sh の整形部分（--format）の回帰テスト。ネットワーク不要。
# 使い方: .claude/skills/cbj-dev-cycle/scripts/test-gate-bodies.sh
#
# fixtures/gate-bodies/ の copilot-v2-*.md は PR #61 / #64 の実際の Copilot レビュー本文（公開コメント）。
# ただし copilot-v2-unknown-section.md と copilot-old-suppressed-comments.md は合成データ（実物ではない）。
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

# load <label> <fixture> : format の結果を out に入れる。format が失敗しても set -e でスクリプトごと落とさず、
# ラベル付きの FAIL を記録して out を空にする（`out=$(format ...)` を単独の代入文にすると、失敗時に
# サマリを出す前に終了コードだけが残る。.claude/rules/skill-scripts.md 参照）。
out=""
load() {
  if out=$(format "$2"); then
    return 0
  fi
  echo "FAIL $1: gate-bodies.sh --format が失敗した（fixture: $2）"
  FAILS=$((FAILS + 1))
  out=""
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
load "copilot-v2-previously-missed" "$FIX/copilot-v2-previously-missed.md"
expect "previously-missed: 判定見出し" "$out" "判定: 🔵 Needs a closer look"
expect "previously-missed: 節の見出し" "$out" "-- Previously missed (1)"
expect "previously-missed: 指摘のタイトル" "$out" "Explicitly clear stale prices when sale conversion fails"
expect "previously-missed: path:line（ゼロ幅スペース除去済み）" "$out" '`includes/Adapters/ColorMe/ColorMeAdapter.php:1101`'
expect "previously-missed: 指摘の本文" "$out" "omits both price fields but still sends the variant update"
expect "previously-missed: 重要度アイコンを [Medium] に置換" "$out" "Findings: 3 [Medium]"
expect "previously-missed: Open の項目（dbid 突合用）" "$out" "#discussion_r4089899212"
expect_absent "previously-missed: <picture> タグが残らない" "$out" "<picture>"

# 新形式: Open の New 印と、ファイル要約の表（What changed）を出さない
load "copilot-v2-open-new-with-file-table" "$FIX/copilot-v2-open-new-with-file-table.md"
expect "open-new: 判定見出し" "$out" "判定: 🟡 Changes recommended"
expect "open-new: Open の見出し" "$out" "-- Open (3)"
expect "open-new: New の印" "$out" "· New"
expect "open-new: Open の項目（dbid）" "$out" "#discussion_r4089899143"
expect_absent "open-new: ファイル要約の表を出さない" "$out" "TaxInclusivePriceTest.php"
expect_absent "open-new: What changed の見出しを出さない" "$out" "What changed in this PR"

# 新形式: Findings が複数の重要度を持つ行、New と既存スレッドの混在
load "copilot-v2-findings-and-open" "$FIX/copilot-v2-findings-and-open.md"
expect "findings-and-open: Findings 行（重要度ごとの件数）" "$out" "Findings: 1 [High] · 3 [Medium]"
expect "findings-and-open: New 印付きの Open 項目" "$out" "#discussion_r4090024208) · New"
expect "findings-and-open: Open (4)" "$out" "-- Open (4)"
expect "findings-and-open: 新規スレッドのリンク" "$out" "#discussion_r4090024208"

# 未知の <details> 節は握りつぶさず出す（fail-open）。実物: 「Resolved since last review」（PR #64 のレビュー）
load "copilot-v2-resolved-since-last-review" "$FIX/copilot-v2-resolved-since-last-review.md"
expect "resolved-since-last-review: 未知の節の見出し" "$out" "-- Resolved since last review (1)"
expect "resolved-since-last-review: 未知の節の本文（項目とその dbid）" "$out" "[Low] [「スレット」を「スレッド」に修正](#discussion_r4092049277)"
expect "resolved-since-last-review: Findings なし" "$out" "Findings: None"
expect "resolved-since-last-review: 同居する Previously missed も出る" "$out" "-- Previously missed (1)"
expect "resolved-since-last-review: path:line（ゼロ幅スペース除去済み）" "$out" '`.claude/skills/cbj-dev-cycle/scripts/test-gate-bodies.sh:3`'

# 未知の節（合成）: 見出しと全項目を出し、ノイズと分かっている What changed だけを出さない
load "copilot-v2-unknown-section" "$FIX/copilot-v2-unknown-section.md"
expect "unknown-section: 見出し" "$out" "-- Some future section (2)"
expect "unknown-section: 1 件目の項目" "$out" "First future item"
expect "unknown-section: 2 件目の項目" "$out" "Second future item"
expect_absent "unknown-section: What changed の表を出さない" "$out" "Noise that must not be printed."

# 旧形式: Suppressed comments を従来どおり出す
load "copilot-old-suppressed-comments" "$FIX/copilot-old-suppressed-comments.md"
expect "old: 判定見出し" "$out" "判定: 🔵 Needs a closer look"
expect "old: Suppressed comments の見出し" "$out" "-- ## Suppressed comments (1)"
expect "old: 指摘の path:line" "$out" "**src/Example.php:42**"
expect "old: 指摘の本文" "$out" "The guard is skipped when the value is an empty string."

if [ "$FAILS" -ne 0 ]; then
  echo "test-gate-bodies: $FAILS 件失敗"
  exit 1
fi
echo "test-gate-bodies: all ok"
