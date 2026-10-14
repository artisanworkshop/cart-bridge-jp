#!/usr/bin/env bash
# plugin-check-zip.sh の集計（--summarize）の回帰テスト（wp-env・ネットワーク不要）。
# Plugin Check の strict-json の形（file・line・column・type・code・message の配列）と、見つかったものが無いときの
# 「Success: …」の行を合成して、件数の表・ERROR の一覧・読めない結果の拒否・終了コードを確かめる。
# 使い方: bin/test-plugin-check-zip.sh
set -euo pipefail
if ! HERE=$(cd "$(dirname "$0")" && pwd) || [ -z "$HERE" ]; then
  echo "test-plugin-check-zip: could not resolve the script directory" >&2
  exit 1
fi
PCZ="$HERE/plugin-check-zip.sh"
FAILS=0
if ! WORK=$(mktemp -d) || [ -z "$WORK" ]; then
  echo "test-plugin-check-zip: could not create a temporary directory (mktemp failed)" >&2
  exit 1
fi
# `set -e` と EXIT トラップの両方があると、macOS の bash 3.2 は異常終了でも 0 を返しうる。最後まで走ったことを DONE で確かめる
# （.claude/rules/skill-scripts.md。test-gate-record.sh と同じ）。
DONE=0
trap 'rm -rf "$WORK"; if [ "$DONE" != 1 ]; then echo "test-plugin-check-zip: stopped before the end (an unexpected error aborted the script)" >&2; exit 1; fi' EXIT

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1"; FAILS=$((FAILS + 1)); }

OUT=
RC=0
# 標準出力と標準エラーをまとめて受ける（終了コードを消費する形なので、失敗しても set -e では止まらない）。
run() {
  if OUT=$("$PCZ" "$@" 2>&1 </dev/null); then RC=0; else RC=$?; fi
}
assert_rc() { if [ "$RC" -eq "$2" ]; then ok "$1"; else fail "$1 (exit ${RC}, expected $2): ${OUT}"; fi; }
# 行全体の一致（部分一致だと、件数の違う行で素通りする）。grep は -q を使わず読み切る（printf の SIGPIPE を避ける）。
assert_line() { if printf '%s\n' "$OUT" | grep -xF -- "$2" >/dev/null; then ok "$1"; else fail "$1 (line not found: $2): ${OUT}"; fi; }
assert_no_line() { if printf '%s\n' "$OUT" | grep -xF -- "$2" >/dev/null; then fail "$1 (unexpected line: $2): ${OUT}"; else ok "$1"; fi; }
assert_has() { if printf '%s\n' "$OUT" | grep -F -- "$2" >/dev/null; then ok "$1"; else fail "$1 (text not found: $2): ${OUT}"; fi; }

# --- フィクスチャ（無料版 1.0.0・Pro 0.1.0 の zip の結果から形を写した。値は合成） ---
cat >"$WORK/warnings.json" <<'EOS'
[{"file":"includes\/Core\/Uninstaller.php","line":40,"column":5,"type":"WARNING","code":"WordPress.DB.DirectDatabaseQuery.DirectQuery","message":"Use of a direct database call is discouraged."},{"file":"includes\/Core\/Uninstaller.php","line":40,"column":5,"type":"WARNING","code":"WordPress.DB.DirectDatabaseQuery.NoCaching","message":"Direct database call without caching detected."},{"file":"includes\/Sync\/JobManager.php","line":120,"column":9,"type":"WARNING","code":"WordPress.DB.DirectDatabaseQuery.DirectQuery","message":"Use of a direct database call is discouraged."}]
EOS
cat >"$WORK/errors.json" <<'EOS'
[{"file":"readme.txt","line":0,"column":0,"type":"ERROR","code":"no_plugin_readme","message":"The plugin readme.txt does not exist."},{"file":"includes\/Admin\/RestController.php","line":496,"column":145,"type":"ERROR","code":"WordPress.WP.I18n.TextDomainMismatch","message":"Mismatched text domain. Expected 'a' but got 'b'."},{"file":"composer.json","line":0,"column":0,"type":"WARNING","code":"missing_composer_json_file","message":"The composer.json file is missing."}]
EOS
printf 'Success: Checks complete. No errors found.\n' >"$WORK/success.txt"
printf 'Error: Plugin with slug cart-bridge-jp is not installed.\n' >"$WORK/not-json.txt"
printf '[{"file":"a.php","line":1,"column":1,"type":"WARNING","message":"no code"}]\n' >"$WORK/no-code.json"
printf '[{"file":"a.php","line":1,"column":1,"type":"NOTICE","code":"x","message":"m"}]\n' >"$WORK/unknown-type.json"
# 配列ではなくオブジェクト（値は形の正しい 1 件）。要素の検査だけでは通ってしまう形。
printf '{"k":{"file":"a.php","line":1,"column":1,"type":"WARNING","code":"x","message":"m"}}\n' >"$WORK/not-array.json"
: >"$WORK/empty.json"

# --- 警告だけ: 0 で終わり、コードごとの件数が多い順に並ぶ。1 件ずつの行は --verbose のときだけ ---
run --summarize "$WORK/warnings.json"
assert_rc "warnings only: exit 0" 0
assert_line "warnings only: totals" "ERROR 0 / WARNING 3"
assert_line "warnings only: DirectQuery x2" "      2  WARNING  WordPress.DB.DirectDatabaseQuery.DirectQuery"
assert_line "warnings only: NoCaching x1" "      1  WARNING  WordPress.DB.DirectDatabaseQuery.NoCaching"
assert_line "warnings only: verdict" "plugin-check-zip: no errors"
assert_no_line "warnings only: no per-finding lines without --verbose" "  WARNING  includes/Sync/JobManager.php:120  WordPress.DB.DirectDatabaseQuery.DirectQuery  Use of a direct database call is discouraged."

run --summarize "$WORK/warnings.json" --verbose
assert_rc "verbose: exit 0" 0
assert_line "verbose: per-finding line" "  WARNING  includes/Sync/JobManager.php:120  WordPress.DB.DirectDatabaseQuery.DirectQuery  Use of a direct database call is discouraged."

# --- ERROR あり: 1 で終わり、ERROR の行が種類の表の先頭と 1 件ずつの一覧に出る。WARNING の 1 件ずつの行は出ない ---
run --summarize "$WORK/errors.json"
assert_rc "errors: exit 1" 1
assert_line "errors: totals" "ERROR 2 / WARNING 1"
assert_line "errors: TextDomainMismatch row" "      1  ERROR    WordPress.WP.I18n.TextDomainMismatch"
assert_line "errors: no_plugin_readme row" "      1  ERROR    no_plugin_readme"
assert_line "errors: composer row" "      1  WARNING  missing_composer_json_file"
assert_line "errors: readme error listed with file:line" "  ERROR  readme.txt:0  no_plugin_readme  The plugin readme.txt does not exist."
assert_line "errors: verdict" "plugin-check-zip: 2 error(s); fix them before releasing"
assert_no_line "errors: warning not listed without --verbose" "  WARNING  composer.json:0  missing_composer_json_file  The composer.json file is missing."
assert_no_line "errors: no 'no errors' verdict" "plugin-check-zip: no errors"

# --- 見つかったものが無いとき Plugin Check は JSON ではなく Success の行を出す ---
run --summarize "$WORK/success.txt"
assert_rc "success line: exit 0" 0
assert_line "success line: totals" "ERROR 0 / WARNING 0"
assert_line "success line: verdict" "plugin-check-zip: no errors"

# --- 読めない結果は「確かめられない」として 1 で止める（0 件と読まない） ---
run --summarize "$WORK/not-json.txt"
assert_rc "not json: exit 1" 1
assert_has "not json: reason" "could not read the Plugin Check results"
assert_no_line "not json: no verdict" "plugin-check-zip: no errors"

run --summarize "$WORK/no-code.json"
assert_rc "missing code: exit 1" 1
assert_has "missing code: reason" "could not read the Plugin Check results"

run --summarize "$WORK/not-array.json"
assert_rc "not an array: exit 1" 1
assert_has "not an array: reason" "could not read the Plugin Check results"

run --summarize "$WORK/empty.json"
assert_rc "empty file: exit 1" 1
assert_has "empty file: reason" "could not read the Plugin Check results"

run --summarize "$WORK/unknown-type.json"
assert_rc "unknown type: exit 1" 1
assert_has "unknown type: reason" "unknown result type(s)"
assert_has "unknown type: names it" "NOTICE"

run --summarize "$WORK/missing.json"
assert_rc "missing file: exit 1" 1
assert_has "missing file: reason" "results file not found"

# --- 引数の検査 ---
run --summarize "$WORK/warnings.json" --zip x.zip
assert_rc "summarize with --zip: exit 1" 1
assert_has "summarize with --zip: reason" "--summarize takes no other options"

run --bogus
assert_rc "unknown option: usage (exit 2)" 2

run --plugin cart-bridge-jp --zip "$WORK/none.zip" --summarize "$WORK/warnings.json" -- --format=json
assert_rc "summarize with passthrough: exit 1" 1

DONE=1
if [ "$FAILS" -ne 0 ]; then
  echo "test-plugin-check-zip: $FAILS 件失敗"
  exit 1
fi
echo "test-plugin-check-zip: all ok"
