#!/usr/bin/env bash
# Cart Bridge JP の翻訳ファイル（plugins/<スラッグ>/languages/）を作る・検査する（無料版は R3-2、Pro アドオンは R3-8d）。WP-CLI は wp-env の cli コンテナで実行する。
#
#   bin/i18n.sh pot      POT を作り直す（先に npm run build。npm run i18n:pot はビルドしてから呼ぶ）
#   bin/i18n.sh po       日本語の PO を POT に合わせる（無ければヘッダだけの PO を作ってから合わせる）。足された文字列は訳が空になる
#   bin/i18n.sh compile  PO から .mo・.l10n.php を作り直す（Pro だけ。無料版は翻訳を同梱しないので何もしない）
#   bin/i18n.sh check    ビルド済みのソースから POT を作り直し、コミット済みの POT と文字列が同じか確かめる（CI。npm run i18n:check はビルドしてから呼ぶ）
#
#   --plugin cart-bridge-jp|cart-bridge-jp-pro を付けると片方だけ。付けなければ無料版 → Pro の順に両方（npm の i18n:* は両方）。
#
# 無料版の JS の文字列は src/*.tsx ではなく build/index.js から抜く（WP-CLI の make-pot は TypeScript を読まない）。参照が build/index.js になるので、
# translate.wordpress.org の言語パックの JSON 名（cart-bridge-jp-ja-<md5("build/index.js")>.json）が wp_set_script_translations() の探す名前と一致する。
# 無料版は翻訳を同梱しない（言語パックで配られる。wordpress.org の審査の指摘。issue #144）。リポジトリの POT と ja.po は translate.wordpress.org へ
# 取り込む元として保ち、.mo・.l10n.php・JSON は作らない。Pro は JS を持たない（設定画面は PHP で描く）ので、build/index.js を要求しない。
set -euo pipefail
# プラグインのディレクトリで作る（POT の参照パスが build/index.js・includes/… のままになり、JSON の名前も変わらない。D29 の配置）。
# wp-env は呼び出したディレクトリから .wp-env.json を探すので、npx wp-env だけはリポジトリのルートから呼ぶ（wp_env）。
ROOT="$(git rev-parse --show-toplevel)"
LOCALE=ja
# 比較のスクリプト（リポジトリのルートの bin/）。wp-env はルートを wp-content/cbjp-dev にマウントする。
CHECK_PHP='../../cbjp-dev/bin/i18n-check.php'

usage() {
	echo "usage: bin/i18n.sh <pot|po|compile|check> [--plugin cart-bridge-jp|cart-bridge-jp-pro]" >&2
	exit 2
}

# プラグインごとの設定。DOMAIN はスラッグ・テキストドメイン・wp-env のマウント名を兼ねる。
# EXCLUDE は抽出しないパス。無料版の src は build/index.js と二重になるうえ参照が JSON 名と食い違う。node_modules・vendor は make-pot が常に除外する。
# POT_ARGS は make-pot に足す引数（コンテナの bash -c にそのまま入る）。Pro は wordpress.org に無いので、make-pot が既定で入れる wordpress.org の
# サポートフォーラムの URL（Report-Msgid-Bugs-To）を空にする。COMPILE は配布物に入れる .mo・.l10n.php を作るか（Pro だけ）。
use_plugin() {
	DOMAIN=$1
	case "$DOMAIN" in
		cart-bridge-jp)
			PROJECT='Cart Bridge JP'
			EXCLUDE='src,tests,languages'
			POT_ARGS=''
			HAS_JS=1
			COMPILE=0
			;;
		cart-bridge-jp-pro)
			PROJECT='Cart Bridge JP Pro'
			EXCLUDE='tests,languages'
			POT_ARGS="--headers='{\"Report-Msgid-Bugs-To\":\"\"}'"
			HAS_JS=0
			COMPILE=1
			;;
		*)
			echo "i18n: unknown plugin: ${DOMAIN}" >&2
			usage
			;;
	esac
	DIR="${ROOT}/plugins/${DOMAIN}"
	POT="languages/${DOMAIN}.pot"
	PO="languages/${DOMAIN}-${LOCALE}.po"
}

wp_env() {
	(cd "$ROOT" && npx wp-env "$@")
}

wpcli() {
	wp_env run cli --env-cwd="wp-content/plugins/${DOMAIN}" -- wp "$@"
}

require_build() {
	if [ "$HAS_JS" = 1 ] && [ ! -f build/index.js ]; then
		echo "i18n: ${DOMAIN}: build/index.js がありません。先に npm run build を実行してください" >&2
		exit 1
	fi
}

# make-pot をコンテナの中で実行し、監査の警告（翻訳者コメントの食い違い・プレースホルダの番号漏れなど）も失敗にする。
# 警告は WP-CLI の標準エラーに出るが、wp-env run の標準エラーには自身の表示も混ざるので、コンテナの中の一時ファイルで受けて消す。
# 第 2 引数があれば、同じ bash の中で続けて実行する（check の比較。wp-env run は起動中のコンテナへの docker compose exec）。
make_pot() {
	local dest="$1"
	local after="${2:-}"

	wp_env run cli --env-cwd="wp-content/plugins/${DOMAIN}" -- bash -c "
		err=\$(mktemp)
		trap 'rm -f \"\$err\"' EXIT
		if ! wp i18n make-pot . '${dest}' --exclude='${EXCLUDE}' ${POT_ARGS} >/dev/null 2>\"\$err\"; then
			echo 'i18n: ${DOMAIN}: make-pot に失敗しました' >&2
			cat \"\$err\" >&2
			exit 1
		fi
		if [ -s \"\$err\" ]; then
			echo 'i18n: ${DOMAIN}: make-pot が警告を出しました:' >&2
			cat \"\$err\" >&2
			exit 1
		fi
		${after}
	"
}

cmd_pot() {
	require_build

	# make-pot は実行のたびに POT-Creation-Date を書き換える。ほかに違いが無ければ元の POT を残す（npm run i18n:pot は両プラグインを作り直すので、
	# 片方の文字列だけを変えた PR に、もう片方の POT の日時だけの差分を出さない。review-loop R1-L2）。
	local fresh="${POT}.new"
	rm -f "$fresh"
	if ! make_pot "$fresh"; then
		rm -f "$fresh"
		exit 1
	fi

	if [ -f "$POT" ] && cmp -s <(grep -v '^"POT-Creation-Date: ' "$fresh") <(grep -v '^"POT-Creation-Date: ' "$POT"); then
		rm -f "$fresh"
		echo "i18n: ${DOMAIN}: ${POT} は変わっていません"
	else
		mv -f "$fresh" "$POT"
		echo "i18n: ${DOMAIN}: ${POT} を作り直しました"
	fi
}

cmd_po() {
	if [ ! -f "$POT" ]; then
		echo "i18n: ${DOMAIN}: ${POT} がありません。先に npm run i18n:pot を実行してください" >&2
		exit 1
	fi

	if [ ! -f "$PO" ]; then
		# 日本語は複数形が 1 つ（Plural-Forms）。update-po はこのヘッダに合わせて msgstr[0] だけの項目を作る。
		cat >"$PO" <<HEADER
msgid ""
msgstr ""
"Project-Id-Version: ${PROJECT}\n"
"Language: ja\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"Plural-Forms: nplurals=1; plural=0;\n"
"X-Domain: ${DOMAIN}\n"
HEADER
	fi

	wpcli i18n update-po "$POT" "$PO" >/dev/null
	echo "i18n: ${DOMAIN}: ${PO} を ${POT} に合わせました（足された文字列は訳が空です）"
}

cmd_compile() {
	if [ "$COMPILE" != 1 ]; then
		echo "i18n: ${DOMAIN}: 翻訳を同梱しないので作りません（translate.wordpress.org の言語パック。issue #144）"
		return 0
	fi

	if [ ! -f "$PO" ]; then
		echo "i18n: ${DOMAIN}: ${PO} がありません" >&2
		exit 1
	fi

	wpcli i18n make-mo "$PO" languages >/dev/null
	wpcli i18n make-php "$PO" languages >/dev/null
	echo "i18n: ${DOMAIN}: .mo・.l10n.php を作り直しました"
}

cmd_check() {
	require_build

	if [ ! -f "$POT" ]; then
		echo "i18n: ${DOMAIN}: ${POT} がありません" >&2
		exit 1
	fi

	# 作り直した POT はリポジトリに書かず、コンテナの /tmp に置いて比べ終わったら消す（作業ツリーに一時ファイルを残さない）。
	local fresh="/tmp/${DOMAIN}-check.pot"
	make_pot "$fresh" "rc=0; wp eval-file '${CHECK_PHP}' '${fresh}' '${POT}' || rc=\$?; rm -f '${fresh}'; exit \$rc"
}

[ $# -ge 1 ] || usage
CMD=$1
shift
case "$CMD" in
	pot | po | compile | check) ;;
	*) usage ;;
esac

PLUGINS='cart-bridge-jp cart-bridge-jp-pro'
while [ $# -gt 0 ]; do
	case "$1" in
		--plugin)
			# 1 語の既知のスラッグだけ（空白だけ・複数語の値を、何もしない・途中まで動いてから止まる形で通さない。review-loop R1-L1）。
			[ $# -ge 2 ] || usage
			case "$2" in
				cart-bridge-jp | cart-bridge-jp-pro) PLUGINS=$2 ;;
				*) usage ;;
			esac
			shift
			;;
		*) usage ;;
	esac
	shift
done

# プラグインごとに別のサブシェルで実行する（cd と設定を持ち越さない）。どれかが失敗したら set -e でそこで止まる。
for slug in $PLUGINS; do
	(
		use_plugin "$slug"
		cd "$DIR"
		mkdir -p languages
		"cmd_${CMD}"
	)
done
