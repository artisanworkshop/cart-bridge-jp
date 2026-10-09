#!/usr/bin/env bash
# wordpress.org 用スクリーンショットを wp-env の tests サイトで撮る。詳細は ../SKILL.md。
# 使い方（リポジトリルートから）:
#   capture.sh shoot [--out DIR]   撮影する（既定の出力先は plugins/cart-bridge-jp/.wordpress-org/。終わると撮影の run・偽のトークン・mu-plugin・Cookie を片付ける）
#   capture.sh status              撮影用 mu-plugin の有無と tests サイトの URL を表示する
#   capture.sh cleanup             撮影の run と偽のトークンを片付けてから、このスキルが置いた mu-plugin を消す（強制終了した後など）
# wp-env は `npx wp-env`（グローバルの wp-env は使わない）。dev サイト（10010）には触れない。
set -euo pipefail

MU_FILE_NAME="cbjp-wporg-screenshots.php"
MARKER="cbjp-wporg-screenshots: managed by .claude/skills/wporg-screenshots"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_DIR="$(cd "$HERE/.." && pwd)"
TEMPLATE="$SKILL_DIR/templates/mu-plugin-screenshot-fixtures.php"
SHOTS="$SKILL_DIR/shots.json"
# コンテナの中では --env-cwd（リポジトリのルートをマウントした wp-content/cbjp-dev）からの相対パスで読む。
SETUP_PHP=".claude/skills/wporg-screenshots/php/setup.php"
TEARDOWN_PHP=".claude/skills/wporg-screenshots/php/teardown.php"

die() { echo "capture: $*" >&2; exit 1; }

# 無料版のディレクトリ（D29）。readme とスクリーンショット（SVN の assets/ へ置くもの）はここにある。
PLUGIN_DIR="plugins/cart-bridge-jp"

[ -f "$PLUGIN_DIR/cart-bridge-jp.php" ] && [ -f .wp-env.json ] || die "run this from the repository root"

# `wp-env install-path` はこのリポジトリのインスタンスの場所（~/.wp-env/<hash>）を返す（mock-adapter.sh と同じ）。
# 取得できない・tests サイトのディレクトリが無いときは必ず失敗する（空のまま連結するとホストのルート直下を指す）。
tests_mu_dir() {
  local p
  p=$(npx wp-env install-path 2>/dev/null) || { echo "npx wp-env install-path failed (is wp-env installed / run from the repo root?)" >&2; return 1; }
  p=${p%$'\n'}
  if [ -z "$p" ] || [ ! -d "$p/tests-WordPress" ]; then
    echo "wp-env install path is empty or has no tests-WordPress directory: '$p' (is wp-env started for this repo?)" >&2
    return 1
  fi
  printf '%s\n' "$p/tests-WordPress/wp-content/mu-plugins"
}

# tests サイトの URL は .wp-env.json の testsPort から作る（dev サイトの port と取り違えない）。
tests_url() {
  local port
  port=$(node -p "require('./.wp-env.json').testsPort") || return 1
  case "$port" in ''|*[!0-9]*) echo "testsPort in .wp-env.json is not a number: '$port'" >&2; return 1 ;; esac
  printf 'http://localhost:%s\n' "$((10#$port))"
}

# tests-cli の WP-CLI。失敗（未起動・PHP の致命的エラー）は握りつぶさず、出力を見せてから同じ終了コードで返す。
twp() {
  local out rc
  if out=$(npx wp-env run tests-cli --env-cwd=wp-content/cbjp-dev wp "$@" 2>&1 </dev/null); then rc=0; else rc=$?; fi
  printf '%s\n' "$out" | grep -v '^ℹ\|^✔\|^$' || true
  return "$rc"
}

# このスキルが置いたファイルだけを消す。目印の無いファイルは消さずに失敗する。
# `remove_mu … || …` の形で呼ばれると関数の中では set -e が効かないので、失敗はすべて明示的に返す。
remove_mu() {
  local dest=$1
  [ -e "$dest" ] || return 0
  grep -q "$MARKER" "$dest" || { echo "not removing $dest (not managed by this skill)" >&2; return 1; }
  rm "$dest" || return 1
  [ ! -e "$dest" ] || { echo "could not remove $dest" >&2; return 1; }
  echo "removed $dest"
}

# 撮影の run と偽のトークンを、mu-plugin（API のモック）がまだある間に片付けてから mu-plugin を消す。モックを先に消すと、
# 残った run のアクションや偽のトークンで管理画面・WP-Cron が動いたとき実 API に通信が出うる（PR #112 G1-2）。
# 後片付けに失敗したら mu-plugin は残して失敗を返す（`… || …` の形で呼ばれるので、失敗はすべて明示的に返す）。
teardown_and_remove() {
  local dest=$1 url=$2
  if ! twp eval-file "$TEARDOWN_PHP" "$url"; then
    echo "capture: teardown failed; keeping the mu-plugin so that nothing reaches the real Color Me Shop API: $dest (fix the cause, then run: $0 cleanup)" >&2
    return 1
  fi
  remove_mu "$dest" || { echo "capture: the mu-plugin may still be installed: $dest (run: $0 cleanup)" >&2; return 1; }
}

readme_captions() {
  awk '/^== Screenshots ==/ { f = 1; next } /^== / { f = 0 } f && /^[0-9]+\. / { print }' "$PLUGIN_DIR/readme.txt"
}

cmd=${1:-}
case "$cmd" in
  status)
    url=$(tests_url) || exit 1
    dir=$(tests_mu_dir) || exit 1
    echo "tests site: $url"
    if [ -e "$dir/$MU_FILE_NAME" ]; then echo "mu-plugin: PRESENT ($dir/$MU_FILE_NAME). Run: $0 cleanup"; else echo "mu-plugin: absent"; fi
    ;;

  cleanup)
    url=$(tests_url) || exit 1
    dir=$(tests_mu_dir) || exit 1
    teardown_and_remove "$dir/$MU_FILE_NAME" "$url" || exit 1
    [ ! -e "$dir/$MU_FILE_NAME" ] || die "the mu-plugin is still there: $dir/$MU_FILE_NAME"
    echo "clean"
    ;;

  shoot)
    shift
    out="$PLUGIN_DIR/.wordpress-org"
    while [ $# -gt 0 ]; do
      case "$1" in
        --out) [ $# -ge 2 ] || die "--out needs a directory"; out=$2; shift 2 ;;
        --out=*) out=${1#--out=}; shift ;;
        *) die "unknown option: $1" ;;
      esac
    done
    [ -n "$out" ] || die "--out is empty"

    # 撮る枚数と readme のキャプションの数が合わないと ReadmeTest が落ちる。先にそろえてもらう。
    shots_count=$(node -p "require('$SHOTS').shots.length") || die "could not read $SHOTS"
    captions=$(readme_captions)
    captions_count=$(printf '%s\n' "$captions" | grep -c . || true)
    [ "$shots_count" = "$captions_count" ] || die "shots.json has $shots_count shots but readme.txt has $captions_count screenshot captions. Make them match first."
    entities=$(node -p "require('$SHOTS').dry_run_entities.join(',')") || die "could not read dry_run_entities from $SHOTS"
    [ -n "$entities" ] || die "dry_run_entities in $SHOTS is empty"

    node -e "require.resolve('playwright', { paths: [ process.cwd() ] })" 2>/dev/null || die "playwright is not installed under node_modules (run npm install)"
    # 管理画面は build/ を読む。古いビルドだと変える前の画面を撮るので、毎回ビルドする（数秒）。
    # 更新日時では判定できない（webpack は中身が同じファイルを書き直さないので、最新でも src/ より古く見える）。
    if build_out=$(npm run build 2>&1); then :; else
      printf '%s\n' "$build_out" | tail -n 20 >&2
      die "npm run build failed"
    fi
    echo "built the admin UI"

    url=$(tests_url) || exit 1
    code=$(curl -s -o /dev/null -w '%{http_code}' "$url/") || die "the tests site $url is not reachable (npx wp-env start)"
    case "$code" in 2??|3??) ;; *) die "the tests site $url answered HTTP $code (npx wp-env start)" ;; esac

    dir=$(tests_mu_dir) || exit 1
    dest="$dir/$MU_FILE_NAME"
    if [ -e "$dest" ] && ! grep -q "$MARKER" "$dest"; then
      die "refusing to overwrite $dest (not managed by this skill)"
    fi
    mkdir -p "$out"
    if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then die "mktemp failed"; fi

    # 途中で止まっても run・偽のトークン・mu-plugin・Cookie を残さない。最後まで走ったことは DONE で確かめる
    # （set -e と EXIT トラップを併せ持つと、macOS の bash 3.2 は異常終了を 0 で返すことがある。.claude/rules/skill-scripts.md）。
    # EXIT トラップの中でも set -e は効くので、どの片付けが失敗しても残りを続け、失敗は rc に残す。mu-plugin の片付けを先に行う。
    DONE=0
    MU_INSTALLED=0
    finish() {
      local rc=$?
      if [ "$MU_INSTALLED" = 1 ]; then
        teardown_and_remove "$dest" "$url" || rc=1
      fi
      rm -rf "$TMP" || rc=1
      if [ "$DONE" != 1 ] && [ "$rc" = 0 ]; then
        echo "capture: stopped before the end" >&2
        rc=1
      fi
      exit "$rc"
    }
    trap finish EXIT
    trap 'exit 130' INT TERM

    mkdir -p "$dir"
    MU_INSTALLED=1
    cp "$TEMPLATE" "$dest"
    echo "installed $dest"

    twp plugin activate woocommerce cart-bridge-jp
    twp rewrite structure '/%postname%/' --hard

    if setup_out=$(twp eval-file "$SETUP_PHP" "$url" "$entities"); then rc=0; else rc=$?; fi
    printf '%s\n' "$setup_out" | grep -v '^COOKIES=' || true
    [ "$rc" = 0 ] || die "setup failed (exit $rc)"
    run_id=$(printf '%s\n' "$setup_out" | sed -n 's/^RUN_ID=//p')
    printf '%s\n' "$setup_out" | sed -n 's/^COOKIES=//p' > "$TMP/cookies.json"
    [ -n "$run_id" ] || die "setup did not print RUN_ID"
    [ -s "$TMP/cookies.json" ] || die "setup did not print COOKIES"

    # 一時ディレクトリに全部撮れてから出力先へ写す（途中で失敗したとき、新旧の画像が混ざらない）。
    mkdir "$TMP/shots"
    node "$HERE/shoot.cjs" "$url" "$TMP/cookies.json" "$run_id" "$SHOTS" "$TMP/shots"
    cp "$TMP"/shots/screenshot-*.png "$out"/

    # 枚数を減らしたときに残る、余った番号の画像を知らせる（消すかどうかは人が決める。ReadmeTest も連番の食い違いで落ちる）。
    for f in "$out"/screenshot-*.png; do
      num=${f##*/screenshot-}
      num=${num%.png}
      case "$num" in ''|*[!0-9]*) continue ;; esac
      if [ "$((10#$num))" -gt "$shots_count" ]; then
        echo "capture: WARNING: $f is beyond the $shots_count shots in shots.json (remove it if the readme no longer has that caption)" >&2
      fi
    done

    echo
    echo "screenshots in $out (check each against its readme caption):"
    n=0
    printf '%s\n' "$captions" | while IFS= read -r line; do
      n=$((n + 1))
      echo "  screenshot-$n.png  ${line#*. }"
    done
    DONE=1
    ;;

  *)
    sed -n '2,7p' "$0" >&2
    exit 2
    ;;
esac
