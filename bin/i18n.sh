#!/usr/bin/env bash
# Cart Bridge JP の翻訳ファイル（languages/）を作る・検査する（R3-2）。WP-CLI は wp-env の cli コンテナで実行する。
#
#   bin/i18n.sh pot      POT を作り直す（先に npm run build。npm run i18n:pot はビルドしてから呼ぶ）
#   bin/i18n.sh po       日本語の PO を POT に合わせる（無ければヘッダだけの PO を作ってから合わせる）。足された文字列は訳が空になる
#   bin/i18n.sh compile  PO から .mo・.l10n.php・JSON（管理画面の JS 用）を作り直す
#   bin/i18n.sh check    ビルド済みのソースから POT を作り直し、コミット済みの POT と文字列が同じか確かめる（CI。npm run i18n:check はビルドしてから呼ぶ）
#
# JS の文字列は src/*.tsx ではなく build/index.js から抜く（WP-CLI の make-pot は TypeScript を読まない）。参照が build/index.js になるので、
# make-json の JSON 名（cart-bridge-jp-ja-<md5("build/index.js")>.json）が wp_set_script_translations() の探す名前と一致する。
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
mkdir -p languages

DOMAIN=cart-bridge-jp
LOCALE=ja
POT="languages/${DOMAIN}.pot"
PO="languages/${DOMAIN}-${LOCALE}.po"
# 抽出しないパス。src は build/index.js と二重になるうえ参照が JSON 名と食い違う。node_modules・vendor は make-pot が常に除外する。
EXCLUDE='src,tests,docs,.claude,dist,.rehearsal,bin,languages'

wpcli() {
	npx wp-env run cli --env-cwd="wp-content/plugins/${DOMAIN}" -- wp "$@"
}

require_build() {
	if [ ! -f build/index.js ]; then
		echo "i18n: build/index.js がありません。先に npm run build を実行してください" >&2
		exit 1
	fi
}

# make-pot をコンテナの中で実行し、監査の警告（翻訳者コメントの食い違い・プレースホルダの番号漏れなど）も失敗にする。
# 警告は WP-CLI の標準エラーに出るが、wp-env run の標準エラーには自身の表示も混ざるので、コンテナの中の一時ファイルで受けて消す。
# 第 2 引数があれば、同じ bash の中で続けて実行する（check の比較。wp-env run は起動中のコンテナへの docker compose exec）。
make_pot() {
	local dest="$1"
	local after="${2:-}"

	npx wp-env run cli --env-cwd="wp-content/plugins/${DOMAIN}" -- bash -c "
		err=\$(mktemp)
		trap 'rm -f \"\$err\"' EXIT
		if ! wp i18n make-pot . '${dest}' --exclude='${EXCLUDE}' >/dev/null 2>\"\$err\"; then
			echo 'i18n: make-pot に失敗しました' >&2
			cat \"\$err\" >&2
			exit 1
		fi
		if [ -s \"\$err\" ]; then
			echo 'i18n: make-pot が警告を出しました:' >&2
			cat \"\$err\" >&2
			exit 1
		fi
		${after}
	"
}

cmd_pot() {
	require_build
	make_pot "$POT"
	echo "i18n: ${POT} を作り直しました"
}

cmd_po() {
	if [ ! -f "$POT" ]; then
		echo "i18n: ${POT} がありません。先に npm run i18n:pot を実行してください" >&2
		exit 1
	fi

	if [ ! -f "$PO" ]; then
		# 日本語は複数形が 1 つ（Plural-Forms）。update-po はこのヘッダに合わせて msgstr[0] だけの項目を作る。
		cat >"$PO" <<'HEADER'
msgid ""
msgstr ""
"Project-Id-Version: Cart Bridge JP\n"
"Language: ja\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"Plural-Forms: nplurals=1; plural=0;\n"
"X-Domain: cart-bridge-jp\n"
HEADER
	fi

	wpcli i18n update-po "$POT" "$PO" >/dev/null
	echo "i18n: ${PO} を ${POT} に合わせました（足された文字列は訳が空です）"
}

cmd_compile() {
	if [ ! -f "$PO" ]; then
		echo "i18n: ${PO} がありません" >&2
		exit 1
	fi

	# 消えたソースの JSON が残らないよう、作り直す前に消す。
	rm -f "languages/${DOMAIN}-${LOCALE}"-*.json
	wpcli i18n make-mo "$PO" languages >/dev/null
	wpcli i18n make-php "$PO" languages >/dev/null
	# --no-purge: 既定の --purge は PO から JS の文字列を消してしまう。
	wpcli i18n make-json "$PO" languages --no-purge --pretty-print >/dev/null
	echo "i18n: .mo・.l10n.php・JSON を作り直しました"
}

cmd_check() {
	require_build

	if [ ! -f "$POT" ]; then
		echo "i18n: ${POT} がありません" >&2
		exit 1
	fi

	# 作り直した POT はリポジトリに書かず、コンテナの /tmp に置いて比べ終わったら消す（作業ツリーに一時ファイルを残さない）。
	make_pot /tmp/cbjp-check.pot "rc=0; wp eval-file bin/i18n-check.php /tmp/cbjp-check.pot '${POT}' || rc=\$?; rm -f /tmp/cbjp-check.pot; exit \$rc"
}

case "${1:-}" in
	pot) cmd_pot ;;
	po) cmd_po ;;
	compile) cmd_compile ;;
	check) cmd_check ;;
	*)
		echo "usage: bin/i18n.sh <pot|po|compile|check>" >&2
		exit 2
		;;
esac
