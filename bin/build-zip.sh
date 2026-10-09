#!/usr/bin/env bash
# 無料版（plugins/cart-bridge-jp）の配布 zip を作り、中身を検査する（D29・R3-7）。release.yml と CI の Distribution ジョブが使う。
#
#   bin/build-zip.sh [--skip-build] [--version X.Y.Z] [--out DIR]
#
# 1. npm run build（--skip-build で省く）
# 2. vendor/ を除いて一時ディレクトリへ写し、そこで composer install --no-dev -o（開発用の plugins/cart-bridge-jp/vendor は触らない。
#    .distignore は composer.json を除くので、除く前の写しで実行する）
# 3. .distignore で最終ディレクトリへ写して検査し、通ったときだけ <out>/cart-bridge-jp-<version>.zip を作る（既定の out は dist/、
#    version はプラグインヘッダーの Version）
#
# 検査（どれかが外れたら zip を作らず非ゼロで終わる）:
# - 最上位は許可したものだけ（本体・uninstall.php・readme.txt・includes/・build/・languages/・vendor/）で、すべてそろっている
# - includes/・languages/ は git が追跡しているファイルとちょうど同じ（作業ツリーの置き忘れを載せない）
# - build/ に管理画面の JS とその依存の一覧がある。vendor/ は autoload だけで、テストの autoload を含まない（--no-dev が効いている）
# - Pro アドオンのコード上の識別子（名前空間・定数・関数とフックの接頭辞・テキストドメイン）が PHP・JS に無い（Pro のコードを無料版に載せない。D29）
set -euo pipefail

ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"

SLUG=cart-bridge-jp
SRC="plugins/${SLUG}"
OUT=dist
VERSION=
BUILD=1

die() {
	echo "build-zip: $*" >&2
	exit 1
}

while [ $# -gt 0 ]; do
	case "$1" in
		--skip-build) BUILD=0 ;;
		--version)
			[ $# -ge 2 ] || die "--version needs a value"
			VERSION=$2
			shift
			;;
		--out)
			[ $# -ge 2 ] || die "--out needs a value"
			OUT=$2
			shift
			;;
		*) die "unknown option: $1 (usage: bin/build-zip.sh [--skip-build] [--version X.Y.Z] [--out DIR])" ;;
	esac
	shift
done

if [ -z "$VERSION" ]; then
	VERSION=$(sed -n 's/^ \* Version: *\([^ ]*\) *$/\1/p' "${SRC}/${SLUG}.php" | head -n 1)
	[ -n "$VERSION" ] || die "could not read the Version header from ${SRC}/${SLUG}.php"
fi
case "$VERSION" in
	*[!0-9A-Za-z.-]* | '') die "invalid version: ${VERSION}" ;;
esac

if [ "$BUILD" = 1 ]; then
	npm run build >/dev/null || die "npm run build failed"
fi

if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then
	die "could not create a temporary directory"
fi
trap 'rm -rf "$TMP"' EXIT

STAGE="${TMP}/stage/${SLUG}"
FINAL="${TMP}/final/${SLUG}"
mkdir -p "$STAGE" "$FINAL"

rsync -a --exclude=vendor/ "${SRC}/" "${STAGE}/"
composer install --no-dev --optimize-autoloader --prefer-dist --no-progress --no-interaction --quiet --working-dir="$STAGE" ||
	die "composer install --no-dev failed"
rsync -a --exclude-from="${SRC}/.distignore" "${STAGE}/" "${FINAL}/"

fail=0
problem() {
	echo "build-zip: $*" >&2
	fail=1
}

# 最上位の許可リスト。
allowed='build cart-bridge-jp.php includes languages readme.txt uninstall.php vendor'
actual=$(cd "$FINAL" && ls -A | sort | tr '\n' ' ' | sed 's/ $//')
[ "$actual" = "$allowed" ] || problem "top-level entries are '${actual}', expected '${allowed}'"

# includes/・languages/ は追跡しているファイルと同じ。
for dir in includes languages; do
	expected=$(git ls-files "${SRC}/${dir}" | sed "s#^${SRC}/##" | sort)
	shipped=$(cd "$FINAL" && find "$dir" -type f | sort)
	[ "$expected" = "$shipped" ] || problem "${dir}/ differs from the tracked files: $(diff <(printf '%s\n' "$expected") <(printf '%s\n' "$shipped") | grep '^[<>]' | head -n 5 | tr '\n' ' ')"
done

for file in build/index.js build/index.asset.php vendor/autoload.php; do
	[ -f "${FINAL}/${file}" ] || problem "${file} is missing"
done

vendor_top=$(cd "${FINAL}/vendor" && ls -A | sort | tr '\n' ' ' | sed 's/ $//')
[ "$vendor_top" = "autoload.php composer" ] || problem "vendor/ holds '${vendor_top}', expected only the autoloader"
# grep の終了コード: 0 = 見つかった、1 = 無い、2 = 読めない（読めないことを「無い」と読まない）。
if grep -q 'Tests' "${FINAL}/vendor/composer/autoload_psr4.php"; then
	problem "vendor/composer/autoload_psr4.php maps the test namespace (composer install --no-dev did not apply)"
else
	rc=$?
	[ "$rc" = 1 ] || problem "could not read vendor/composer/autoload_psr4.php (grep exit ${rc})"
fi

# Pro のコード上の識別子。readme などの文章は対象にしない（Pro への案内のリンクを書いてよい。ガイドライン 11）。
pro_pattern='CartBridgeJP\\+Pro|CBJP_PRO_|cbjp_pro_|cbjp/pro/|cart-bridge-jp-pro'
if hits=$(grep -rlE --include='*.php' --include='*.js' --include='*.json' "$pro_pattern" "$FINAL"); then
	problem "Pro identifiers found in: $(printf '%s' "$hits" | sed "s#${FINAL}/##g" | tr '\n' ' ')"
else
	rc=$?
	[ "$rc" = 1 ] || problem "could not search the plugin files for Pro identifiers (grep exit ${rc})"
fi

[ "$fail" = 0 ] || die "the plugin zip did not pass the checks; nothing was written"

mkdir -p "$OUT"
case "$OUT" in
	/*) dest="${OUT}/${SLUG}-${VERSION}.zip" ;;
	*) dest="${ROOT}/${OUT}/${SLUG}-${VERSION}.zip" ;;
esac
rm -f "$dest"
(cd "${TMP}/final" && zip -rq "$dest" "${SLUG}/")
echo "build-zip: wrote ${dest} ($(cd "$FINAL" && find . -type f | wc -l | tr -d ' ') files)"
