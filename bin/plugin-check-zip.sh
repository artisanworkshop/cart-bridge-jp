#!/usr/bin/env bash
# 配布 zip（bin/build-zip.sh の出力）に wordpress.org の Plugin Check を掛け、見つかったものをコードごとに数える。
# R3-5b・R3-4b で手で組み立てた手順（展開 → wp plugin check → フォルダ名由来の指摘を除いて集計 → 削除）のスクリプト版。
#
#   bin/plugin-check-zip.sh [--plugin cart-bridge-jp|cart-bridge-jp-pro] [--zip FILE | --build] [--env cli|tests-cli] [--verbose] [--keep] [-- <wp plugin check の引数>]
#   bin/plugin-check-zip.sh --summarize FILE [--verbose]
#
# 1. zip の最上位が <slug>/ だけで、展開先の外へ出る名前（絶対パス・`..`）とシンボリックリンクが無いことを確かめ、実行ごとの dist/plugin-check.XXXXXX/<slug>/ へ
#    展開する（既定の zip は dist/<slug>-<ヘッダーの Version>.zip。--build は先に bin/build-zip.sh --plugin <slug> で作る）。wp-env はリポジトリのルートを
#    wp-content/cbjp-dev にマウントしているので、コンテナはこのディレクトリをそのまま読める。無料版と Pro を同時に回しても互いの展開物・結果を消さない
# 2. wp-env の cli コンテナ（--env tests-cli で tests サイト）で `wp plugin check <展開したディレクトリ> --slug=<slug> --format=strict-json` を実行する。
#    Plugin Check の CLI はディレクトリのパスを受け（1.1.0+）、--slug が期待するテキストドメインになるので、wp-content/plugins/ に別名で置いたときの
#    フォルダ名由来の指摘（WordPress.WP.I18n.TextDomainMismatch・ヘッダーの textdomain_mismatch）が出ない。それ以外に見つかるものは別名で置いたときと同じ
#    （無料版 1.0.0・Pro 0.1.0 の zip で両方を比べた）。ランタイムのチェックは有効化したプラグインにしか走らないので、どちらの置き方でも静的なチェックだけになる
# 3. 種類（ERROR・WARNING）とコードごとに数えて出し、ERROR が 1 つでもあれば非ゼロで終わる。展開したディレクトリと結果は消す（--keep で残す）。
#    --summarize は取ってある結果（strict-json）を集計だけする（回帰テスト bin/test-plugin-check-zip.sh・再表示）
#
# Pro は readme.txt を同梱しない（wordpress.org で配らない。R3-5b）ので、no_plugin_readme だけを --ignore-codes で除く。
# 受け入れた警告（無料版: 自前のテーブルへの直接のクエリ・prepare 済みの動的な WHERE・アンインストールの DROP。docs/03 §10.9）は数えるだけで止めない。
# コードの一覧に見覚えの無いものが増えていないかを集計で見る。-- の後の引数は wp plugin check にそのまま渡す（--include-experimental・--mode=update・--checks=… など）。
# 結果を隠す引数（--ignore-errors・--ignore-warnings・--severity・--exclude-*。値付きの --ignore-errors=false も: Plugin Check は値が空でなければ隠す）は受けない。渡した引数は見出しに出す（全チェックの結果と取り違えない）。
set -euo pipefail

INVOKED_FROM=$PWD
ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"

SLUG=cart-bridge-jp
ZIP=
BUILD=0
ENV_NAME=cli
VERBOSE=0
KEEP=0
SUMMARIZE=
EXTRA=()
WORK=
MAIN_OPTS=0

usage() {
	echo "usage: bin/plugin-check-zip.sh [--plugin cart-bridge-jp|cart-bridge-jp-pro] [--zip FILE | --build] [--env cli|tests-cli] [--verbose] [--keep] [-- <wp plugin check args>]" >&2
	echo "       bin/plugin-check-zip.sh --summarize FILE [--verbose]" >&2
	exit 2
}

die() {
	echo "plugin-check-zip: $*" >&2
	exit 1
}

# 呼び出したディレクトリからの相対パスを絶対パスにする（この後リポジトリのルートへ cd するため）。
abs_path() {
	case "$1" in
		/*) printf '%s\n' "$1" ;;
		*) printf '%s\n' "${INVOKED_FROM}/$1" ;;
	esac
}

while [ $# -gt 0 ]; do
	case "$1" in
		--plugin)
			[ $# -ge 2 ] || usage
			SLUG=$2
			MAIN_OPTS=1
			shift
			;;
		--zip)
			[ $# -ge 2 ] || usage
			ZIP=$(abs_path "$2")
			MAIN_OPTS=1
			shift
			;;
		--build)
			BUILD=1
			MAIN_OPTS=1
			;;
		--env)
			[ $# -ge 2 ] || usage
			ENV_NAME=$2
			MAIN_OPTS=1
			shift
			;;
		--verbose) VERBOSE=1 ;;
		--keep)
			KEEP=1
			MAIN_OPTS=1
			;;
		--summarize)
			[ $# -ge 2 ] || usage
			SUMMARIZE=$(abs_path "$2")
			shift
			;;
		--)
			shift
			EXTRA=("$@")
			MAIN_OPTS=1
			break
			;;
		*) usage ;;
	esac
	shift
done

command -v jq >/dev/null 2>&1 || die "jq is required"

# 結果のファイルを読んで集計する。Plugin Check は見つかったものが無いと JSON ではなく「Success: Checks complete. No errors found.」を出す。
# それ以外は strict-json の配列（file・line・column・type・code・message）。読めない形・知らない種類は「確かめられない」として止める（フェイルクローズ）。
# ERROR があれば 1 を返す。
summarize() {
	local file=$1
	local content errors warnings unknown rows filter
	[ -f "$file" ] || die "results file not found: ${file}"
	if content=$(cat "$file"); then :; else die "could not read ${file}"; fi

	if [ "$content" = 'Success: Checks complete. No errors found.' ]; then
		echo "ERROR 0 / WARNING 0"
		echo "plugin-check-zip: no errors"
		return 0
	fi

	if ! jq -e 'if type == "array" then all(.[]; type == "object" and (.file | type) == "string" and (.type | type) == "string" and (.code | type) == "string" and (.message | type) == "string") else false end' "$file" >/dev/null 2>&1; then
		die "could not read the Plugin Check results in ${file} (expected a strict-json array or the success line). It starts with: $(head -c 200 "$file" | tr '\n' ' ')"
	fi
	if unknown=$(jq -r '[.[] | select(.type != "ERROR" and .type != "WARNING") | .type] | unique | join(", ")' "$file"); then :; else die "could not read ${file}"; fi
	[ -z "$unknown" ] || die "unknown result type(s) in ${file}: ${unknown} (expected ERROR or WARNING)"

	if errors=$(jq '[.[] | select(.type == "ERROR")] | length' "$file"); then :; else die "could not count the errors in ${file}"; fi
	if warnings=$(jq '[.[] | select(.type == "WARNING")] | length' "$file"); then :; else die "could not count the warnings in ${file}"; fi
	echo "ERROR ${errors} / WARNING ${warnings}"

	# 種類・コードごとの件数（ERROR が先、同じ種類の中は多い順）。
	if rows=$(jq -r 'group_by([.type, .code]) | map({ type: .[0].type, code: .[0].code, n: length }) | sort_by(.type, -.n, .code) | .[] | "\(.n)\t\(.type)\t\(.code)"' "$file"); then :; else die "could not group the results in ${file}"; fi
	printf '%s\n' "$rows" | while IFS=$'\t' read -r n type code; do
		printf '  %5d  %-7s  %s\n' "$n" "$type" "$code"
	done

	# ERROR は常に 1 件ずつ出す。--verbose は WARNING も出す。
	if [ "$VERBOSE" = 1 ]; then
		filter='.[]'
	else
		filter='.[] | select(.type == "ERROR")'
	fi
	# ファイル全体への指摘（readme の無い ERROR など）は line が 0 なので、行番号を付けない。
	if rows=$(jq -r "${filter}"' | "  \(.type)  \(.file)\(if (.line | type) == "number" and .line > 0 then ":\(.line)" else "" end)  \(.code)  \(.message)"' "$file"); then :; else die "could not list the results in ${file}"; fi
	[ -z "$rows" ] || printf '%s\n' "$rows"

	if [ "$errors" -gt 0 ]; then
		echo "plugin-check-zip: ${errors} error(s); fix them before releasing" >&2
		return 1
	fi
	echo "plugin-check-zip: no errors"
	return 0
}

if [ -n "$SUMMARIZE" ]; then
	[ "$MAIN_OPTS" = 0 ] || die "--summarize takes no other options than --verbose"
	echo "plugin-check-zip: ${SUMMARIZE}"
	rc=0
	summarize "$SUMMARIZE" || rc=$?
	exit "$rc"
fi

# プラグインごとの違い: Pro は readme.txt を同梱しない（wordpress.org で配らない）ので、無い readme の ERROR だけを除く。
case "$SLUG" in
	cart-bridge-jp) IGNORE_CODES= ;;
	cart-bridge-jp-pro) IGNORE_CODES=no_plugin_readme ;;
	*) die "unknown plugin: ${SLUG} (expected cart-bridge-jp or cart-bridge-jp-pro)" ;;
esac
case "$ENV_NAME" in
	cli | tests-cli) ;;
	*) die "unknown env: ${ENV_NAME} (expected cli or tests-cli)" ;;
esac
[ -z "$ZIP" ] || [ "$BUILD" = 0 ] || die "use either --zip or --build"
# --format・--fields・--slug・--ignore-codes はこのスクリプトが決める（二重に渡さない）。結果を隠す引数は値の有無によらず受けない（ERROR を隠した結果を「ERROR 0」と報告しない。Plugin Check は --ignore-errors を empty() で見るので =false でも隠す。PR #141 Codex）。
for arg in ${EXTRA[@]+"${EXTRA[@]}"}; do
	case "$arg" in
		--format | --format=* | --fields | --fields=* | --slug | --slug=* | --ignore-codes | --ignore-codes=*)
			die "${arg} is set by this script (see --plugin); pass only other wp plugin check options after --"
			;;
		--ignore-errors | --ignore-errors=* | --ignore-warnings | --ignore-warnings=* | --severity | --severity=* | --error-severity | --error-severity=* | --warning-severity | --warning-severity=* | --exclude-checks | --exclude-checks=* | --exclude-directories | --exclude-directories=* | --exclude-files | --exclude-files=*)
			die "${arg} hides results; this script reports everything Plugin Check finds"
			;;
	esac
done

SRC="plugins/${SLUG}"
if [ "$BUILD" = 1 ]; then
	bin/build-zip.sh --plugin "$SLUG"
fi
if [ -z "$ZIP" ]; then
	header_version=$(sed -n 's/^ \* Version: *\([^ ]*\) *$/\1/p' "${SRC}/${SLUG}.php" | head -n 1)
	[ -n "$header_version" ] || die "could not read the Version header from ${SRC}/${SLUG}.php"
	ZIP="${ROOT}/dist/${SLUG}-${header_version}.zip"
fi
[ -f "$ZIP" ] || die "zip not found: ${ZIP} (run bin/build-zip.sh --plugin ${SLUG}, pass --build, or point --zip at the file)"
case "$ZIP" in
	"${ROOT}"/*) zip_display=${ZIP#"${ROOT}"/} ;;
	*) zip_display=$ZIP ;;
esac

# zip の中身の名前を検査する。展開先の外へ出る名前（絶対パス・`..` の要素）が無いこと（unzip は既定で飛ばすが、飛ばした zip を「検査した」と言わない）、
# 最上位は <slug>/ だけ（build-zip.sh の出力の形。別の zip を渡されたときに、別の名前のディレクトリを検査しない）。
if entries=$(unzip -Z1 "$ZIP"); then :; else die "could not list ${zip_display} (is it a zip?)"; fi
if bad=$(printf '%s\n' "$entries" | grep -E '^/|(^|/)\.\.(/|$)'); then
	die "${zip_display} has entries outside its own directory: $(printf '%s' "$bad" | head -n 3 | tr '\n' ' ')"
else
	rc=$?
	[ "$rc" = 1 ] || die "could not scan the entries of ${zip_display} (grep exit ${rc})"
fi
top=$(printf '%s\n' "$entries" | cut -d/ -f1 | sort -u)
[ "$top" = "$SLUG" ] || die "${zip_display} must contain exactly one top-level directory '${SLUG}/' (found: $(printf '%s' "$top" | tr '\n' ' '))"
nfiles=$(printf '%s\n' "$entries" | grep -c -v '/$' || true)

# 展開先はリポジトリのルートの dist/ の下の、この実行だけのディレクトリ（gitignore 済みのローカル出力。wp-env のマウントでコンテナから
# wp-content/cbjp-dev/dist/plugin-check.XXXXXX と見える）。無料版と Pro を同時に回しても互いの展開物・結果を消さない（PR #141 Codex）。
# 消すのは自分の作ったディレクトリだけで、終わったら消す（--keep で残す）。消せなかったら成功と言わない。
# 中断で残った前回のディレクトリは、別の実行が使っている最中かもしれないので消さず、名前を知らせる。
mkdir -p "${ROOT}/dist"
if ! WORK=$(mktemp -d "${ROOT}/dist/plugin-check.XXXXXX") || [ -z "$WORK" ] || [ ! -d "$WORK" ]; then
	die "could not create a work directory under dist/"
fi
WORK_REL=${WORK#"${ROOT}"/}
# shellcheck disable=SC2329  # trap から呼ぶ
cleanup() {
	if [ "$KEEP" = 1 ] || [ -z "$WORK" ] || [ ! -e "$WORK" ]; then
		return 0
	fi
	rm -rf "$WORK" || true
	if [ -e "$WORK" ]; then
		echo "plugin-check-zip: could not remove ${WORK}; remove it by hand" >&2
		exit 1
	fi
}
trap cleanup EXIT
if others=$(find "${ROOT}/dist" -mindepth 1 -maxdepth 1 -type d -name 'plugin-check.*' ! -path "$WORK" | sed "s#^${ROOT}/##" | sort | tr '\n' ' '); then :; else others=; fi
[ -z "$others" ] || echo "plugin-check-zip: note: other work directories exist (an interrupted earlier run, or a run in progress; remove by hand when no run is active): ${others}"
if unzip_out=$(unzip -q "$ZIP" -d "$WORK" 2>&1); then :; else
	printf '%s\n' "$unzip_out" >&2
	die "could not extract ${zip_display}"
fi
[ -f "${WORK}/${SLUG}/${SLUG}.php" ] || die "${zip_display} does not contain ${SLUG}/${SLUG}.php"
# シンボリックリンクは展開先の外を読ませうるので受けない（build-zip.sh の zip は実体しか持たない）。
if links=$(find "$WORK" -type l); then :; else die "could not scan ${zip_display} for symbolic links"; fi
[ -z "$links" ] || die "${zip_display} contains symbolic links: $(printf '%s' "$links" | sed "s#^${WORK}/##" | head -n 3 | tr '\n' ' ')"

# コンテナの中で実行するコマンド。カレントはルートのマウント（--env-cwd）なので、展開先は dist/plugin-check.XXXXXX の相対パスのまま指せる。
# 結果は同じディレクトリのファイルに受ける（wp-env run の標準出力には自身の表示が混ざるので、そこから JSON を拾わない）。
# パスと -- の後の引数は printf %q で引用して埋め込む。
extra_q=
for arg in ${EXTRA[@]+"${EXTRA[@]}"}; do
	extra_q="${extra_q} $(printf '%q' "$arg")"
done
ignore_q=
[ -z "$IGNORE_CODES" ] || ignore_q=" --ignore-codes=${IGNORE_CODES}"
work_q=$(printf '%q' "$WORK_REL")
inner="set -euo pipefail
cd ${work_q}
wp plugin get plugin-check --field=version > version.txt
wp plugin check \"\$PWD/${SLUG}\" --slug=${SLUG} --format=strict-json --fields=file,line,column,type,code,message${ignore_q}${extra_q} > results.json 2> stderr.txt
"
if run_out=$(npx wp-env run "$ENV_NAME" --env-cwd=wp-content/cbjp-dev -- bash -c "$inner" 2>&1 </dev/null); then :; else
	printf '%s\n' "$run_out" >&2
	[ ! -s "${WORK}/stderr.txt" ] || cat "${WORK}/stderr.txt" >&2
	die "wp plugin check did not finish in the ${ENV_NAME} container (is wp-env running with Plugin Check active?)"
fi
# WP-CLI・PHP の警告が出ていたら、チェックが途中で止まっていないと言えないので止める（手で確かめるときは --keep で残したファイルを見る）。
if [ -s "${WORK}/stderr.txt" ]; then
	cat "${WORK}/stderr.txt" >&2
	die "wp plugin check printed warnings (above); the results may be incomplete"
fi
if pc_version=$(tr -d '[:space:]' <"${WORK}/version.txt") && [ -n "$pc_version" ]; then :; else
	die "could not read the Plugin Check version (${WORK_REL}/version.txt)"
fi

echo "plugin-check-zip: ${zip_display} (${nfiles} files) checked as ${SLUG} with Plugin Check ${pc_version} on wp-env ${ENV_NAME}"
[ -z "$IGNORE_CODES" ] || echo "plugin-check-zip: ignored codes: ${IGNORE_CODES} (Pro ships no readme.txt; it is not distributed on wordpress.org)"
[ -z "$extra_q" ] || echo "plugin-check-zip: extra wp plugin check options:${extra_q} (not the default full run)"
rc=0
summarize "${WORK}/results.json" || rc=$?
[ "$KEEP" = 0 ] || echo "plugin-check-zip: kept ${WORK_REL} (extracted plugin, results.json, stderr.txt)"
exit "$rc"
