#!/usr/bin/env bash
# プラグインの配布 zip を作り、中身を検査する（無料版は D29・R3-7、Pro は R3-8c）。release.yml・release-pro.yml と CI の Distribution ジョブが使う。
#
#   bin/build-zip.sh [--plugin cart-bridge-jp|cart-bridge-jp-pro] [--skip-build] [--version X.Y.Z] [--out DIR]
#
# 1. npm run build（無料版だけ。--skip-build で省く。Pro は JS を持たない）
# 2. vendor/ を除いて一時ディレクトリへ写し、そこで composer install --no-dev -o（開発用の plugins/<slug>/vendor は触らない。
#    Pro の .distignore は composer.json を除くので、除く前の写しで実行する）
# 3. .distignore で最終ディレクトリへ写して検査し、通ったときだけ <out>/<slug>-<version>.zip を作る（既定の plugin は無料版、out は dist/、
#    version はプラグインヘッダーの Version。--version を渡したときはヘッダーの Version と同じでなければ止める〔タグとヘッダーの版の食い違い。
#    backlog r3-7-monorepo/R1-L2〕）
#
# 検査（どれかが外れたら zip を作らず非ゼロで終わる）:
# - 最上位は許可したものだけで、すべてそろっている（無料版: 本体・uninstall.php・readme.txt・composer.json・includes/・build/・languages/・vendor/、
#   Pro: 本体・includes/・languages/・vendor/）。無料版は vendor/ の autoloader を同梱するので composer.json も同梱する（無ければ wordpress.org の
#   Plugin Check が missing_composer_json_file を警告する。R3-4a）。Pro は wordpress.org で配らないので除いたまま
# - includes/・languages/ は git が追跡しているファイルとちょうど同じ（作業ツリーの置き忘れを載せない）
# - vendor/ は autoload だけで、テストの autoload を含まない（--no-dev が効いている）。無料版は build/ に管理画面の JS とその依存の一覧がある
# - 無料版: Pro アドオンのコード上の識別子（名前空間・定数・関数とフックの接頭辞・テキストドメイン）が PHP・JS に無い（Pro のコードを無料版に載せない。D29）
# - Pro: bin/check-pro-zip.php が、無料版の名前空間（CartBridgeJP\ で Pro\ でないもの）を宣言する PHP が無いこと（無料版のコードを Pro に同梱しない。
#   依存は Pro から無料版への一方向）と、リリースの設定（ヘッダーの Version と定数、版 1.0.0 以上では接続先・製品 ID・Update URI）を確かめる
set -euo pipefail

ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"

SLUG=cart-bridge-jp
OUT=dist
VERSION=
BUILD=1

die() {
	echo "build-zip: $*" >&2
	exit 1
}

while [ $# -gt 0 ]; do
	case "$1" in
		--plugin)
			[ $# -ge 2 ] || die "--plugin needs a value"
			SLUG=$2
			shift
			;;
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
		*) die "unknown option: $1 (usage: bin/build-zip.sh [--plugin cart-bridge-jp|cart-bridge-jp-pro] [--skip-build] [--version X.Y.Z] [--out DIR])" ;;
	esac
	shift
done

# プラグインごとの違い: JS のビルド・許可する最上位・追跡と一致させるディレクトリ・必ずあるファイル。
case "$SLUG" in
	cart-bridge-jp)
		allowed='build cart-bridge-jp.php composer.json includes languages readme.txt uninstall.php vendor'
		tracked_dirs='includes languages'
		required_files='build/index.js build/index.asset.php vendor/autoload.php'
		;;
	cart-bridge-jp-pro)
		BUILD=0
		allowed='cart-bridge-jp-pro.php includes languages templates vendor'
		tracked_dirs='includes languages templates'
		required_files='vendor/autoload.php'
		;;
	*) die "unknown plugin: ${SLUG} (expected cart-bridge-jp or cart-bridge-jp-pro)" ;;
esac
SRC="plugins/${SLUG}"

header_version=$(sed -n 's/^ \* Version: *\([^ ]*\) *$/\1/p' "${SRC}/${SLUG}.php" | head -n 1)
[ -n "$header_version" ] || die "could not read the Version header from ${SRC}/${SLUG}.php"
if [ -z "$VERSION" ]; then
	VERSION=$header_version
elif [ "$VERSION" != "$header_version" ]; then
	die "--version ${VERSION} differs from the Version header of ${SRC}/${SLUG}.php (${header_version})"
fi
case "$VERSION" in
	*[!0-9A-Za-z.-]* | '') die "invalid version: ${VERSION}" ;;
esac

# 出力は受けておき、失敗したときだけすべて出してから止める（webpack のエラーは標準出力に出るので、捨てると CI のログに理由が残らない）。
if [ "$BUILD" = 1 ]; then
	if build_out=$(npm run build 2>&1); then :; else
		printf '%s\n' "$build_out" >&2
		die "npm run build failed"
	fi
fi

if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then
	die "could not create a temporary directory"
fi
trap 'rm -rf "$TMP"' EXIT

STAGE="${TMP}/stage/${SLUG}"
FINAL="${TMP}/final/${SLUG}"
mkdir -p "$STAGE" "$FINAL"

rsync -a --exclude=vendor/ "${SRC}/" "${STAGE}/"
if composer_out=$(composer install --no-dev --optimize-autoloader --prefer-dist --no-progress --no-interaction --working-dir="$STAGE" 2>&1); then :; else
	printf '%s\n' "$composer_out" >&2
	die "composer install --no-dev failed"
fi
rsync -a --exclude-from="${SRC}/.distignore" "${STAGE}/" "${FINAL}/"

fail=0
problem() {
	echo "build-zip: $*" >&2
	fail=1
}

# 最上位の許可リスト。
actual=$(cd "$FINAL" && ls -A | sort | tr '\n' ' ' | sed 's/ $//')
[ "$actual" = "$allowed" ] || problem "top-level entries are '${actual}', expected '${allowed}'"

# includes/・languages/ は追跡しているファイルと同じ。
for dir in $tracked_dirs; do
	expected=$(git ls-files "${SRC}/${dir}" | sed "s#^${SRC}/##" | sort)
	shipped=$(cd "$FINAL" && find "$dir" -type f | sort)
	[ "$expected" = "$shipped" ] || problem "${dir}/ differs from the tracked files: $(diff <(printf '%s\n' "$expected") <(printf '%s\n' "$shipped") | grep '^[<>]' | head -n 5 | tr '\n' ' ')"
done

for file in $required_files; do
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

if [ "$SLUG" = cart-bridge-jp ]; then
	# Pro のコード上の識別子。readme などの文章は対象にしない（Pro への案内のリンクを書いてよい。ガイドライン 11）。
	pro_pattern='CartBridgeJP\\+Pro|CBJP_PRO_|cbjp_pro_|cbjp/pro/|cart-bridge-jp-pro'
	if hits=$(grep -rlE --include='*.php' --include='*.js' --include='*.json' "$pro_pattern" "$FINAL"); then
		problem "Pro identifiers found in: $(printf '%s' "$hits" | sed "s#${FINAL}/##g" | tr '\n' ' ')"
	else
		rc=$?
		[ "$rc" = 1 ] || problem "could not search the plugin files for Pro identifiers (grep exit ${rc})"
	fi
else
	# 無料版の名前空間を宣言する PHP が無いこと（PHP のトークンで読む。改行・コメントを挟んだ宣言も）と、リリースの設定（ヘッダーの Version と
	# CBJP_PRO_VERSION、版 1.0.0 以上では接続先・製品 ID・Update URI）。pro-v* のタグのリリースは CI のテストを通らないので、ここで止める（PR #125 G1）。
	# 出力は受けておき、失敗したときだけ問題の一覧を出す（終了コード 1 = 問題あり、2 = 確かめられない。どちらも止める）。
	if check_out=$(php bin/check-pro-zip.php "$FINAL" 2>&1); then :; else
		rc=$?
		problem "the Pro zip check failed (exit ${rc}): $(printf '%s' "$check_out" | sed "s#${FINAL}/##g" | tr '\n' ';')"
	fi
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
