#!/usr/bin/env bash
# 手元の WooCommerce API Manager を wp-env の dev サイトに一時的に入れて、Cart Bridge JP Pro のライセンス・更新の API を実測する。詳細は ../SKILL.md。
# 使い方（リポジトリルートから）:
#   am.sh status                                       状態ファイル・消し残し・mu-plugin・Pro のヘッダーを表示する（何も変えない）
#   am.sh setup --zip <API Manager の zip> [--new-version X.Y.Z]   API Manager・製品・顧客・注文・キー 2 つ・mu-plugin を用意する
#   am.sh measure [all|<グループ>[,<グループ>…]]       生の要求を送り、応答を dist/api-manager/out/<版>/ に保存する
#   am.sh compare                                      今の setup の応答を匿名化して Pro のフィクスチャと比べる（フィクスチャは書き換えない）
#   am.sh e2e <start|reset|activate|check|details|rows|expire|forget|stop>   更新の配信の実機確認（start/stop は Pro のヘッダーの Update URI を出し入れする）
#   am.sh teardown                                     作ったものを消し、mu-plugin を外し、消し残しが 0 であることを確かめる
# wp-env は `npx wp-env`（グローバルの wp-env は使わない）。tests サイト（10011）には触れない。終了コード: 0 成功、1 拒否・失敗、2 使い方の誤り。
set -euo pipefail

MU_FILE_NAME="cbjp-measure-api-manager.php"
MARKER="cbjp-measure-api-manager: managed by .claude/skills/measure-api-manager"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_DIR="$(cd "$HERE/.." && pwd)"
TEMPLATE="$SKILL_DIR/templates/mu-plugin-api-manager.php"
# コンテナの中では --env-cwd（リポジトリのルートをマウントした wp-content/cbjp-dev）からの相対パスで読む。
PHP_REL=".claude/skills/measure-api-manager/php"
OUT="dist/api-manager"
PRO_MAIN="plugins/cart-bridge-jp-pro/cart-bridge-jp-pro.php"
# E2E の間だけ Pro のヘッダーに足す行（販売サイトの URL が決まるまでヘッダーの Update URI は空）。コンテナの中の API Manager を指す。
HEADER_LINE=' * Update URI: http://wordpress/'
HEADER_AFTER=' * Text Domain: cart-bridge-jp-pro'
HEADER_FLAG="$OUT/header-inserted"
# 残っているかの判定は、行が変形していても（空白を整えられた等）拾えるよう、URL の部分で見る（R2-3）。
HEADER_MARK='Update URI: http://wordpress/'

die() { echo "am: $*" >&2; exit 1; }
usage() { sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2; exit 2; }

[ -f "$PRO_MAIN" ] && [ -f .wp-env.json ] && [ -f "$TEMPLATE" ] || die "run this from the repository root"

# dev サイトの URL は .wp-env.json の port から作る（tests サイトの testsPort と取り違えない）。
dev_url() {
  local port
  port=$(node -p "require('./.wp-env.json').port") || return 1
  case "$port" in ''|*[!0-9]*) echo "port in .wp-env.json is not a number: '$port'" >&2; return 1 ;; esac
  printf 'http://localhost:%s\n' "$((10#$port))"
}

# `wp-env install-path` はこのリポジトリのインスタンスの場所（~/.wp-env/<hash>）を返す。取得できない・dev サイトのディレクトリが無いときは失敗する
# （空のまま連結するとホストのルート直下を指す）。
mu_dir() {
  local p
  p=$(npx wp-env install-path 2>/dev/null) || { echo "npx wp-env install-path failed (is wp-env installed / run from the repo root?)" >&2; return 1; }
  p=${p%$'\n'}
  if [ -z "$p" ] || [ ! -d "$p/WordPress" ]; then
    echo "wp-env install path is empty or has no WordPress directory: '$p' (is wp-env started for this repo?)" >&2
    return 1
  fi
  printf '%s\n' "$p/WordPress/wp-content/mu-plugins"
}

# dev サイト（cli）の WP-CLI。失敗（未起動・PHP の致命的エラー）は握りつぶさず、出力を見せてから同じ終了コードで返す。
wp() {
  local out rc
  if out=$(npx wp-env run cli --env-cwd=wp-content/cbjp-dev wp "$@" 2>&1 </dev/null); then rc=0; else rc=$?; fi
  printf '%s\n' "$out" | grep -v '^ℹ\|^✔\|^$' || true
  return "$rc"
}

# プラグインの状態（active・inactive など。入っていなければ空）。WP-CLI 自体が失敗したら、出力を見せて非ゼロで返す
# （出力を捨てると、WP-CLI が起動しない・PHP の致命的エラーを「無効」「入っていない」と取り違える。G1-12）。
plugin_status() {
  local out
  if ! out=$(wp plugin list --name="$1" --field=status); then
    printf '%s\n' "$out" >&2
    echo "could not read the status of the plugin $1 (WP-CLI failed; see above)" >&2
    return 1
  fi
  printf '%s\n' "$out" | grep -E '^(active|active-network|inactive)$' | head -n 1 || true
}

# php/<name>.php を dev サイトで実行する（site= は PHP がサイトを確かめるのに使う）。
php_run() {
  local name=$1
  shift
  wp eval-file "$PHP_REL/$name.php" "site=$SITE" "$@"
}

# 状態ファイル（API Manager のキーを含む）と出力の置き場所が HTTP で読めないこと（rehearse-colorme の guard_output_dir() と同じ）。
guard_output_dir() {
  local port url code
  mkdir -p "$OUT" || return 1
  [ -f "$OUT/.htaccess" ] || printf '%s\n' '# measure-api-manager: holds license keys of the local API Manager. Never serve this directory.' 'Require all denied' > "$OUT/.htaccess" || return 1
  [ -f "$OUT/index.php" ] || printf '%s\n' '<?php' '// Silence is golden.' > "$OUT/index.php" || return 1
  port=${SITE##*:}
  url="http://localhost:${port}/wp-content/cbjp-dev/${OUT}/.probe.json"
  printf '{}\n' > "$OUT/.probe.json" || return 1
  if code=$(curl -s -o /dev/null -w '%{http_code}' "$url"); then :; else code="curl-failed"; fi
  rm -f "$OUT/.probe.json"
  case "$code" in
    403|404) return 0 ;;
    *) echo "refusing to run: $url answered '$code' (the state file holds license keys and must not be served over HTTP)" >&2; return 1 ;;
  esac
}

# このスキルが置いた mu-plugin だけを消す。目印の無いファイルは消さずに失敗する（`… || …` の形で呼ばれるので、失敗は明示的に返す）。
remove_mu() {
  local dest=$1
  [ -e "$dest" ] || return 0
  # 目印は文字どおりに比べる（正規表現だと `.` が任意の 1 文字に一致し、よく似た別のファイルを消しうる。G1-4）。
  grep -qF "$MARKER" "$dest" || { echo "not removing $dest (not managed by this skill)" >&2; return 1; }
  rm "$dest" || return 1
  [ ! -e "$dest" ] || { echo "could not remove $dest" >&2; return 1; }
  echo "removed $dest"
}

# Pro のメインファイルに、未コミット（stage 済みを含む）の変更が無いか。
pro_main_clean() {
  git diff --quiet -- "$PRO_MAIN" && git diff --cached --quiet -- "$PRO_MAIN"
}

# Pro のメインファイルを awk の結果で置き換える。同じディレクトリの一時ファイル（cp -p で権限を引き継ぐ。拡張子が .php でない）に書いてから
# rename する（その場で書き戻すと、途中で止まったときに Pro のメインファイルが空や途中までになり、E2E の間のほかの変更も失われる。G3-3・G3-4）。
# 引数は awk にそのまま渡す（-v … とプログラム）。
rewrite_pro_main() {
  local tmp
  tmp=$(mktemp "$(dirname "$PRO_MAIN")/.cbjp-am-header.XXXXXX") || return 1
  if ! cp -p "$PRO_MAIN" "$tmp" || ! awk "$@" "$PRO_MAIN" > "$tmp" || [ ! -s "$tmp" ]; then
    rm -f "$tmp"
    return 1
  fi
  mv -f "$tmp" "$PRO_MAIN" || { rm -f "$tmp"; return 1; }
}

# E2E のために Update URI の行を足す。ファイルが clean で、ヘッダーに Update URI が無いときだけ。足した後は差分が 1 行の追加だけであることを確かめる。
header_insert() {
  local numstat
  pro_main_clean || { echo "$PRO_MAIN has uncommitted changes; commit or stash them first (e2e start edits this file and must be able to restore it exactly)" >&2; return 1; }
  grep -qxF "$HEADER_AFTER" "$PRO_MAIN" || { echo "could not find the line '$HEADER_AFTER' in $PRO_MAIN" >&2; return 1; }
  # 印を先に置く（書き換えの直後に止まっても、teardown が足した行を探して消せるように）。
  touch "$HEADER_FLAG" || return 1
  rewrite_pro_main -v after="$HEADER_AFTER" -v line="$HEADER_LINE" '{ print } $0 == after { print line }' || return 1
  numstat=$(git diff --numstat -- "$PRO_MAIN") || return 1
  case "$numstat" in
    "1	0	$PRO_MAIN") echo "added '$HEADER_LINE' to $PRO_MAIN (am.sh e2e stop / teardown removes it)" ;;
    *) echo "unexpected change to $PRO_MAIN after adding the Update URI line: '$numstat'" >&2; return 1 ;;
  esac
}

# 足した行だけを消し、無くなったことを確かめる。ほかの未コミットの変更（E2E の間に自分で直したもの）は残し、警告だけ出す
# （teardown を止めると状態ファイルを片付けられない。足した行が無いことが守るべきこと。R1-11）。
header_remove() {
  if grep -qxF "$HEADER_LINE" "$PRO_MAIN"; then
    rewrite_pro_main -v line="$HEADER_LINE" '$0 != line { print }' || return 1
    echo "removed '$HEADER_LINE' from $PRO_MAIN"
  fi
  # 置き換えの途中で止まったときの一時ファイル（未追跡のまま作業ツリーに残る）を消す。
  rm -f "$(dirname "$PRO_MAIN")"/.cbjp-am-header.* || return 1
  if grep -qF "$HEADER_MARK" "$PRO_MAIN"; then
    echo "a line with '$HEADER_MARK' is still in $PRO_MAIN (changed after e2e start?); remove it by hand" >&2
    return 1
  else
    [ "$?" = 1 ] || { echo "could not read $PRO_MAIN" >&2; return 1; }
  fi
  rm -f "$HEADER_FLAG" || return 1
  if ! pro_main_clean; then
    echo "warning: $PRO_MAIN has other uncommitted changes (not made by this skill); check 'git diff -- $PRO_MAIN'" >&2
  fi
}

site_up() {
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/") || { echo "the dev site $SITE is not reachable (npx wp-env start)" >&2; return 1; }
  case "$code" in 2??|3??) return 0 ;; *) echo "the dev site $SITE answered HTTP $code (npx wp-env start)" >&2; return 1 ;; esac
}

cmd=${1:-}
[ -n "$cmd" ] || usage
shift
SITE=$(dev_url) || exit 1

case "$cmd" in
  status)
    site_up || exit 1
    php_run status
    dir=$(mu_dir) || exit 1
    if [ -e "$dir/$MU_FILE_NAME" ]; then echo "mu-plugin: PRESENT ($dir/$MU_FILE_NAME)"; else echo "mu-plugin: absent"; fi
    if [ -e "$HEADER_FLAG" ] || grep -qF "$HEADER_MARK" "$PRO_MAIN"; then echo "Pro header: the temporary Update URI line is PRESENT (am.sh e2e stop)"; else echo "Pro header: no temporary Update URI line"; fi
    ;;

  setup)
    zip=""
    new_version=""
    while [ $# -gt 0 ]; do
      case "$1" in
        --zip) [ $# -ge 2 ] || usage; zip=$2; shift 2 ;;
        --zip=*) zip=${1#--zip=}; shift ;;
        --new-version) [ $# -ge 2 ] || usage; new_version=$2; shift 2 ;;
        --new-version=*) new_version=${1#--new-version=}; shift ;;
        *) echo "unknown option: $1" >&2; usage ;;
      esac
    done
    [ -n "$zip" ] || usage
    [ -f "$zip" ] || die "no such file: $zip"
    am_base=$(basename "$zip")
    case "$am_base" in
      woocommerce-api-manager*.zip) ;;
      *) die "expected a WooCommerce API Manager zip (woocommerce-api-manager*.zip), got: $am_base" ;;
    esac
    case "$am_base" in *[!A-Za-z0-9._-]*) die "unexpected characters in the zip name: $am_base" ;; esac
    if listing=$(unzip -l "$zip" 2>&1); then :; else die "could not read $zip: $listing"; fi
    # 文字列で照合する（大きな一覧を grep -q へパイプすると、grep が先に閉じて printf が SIGPIPE になり、pipefail で正しい zip を拒みうる。G3-1）。
    case "$listing"$'\n' in
      *" woocommerce-api-manager/woocommerce-api-manager.php"$'\n'*) ;;
      *) die "$zip does not hold woocommerce-api-manager/woocommerce-api-manager.php" ;;
    esac

    site_up || exit 1
    guard_output_dir || exit 1
    dir=$(mu_dir) || exit 1
    # 何かを変える前に、mu-plugin を書けることを確かめる（新しい wp-env には mu-plugins が無い。R1-10）。
    mkdir -p "$dir" && [ -w "$dir" ] || die "cannot write to $dir"
    [ ! -e "$dir/$MU_FILE_NAME" ] || die "a mu-plugin named $MU_FILE_NAME is already there: $dir/$MU_FILE_NAME (an earlier run? run: $0 teardown)"
    [ ! -e "$HEADER_FLAG" ] && ! grep -qF "$HEADER_MARK" "$PRO_MAIN" || die "the temporary Update URI line from an earlier run is still in $PRO_MAIN (run: $0 teardown)"
    php_run preflight || die "preflight refused (nothing was changed)"

    current=$(sed -n 's/^ \* Version: *\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\) *$/\1/p' "$PRO_MAIN" | head -n 1)
    [ -n "$current" ] || die "could not read the X.Y.Z Version header of $PRO_MAIN"
    if [ -z "$new_version" ]; then
      new_version="${current%.*}.$(( ${current##*.} + 1 ))"
    fi
    case "$new_version" in *[!0-9.]*|.*|*.|*..*) die "--new-version must be X.Y.Z (got '$new_version')" ;; esac
    php -r 'exit( preg_match( "/\\A\\d+\\.\\d+\\.\\d+\\z/", $argv[1] ) && version_compare( $argv[1], $argv[2], ">" ) ? 0 : 1 );' "$new_version" "$current" \
      || die "--new-version ($new_version) must be X.Y.Z and newer than the Pro version ($current), or WordPress will not offer it as an update"

    # Pro の zip を作り（中身の検査は build-zip.sh）、版を上げた写しを製品のダウンロードのファイルにする。
    rm -rf "$OUT/build"
    if build_out=$(bin/build-zip.sh --plugin cart-bridge-jp-pro --out "$OUT/build" 2>&1); then :; else
      printf '%s\n' "$build_out" >&2
      die "bin/build-zip.sh --plugin cart-bridge-jp-pro failed"
    fi
    built="$OUT/build/cart-bridge-jp-pro-$current.zip"
    [ -f "$built" ] || die "the Pro zip was not written: $built"
    if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then die "mktemp failed"; fi
    trap 'rm -rf "$TMP"' EXIT
    unzip -q "$built" -d "$TMP" || die "could not unpack $built"
    main="$TMP/cart-bridge-jp-pro/cart-bridge-jp-pro.php"
    sed -e "s/^ \\* Version: $current\$/ * Version: $new_version/" -e "s/define( 'CBJP_PRO_VERSION', '$current' );/define( 'CBJP_PRO_VERSION', '$new_version' );/" "$main" > "$TMP/main.php" || die "could not rewrite the version"
    grep -qxF " * Version: $new_version" "$TMP/main.php" && grep -qF "define( 'CBJP_PRO_VERSION', '$new_version' );" "$TMP/main.php" || die "could not set the version $new_version in the staged Pro"
    cat "$TMP/main.php" > "$main" && rm "$TMP/main.php"
    pro_zip="cart-bridge-jp-pro-$new_version.zip"
    rm -f "$OUT/$pro_zip"
    (cd "$TMP" && zip -rq "$OLDPWD/$OUT/$pro_zip" cart-bridge-jp-pro) || die "could not write $OUT/$pro_zip"
    cp "$zip" "$OUT/$am_base" || die "could not copy $zip into $OUT"

    php_run setup step=init "am_zip=$am_base" "pro_zip=$pro_zip" "pro_version=$current" "new_version=$new_version" || die "setup step=init failed"
    fail_setup() { echo "am: $* -- setup did not finish. Run: $0 teardown" >&2; exit 1; }
    # WP-CLI の作業ディレクトリは --env-cwd（リポジトリのルートのマウント）なので、ルートからの相対パスで渡す。
    wp plugin install "$OUT/$am_base" --activate || fail_setup "could not install API Manager"
    # WP-CLI で有効化しても API Manager のテーブルが作られないことがある（R3-8c）。init の check_version を HTTP の要求で起こす。
    # `npx wp-env run` は中のコマンドの終了コードを 1 にまとめるので、テーブルが無いことは出力で見分ける。
    if tables_out=$(php_run setup step=tables); then printf '%s\n' "$tables_out"; else
      case "$tables_out" in
        *"missing tables:"*) ;;
        *) printf '%s\n' "$tables_out" >&2; fail_setup "setup step=tables failed" ;;
      esac
      echo "API Manager did not create its tables on activation; starting its installer with an HTTP request"
      curl -s -o /dev/null "$SITE/wp-admin/admin-ajax.php" || true
      php_run setup step=tables || fail_setup "API Manager did not create its tables"
    fi
    if data_out=$(php_run setup step=data); then printf '%s\n' "$data_out"; else
      printf '%s\n' "$data_out" >&2
      fail_setup "setup step=data failed"
    fi
    product_id=$(printf '%s\n' "$data_out" | sed -n 's/^PRODUCT_ID=\([0-9][0-9]*\)$/\1/p')
    [ -n "$product_id" ] || fail_setup "setup step=data did not report the product ID"
    # 隣の一時ファイル（拡張子が .php でないので WordPress は読み込まない）に書いて確かめてから、rename で置き換える。
    # 書いている途中で止まると、読みかけの PHP で dev サイトの全要求が fatal になり、teardown も動かなくなる（G2-3）。
    mu_tmp="$dir/.$MU_FILE_NAME.tmp"
    sed "s/__PRODUCT_ID__/$product_id/" "$TEMPLATE" > "$mu_tmp" || { rm -f "$mu_tmp"; fail_setup "could not write the mu-plugin"; }
    if ! grep -qF "$MARKER" "$mu_tmp" || ! grep -qF "(int) '$product_id'" "$mu_tmp" || ! lint_out=$(php -l "$mu_tmp" 2>&1); then
      printf '%s\n' "${lint_out:-}" >&2
      rm -f "$mu_tmp"
      fail_setup "the mu-plugin was not written as expected"
    fi
    chmod 644 "$mu_tmp" && mv -f "$mu_tmp" "$dir/$MU_FILE_NAME" || { rm -f "$mu_tmp"; fail_setup "could not install the mu-plugin"; }
    echo "installed $dir/$MU_FILE_NAME"
    php_run setup step=verify || fail_setup "setup step=verify failed"
    echo "setup: done. Next: $0 measure all"
    ;;

  measure)
    group=${1:-all}
    case "$group" in ''|*[!a-z_,]*) echo "invalid group: $group" >&2; usage ;; esac
    site_up || exit 1
    guard_output_dir || exit 1
    php_run measure "group=$group"
    ;;

  compare)
    [ $# -eq 0 ] || { echo "compare takes no arguments (it compares the responses measured in the current setup)" >&2; usage; }
    site_up || exit 1
    guard_output_dir || exit 1
    php_run compare
    ;;

  e2e)
    step=${1:-}
    case "$step" in
      start)
        site_up || exit 1
        guard_output_dir || exit 1
        free_status=$(plugin_status cart-bridge-jp) || exit 1
        pro_status=$(plugin_status cart-bridge-jp-pro) || exit 1
        [ "$free_status" = active ] && [ "$pro_status" = active ] || die "Cart Bridge JP and Cart Bridge JP Pro must be active on the dev site (cart-bridge-jp: '${free_status}', cart-bridge-jp-pro: '${pro_status}')"
        if [ -e "$HEADER_FLAG" ] || grep -qF "$HEADER_MARK" "$PRO_MAIN"; then
          die "e2e is already started (the temporary Update URI line is in $PRO_MAIN). Run: $0 e2e stop"
        fi
        # ヘッダーを書き換えるなら、dev サイトを変える（リセット）前にファイルが clean であることを確かめる（G1-10）。
        need_insert=1
        if grep -q '^ \* Update URI:' "$PRO_MAIN"; then need_insert=0; fi
        if [ "$need_insert" = 1 ] && ! pro_main_clean; then
          die "$PRO_MAIN has uncommitted changes; commit or stash them first (e2e start edits this file and must be able to restore it exactly). Nothing was changed"
        fi
        # 次にリセットする（setup が済んでいなければここで止まり、ヘッダーは書き換えない）。
        php_run e2e step=reset || die "e2e step=reset failed (has setup finished?)"
        if [ "$need_insert" = 0 ]; then
          # 販売サイトの URL が入った後: ヘッダーは触らない。要求は mu-plugin の接続先の定数で手元へ行く。
          echo "the Pro header already has an Update URI; leaving it as is (requests still go to the local API Manager)"
        else
          header_insert || die "could not add the Update URI line"
        fi
        echo "e2e: started. Next: $0 e2e check (not activated: no request), then activate, check, details, rows, expire, check, stop"
        ;;
      stop)
        header_remove || die "could not restore $PRO_MAIN"
        echo "e2e: stopped (the Update URI line is gone from $PRO_MAIN)"
        ;;
      reset|activate|check|details|rows|expire|forget)
        site_up || exit 1
        php_run e2e "step=$step"
        ;;
      *) echo "unknown e2e step: '$step'" >&2; usage ;;
    esac
    ;;

  teardown)
    site_up || exit 1
    guard_output_dir || exit 1
    dir=$(mu_dir) || exit 1
    # 順序: Pro のヘッダー → データ（API Manager が有効な間）→ API Manager の削除 → そのテーブル・option など → mu-plugin → 数え直し。
    # 途中で失敗したら、その先（とくに mu-plugin の削除）は行わずに止める（もう一度 teardown を走らせれば続きから片付く）。
    if [ -e "$HEADER_FLAG" ] || grep -qF "$HEADER_MARK" "$PRO_MAIN"; then
      header_remove || die "could not restore $PRO_MAIN; nothing else was removed"
    fi
    php_run teardown step=data || die "teardown step=data failed; API Manager and the mu-plugin were kept (fix the cause, then run: $0 teardown)"
    am_status=$(plugin_status woocommerce-api-manager) || die "could not read the status of API Manager; the mu-plugin was kept"
    if [ -n "$am_status" ]; then
      if [ "$am_status" != inactive ]; then
        wp plugin deactivate woocommerce-api-manager || die "could not deactivate API Manager"
      fi
      wp plugin delete woocommerce-api-manager || die "could not delete API Manager"
    fi
    php_run teardown step=am || die "teardown step=am failed; the mu-plugin was kept (fix the cause, then run: $0 teardown)"
    remove_mu "$dir/$MU_FILE_NAME" || die "could not remove the mu-plugin"
    # setup が mu-plugin を書く途中で止まると、一時ファイル（WordPress は読み込まない）が残りうる。目印があれば消す。
    remove_mu "$dir/.$MU_FILE_NAME.tmp" || die "could not remove the temporary mu-plugin file"
    [ ! -e "$dir/.$MU_FILE_NAME.tmp" ] || die "the temporary mu-plugin file is still there: $dir/.$MU_FILE_NAME.tmp"
    if status_out=$(php_run status); then :; else
      printf '%s\n' "$status_out" >&2
      die "could not count the leftovers"
    fi
    printf '%s\n' "$status_out"
    left=$(printf '%s\n' "$status_out" | sed -n 's/^LEFTOVERS=\([0-9][0-9]*\)$/\1/p')
    [ "$left" = 0 ] || die "the dev site still holds what setup created (LEFTOVERS=$left)"
    [ ! -e "$dir/$MU_FILE_NAME" ] || die "the mu-plugin is still there: $dir/$MU_FILE_NAME"
    [ ! -e "$HEADER_FLAG" ] && ! grep -qF "$HEADER_MARK" "$PRO_MAIN" || die "the temporary Update URI line is still in $PRO_MAIN"
    # 状態ファイル（キーを含む）と、写した zip を消す。応答（out/）と匿名化した応答（anon/）は残す。
    rm -f "$OUT/state.json" "$OUT"/woocommerce-api-manager*.zip "$OUT"/cart-bridge-jp-pro-*.zip "$HEADER_FLAG"
    rm -rf "$OUT/build"
    [ ! -e "$OUT/state.json" ] || die "could not remove $OUT/state.json"
    echo "teardown: clean (responses are kept in $OUT/out and $OUT/anon)"
    ;;

  *)
    usage
    ;;
esac
