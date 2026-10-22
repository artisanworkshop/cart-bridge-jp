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
# jq は集計、unzip はスクリプト自身の一覧、python3 は zip の fixture の合成（`..`・絶対パス・シンボリックリンクは zip コマンドでは作りにくい）。
for tool in jq unzip python3; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "test-plugin-check-zip: ${tool} is required" >&2
    exit 1
  fi
done
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
assert_line "errors: file-level error listed without a line number" "  ERROR  readme.txt  no_plugin_readme  The plugin readme.txt does not exist."
assert_line "errors: error with a line number listed as file:line" "  ERROR  includes/Admin/RestController.php:496  WordPress.WP.I18n.TextDomainMismatch  Mismatched text domain. Expected 'a' but got 'b'."
assert_line "errors: verdict" "plugin-check-zip: 2 error(s); fix them before releasing"
assert_no_line "errors: warning not listed without --verbose" "  WARNING  composer.json  missing_composer_json_file  The composer.json file is missing."
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

# --- 引数の検査（wp-env に出る前に止まるもの） ---
run --summarize "$WORK/warnings.json" --zip x.zip
assert_rc "summarize with --zip: exit 1" 1
assert_has "summarize with --zip: reason" "--summarize takes no other options"

run --summarize "$WORK/warnings.json" --keep
assert_rc "summarize with --keep: exit 1" 1
assert_has "summarize with --keep: reason" "--summarize takes no other options"

run --bogus
assert_rc "unknown option: usage (exit 2)" 2

run --plugin cart-bridge-jp --zip "$WORK/none.zip" --summarize "$WORK/warnings.json" -- --format=json
assert_rc "summarize with passthrough: exit 1" 1

run --plugin foo --zip "$WORK/none.zip"
assert_rc "unknown plugin: exit 1" 1
assert_has "unknown plugin: reason" "unknown plugin: foo"

run --env web --zip "$WORK/none.zip"
assert_rc "unknown env: exit 1" 1
assert_has "unknown env: reason" "unknown env: web"

run --zip "$WORK/none.zip" --build
assert_rc "--zip with --build: exit 1" 1
assert_has "--zip with --build: reason" "use either --zip or --build"

run --zip "$WORK/none.zip" -- --format=json
assert_rc "passthrough --format: exit 1" 1
assert_has "passthrough --format: reason" "--format=json is set by this script"

run --zip "$WORK/none.zip" -- --ignore-errors
assert_rc "passthrough --ignore-errors: exit 1" 1
assert_has "passthrough --ignore-errors: reason" "--ignore-errors hides results"

# 値付きの形も拒否する（Plugin Check は --ignore-errors を empty() で見るので、=true はもちろん =false でも ERROR を隠す。PR #141 Codex）。
run --zip "$WORK/none.zip" -- --ignore-errors=true
assert_rc "passthrough --ignore-errors=true: exit 1" 1
assert_has "passthrough --ignore-errors=true: reason" "--ignore-errors=true hides results"

run --zip "$WORK/none.zip" -- --ignore-warnings=false
assert_rc "passthrough --ignore-warnings=false: exit 1" 1
assert_has "passthrough --ignore-warnings=false: reason" "--ignore-warnings=false hides results"

run --zip "$WORK/none.zip" -- --severity=7
assert_rc "passthrough --severity: exit 1" 1
assert_has "passthrough --severity: reason" "--severity=7 hides results"

run --zip "$WORK/none.zip"
assert_rc "missing zip: exit 1" 1
assert_has "missing zip: reason" "zip not found"

# --- zip の中身の検査（python3 の zipfile で合成。一覧で止まるものと、展開してから止まるもの） ---
python3 - "$WORK" <<'PY'
import os, sys, zipfile
w = sys.argv[1]
def make(name, entries):
    with zipfile.ZipFile(os.path.join(w, name), 'w') as z:
        for n, body in entries:
            z.writestr(n, body)
make('wrong-top.zip', [('other-name/other-name.php', '<?php\n')])
make('two-tops.zip', [('cart-bridge-jp/cart-bridge-jp.php', '<?php\n'), ('extra.txt', 'x\n')])
make('slip.zip', [('cart-bridge-jp/cart-bridge-jp.php', '<?php\n'), ('cart-bridge-jp/../evil.php', '<?php\n')])
make('absolute.zip', [('cart-bridge-jp/cart-bridge-jp.php', '<?php\n'), ('/tmp/evil.php', '<?php\n')])
with zipfile.ZipFile(os.path.join(w, 'symlink.zip'), 'w') as z:
    z.writestr('cart-bridge-jp/cart-bridge-jp.php', '<?php\n')
    info = zipfile.ZipInfo('cart-bridge-jp/link')
    info.create_system = 3
    info.external_attr = 0o120777 << 16
    z.writestr(info, 'cart-bridge-jp.php')
PY

run --zip "$WORK/wrong-top.zip"
assert_rc "wrong top-level dir: exit 1" 1
assert_has "wrong top-level dir: reason" "must contain exactly one top-level directory 'cart-bridge-jp/'"
assert_has "wrong top-level dir: names it" "(found: other-name"

run --zip "$WORK/two-tops.zip"
assert_rc "two top-level entries: exit 1" 1
assert_has "two top-level entries: reason" "must contain exactly one top-level directory 'cart-bridge-jp/'"

run --zip "$WORK/slip.zip"
assert_rc "path traversal entry: exit 1" 1
assert_has "path traversal entry: reason" "has entries outside its own directory"
assert_has "path traversal entry: names it" "cart-bridge-jp/../evil.php"

run --zip "$WORK/absolute.zip"
assert_rc "absolute entry: exit 1" 1
assert_has "absolute entry: reason" "has entries outside its own directory"

# シンボリックリンクは展開してからしか分からない。止めた後に作業ディレクトリ（dist/plugin-check.XXXXXX）を残さない。
ROOT_DIR=$(git -C "$HERE" rev-parse --show-toplevel)
before=$(ls -d "$ROOT_DIR"/dist/plugin-check.* 2>/dev/null || true)
run --zip "$WORK/symlink.zip"
assert_rc "symbolic link: exit 1" 1
assert_has "symbolic link: reason" "contains symbolic links"
assert_has "symbolic link: names it" "cart-bridge-jp/link"
after=$(ls -d "$ROOT_DIR"/dist/plugin-check.* 2>/dev/null || true)
if [ "$before" = "$after" ]; then ok "symbolic link: work directory removed"; else fail "symbolic link: work directory left behind: ${after}"; fi

# --- プラグインごとの --ignore-codes（Plugin Check 自身が除くので集計には現れない。スクリプトの宣言を読んで固定する） ---
# 無料版は wordpress.org の審査と同じ結果を見るので何も除かない。Pro は wordpress.org で配らないので、無い readme（R3-5b）と
# `Update URI` の更新の仕組み（R3-8c。1.0.0 から）の ERROR だけを除く。ここが広がる・縮む変更は意図したものか確かめる。
if grep -xF -- '	cart-bridge-jp) IGNORE_CODES= ;;' "$PCZ" >/dev/null; then ok "ignore codes: the free plugin ignores nothing"; else fail "ignore codes: the free plugin must not ignore any code"; fi
if grep -xF -- '	cart-bridge-jp-pro) IGNORE_CODES=no_plugin_readme,plugin_updater_detected ;;' "$PCZ" >/dev/null; then ok "ignore codes: the Pro ignores only no_plugin_readme and plugin_updater_detected"; else fail "ignore codes: the Pro's list changed (expected no_plugin_readme,plugin_updater_detected)"; fi
if [ "$(grep -c 'IGNORE_CODES=' "$PCZ")" = 2 ]; then ok "ignore codes: declared once per plugin"; else fail "ignore codes: IGNORE_CODES is assigned somewhere other than the per-plugin case"; fi

DONE=1
if [ "$FAILS" -ne 0 ]; then
  echo "test-plugin-check-zip: $FAILS 件失敗"
  exit 1
fi
echo "test-plugin-check-zip: all ok"
