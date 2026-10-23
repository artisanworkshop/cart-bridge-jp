#!/usr/bin/env bash
# Pro のマニュアル用スクリーンショットの build（shots.json の `build`。共有スキル wporg-screenshots の capture.sh が撮影の前に実行する）。
#
# 1. 管理画面をビルドする（古いビルドは変える前の画面を写す）。
# 2. 無料版の日本語訳を dist/pro-manual-i18n/ に作る（.mo・.l10n.php・JS の JSON）。無料版は訳を同梱せず translate.wordpress.org の
#    言語パックで配るので（issue #144）、撮影の間だけ setup.php がこれを tests サイトの wp-content/languages/plugins/ に置き、
#    言語パックが入ったサイトと同じ日本語の画面にする。teardown.php が外す。dist/ は .gitignore 済みのローカル出力（ルートのマウントは HTTP で 403）。
#
# リポジトリのルートで実行する（capture.sh はルートから build を実行する）。
set -euo pipefail

ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"

OUT_REL=dist/pro-manual-i18n
PO=plugins/cart-bridge-jp/languages/cart-bridge-jp-ja.po

[ -f "$PO" ] || { echo "build: no $PO" >&2; exit 1; }

if build_out=$(npm run build 2>&1); then :; else
	printf '%s\n' "$build_out" | tail -n 20 >&2
	echo "build: npm run build failed" >&2
	exit 1
fi
echo "built: npm run build"

mkdir -p "$OUT_REL"
# 前回の出力を消す（自分が作る種類のファイルだけ。このディレクトリには他のものを置かない）。
find "$OUT_REL" -maxdepth 1 -type f \( -name 'cart-bridge-jp-ja*.mo' -o -name 'cart-bridge-jp-ja*.l10n.php' -o -name 'cart-bridge-jp-ja-*.json' \) -delete

# WP-CLI は wp-env の cli コンテナで実行する（ルートは wp-content/cbjp-dev にマウントされている）。
if i18n_out=$(npx wp-env run cli --env-cwd=wp-content/cbjp-dev -- sh -c \
	"wp i18n make-mo $PO $OUT_REL && wp i18n make-php $PO $OUT_REL && wp i18n make-json $PO $OUT_REL --no-purge" 2>&1 </dev/null); then :; else
	printf '%s\n' "$i18n_out" | tail -n 20 >&2
	echo "build: compiling the free plugin's Japanese translation failed" >&2
	exit 1
fi

count_mo=$(find "$OUT_REL" -maxdepth 1 -name 'cart-bridge-jp-ja.mo' | wc -l | tr -d ' ')
count_php=$(find "$OUT_REL" -maxdepth 1 -name 'cart-bridge-jp-ja.l10n.php' | wc -l | tr -d ' ')
count_json=$(find "$OUT_REL" -maxdepth 1 -name 'cart-bridge-jp-ja-*.json' | wc -l | tr -d ' ')
if [ "$count_mo" != 1 ] || [ "$count_php" != 1 ] || [ "$count_json" -lt 1 ]; then
	echo "build: expected one .mo, one .l10n.php and at least one JSON in $OUT_REL (got $count_mo, $count_php, $count_json)" >&2
	exit 1
fi
echo "compiled: the free plugin's Japanese translation into $OUT_REL ($count_json JSON)"
