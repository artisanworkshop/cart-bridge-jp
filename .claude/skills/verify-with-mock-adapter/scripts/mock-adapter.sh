#!/usr/bin/env bash
# wp-env の開発サイトで mock アダプタを使って検証するための補助。詳細は ../SKILL.md。
# 使い方（リポジトリルートから）:
#   mock-adapter.sh inspect                  開発サイトの既存データを表示する（検証の前後で比較する）
#   mock-adapter.sh install <platform-key>   mock アダプタを <platform-key> に登録する mu-plugin を置く
#   mock-adapter.sh run <php-file>           リポジトリ内の PHP を wp eval-file で実行する（seed / verify / cleanup）
#   mock-adapter.sh uninstall                このスクリプトが置いた mock アダプタの mu-plugin だけを削除する
#   mock-adapter.sh pro-unlock               Pro をキー無しで「有効化済み」と読ませる mu-plugin を置く（社内テスト用。production では効かない）
#   mock-adapter.sh pro-lock                 pro-unlock が置いた mu-plugin だけを削除する
# wp-env は `npx wp-env`（グローバルの wp-env は使わない）。Node は .nvmrc の版を PATH に通しておくこと。
set -euo pipefail

MU_FILE_NAME="cbjp-verify-mock-adapter.php"
MARKER="cbjp-verify-mock-adapter: managed by .claude/skills/verify-with-mock-adapter"
UNLOCK_FILE_NAME="cbjp-verify-pro-unlock.php"
UNLOCK_MARKER="cbjp-verify-pro-unlock: managed by .claude/skills/verify-with-mock-adapter"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="$HERE/../templates/mu-plugin-mock-adapter.php"
UNLOCK_TEMPLATE="$HERE/../templates/mu-plugin-pro-unlock.php"

# `wp-env install-path` はこのリポジトリのインスタンスの場所（~/.wp-env/<hash>）を返す。
# 他プロジェクトの wp-env と取り違えないため、`docker ps` や hash の総当たりではなくこれを使う。
# 取得できなかった／存在しないパスのときは**必ず失敗する**（空のまま連結すると `/WordPress/wp-content/mu-plugins`＝
# ホストのルート直下を対象にしてしまう）。`mu_dir` を呼ぶ側は `set -e` 下で `$(mu_dir)` の失敗を検知できるよう、
# 代入で受けてから使うこと。
install_path() {
  local p
  p=$(npx wp-env install-path 2>/dev/null) || { echo "npx wp-env install-path failed (is wp-env installed / run from the repo root?)" >&2; return 1; }
  p=${p%$'\n'}
  if [ -z "$p" ] || [ ! -d "$p/WordPress" ]; then
    echo "wp-env install path is empty or has no WordPress directory: '$p' (is wp-env started for this repo?)" >&2
    return 1
  fi
  printf '%s\n' "$p"
}
mu_dir() {
  local p
  p=$(install_path) || return 1
  printf '%s\n' "$p/WordPress/wp-content/mu-plugins"
}
# wp-env の失敗（未起動・別プロジェクト・PHP の致命的エラー）は握りつぶさず、そのまま非ゼロで返す。
# 出力は wp-env の進捗行（ℹ / ✔）と空行だけを落とす。`grep -v` は「全行が落ちて出力が空」のとき終了コード 1 を返すが、
# それは失敗ではないので無視する（`|| true` をパイプ全体に付けると wp-env 側の失敗まで隠れてしまう）。
wp() {
  local out rc
  # `set -e` 下で `out=$(…)` を単独の代入文にすると、wp-env が失敗した時点でスクリプトごと終了し、下の出力に
  # 到達しない（エラー本文が消える）。`if` の条件位置に置いて errexit を回避し、終了コードを自分で受ける。
  if out=$(npx wp-env run cli --env-cwd=wp-content/cbjp-dev wp "$@" 2>&1); then rc=0; else rc=$?; fi
  printf '%s\n' "$out" | grep -v '^ℹ\|^✔\|^$' || true
  return "$rc"
}

# `wp eval-file` に渡す一時の PHP をリポジトリのルートに作る（コンテナからは wp-content/cbjp-dev に見える）: eval_tmp <名前>。
# 名前は呼び出しごとに mktemp で作る（固定の名前だと、同時に走った別の呼び出しが書きかけのファイルを実行し、相手の `rm` で消される。PR #143 Codex）。
# mktemp は 0600 で作るので 644 にする（wp-env の cli はホストの uid で動くが、rootless Docker・userns-remap ではコンテナの uid が
# ホストの別の uid に写り、0600 だと `wp eval-file` が読めない。PR #143 Codex）。
eval_tmp() {
  local f
  f=$(mktemp "./.tmp-verify-$1.XXXXXX") || return 1
  chmod 644 "$f" || { rm -f "$f"; return 1; }
  printf '%s\n' "$f"
}
# mu-plugin を置く途中の一時ファイルの名前の頭（置き先と同じディレクトリの `.<置き先の名前>.`。拡張子が .php でないので WordPress は読み込まない）。
# 名前の残りは呼び出しごとに mktemp で作る（固定の名前だと、同時に走った別の呼び出しの書いた中身を検査して置き、残しておいた目印の無い
# ファイルも上書きする。PR #143 Codex）。
mu_tmp_prefix() {
  printf '%s/.%s.\n' "$(dirname "$1")" "$(basename "$1")"
}
# 管理する mu-plugin を置く: install_mu <置き先> <目印> <生成コマンド...>（生成コマンドの標準出力を書く）。
# 置き先へ直接書かない。書いている途中で止まると、読みかけの PHP で dev サイトの全要求が fatal になり、WP を起動する
# `run`・`inspect` も動かなくなる（.claude/rules/skill-scripts.md「中断で壊れると復旧できないファイル」。PR #143）。
# この呼び出しだけの一時ファイルを隣に作って書き、目印と `php -l` を確かめてから `mv -f` で置き換える。目印の無い既存のファイルは上書きしない
# （他セッション由来の mu-plugin を壊さない）。`f || exit` の形で呼ぶので、中の失敗は `return 1` で返す（set -e は効かない）。
install_mu() {
  local dest=$1 marker=$2 tmp lint_out=''
  shift 2
  if [ -e "$dest" ] && ! grep -qF "$marker" "$dest"; then
    echo "refusing to overwrite $dest (not managed by this skill)" >&2; return 1
  fi
  tmp=$(mktemp "$(mu_tmp_prefix "$dest")XXXXXX") || { echo "could not create a temporary file next to $dest" >&2; return 1; }
  "$@" > "$tmp" || { rm -f "$tmp"; echo "could not write $tmp" >&2; return 1; }
  if ! grep -qF "$marker" "$tmp" || ! lint_out=$(php -l "$tmp" 2>&1); then
    printf '%s\n' "$lint_out" >&2
    rm -f "$tmp"
    echo "the mu-plugin was not written as expected; nothing was installed" >&2
    return 1
  fi
  { chmod 644 "$tmp" && mv -f "$tmp" "$dest"; } || { rm -f "$tmp"; echo "could not install $dest" >&2; return 1; }
  echo "installed $dest"
}
# 管理する mu-plugin を消す: remove_mu <置き先> <目印>。目印の無いファイルは消さない。置く途中で止まって残った一時ファイル
# （`.<置き先の名前>.*`。前の版の `.tmp` も含む）も、目印があれば消す（目印の無い一時ファイルは WordPress が読み込まないので、知らせて残す）。
# 同時に走っている install_mu の一時ファイルも消しうるが、そのときの install_mu は目印・`php -l`・`mv` のどこかで失敗し、何も置かない。
remove_mu() {
  local dest=$1 marker=$2 tmp removed=0
  if [ -e "$dest" ]; then
    grep -qF "$marker" "$dest" || { echo "not managed by this skill; left in place: $dest" >&2; return 1; }
    rm -f "$dest" && [ ! -e "$dest" ] || { echo "could not remove $dest" >&2; return 1; }
    echo "removed $dest"; removed=1
  fi
  for tmp in "$(mu_tmp_prefix "$dest")"*; do
    [ -e "$tmp" ] || continue
    if grep -qF "$marker" "$tmp"; then
      rm -f "$tmp" && [ ! -e "$tmp" ] || { echo "could not remove $tmp" >&2; return 1; }
      echo "removed $tmp"; removed=1
    else
      echo "left in place (not managed by this skill; WordPress does not load it): $tmp" >&2
    fi
  done
  [ "$removed" -eq 1 ] || echo "nothing to remove ($dest does not exist)"
}

cmd=${1:-}
case "$cmd" in
  inspect)
    tmp=$(eval_tmp inspect) || exit 1
    cat > "$tmp" <<'PHP' || { rm -f "$tmp"; echo "could not write $tmp" >&2; exit 1; }
<?php
global $wpdb;
$t = $wpdb->prefix . 'cbjp_mappings';
echo "mappings by platform/entity:\n";
foreach ( $wpdb->get_results( "SELECT platform, entity_type, COUNT(*) c FROM {$t} GROUP BY platform, entity_type", ARRAY_A ) as $r ) {
	echo "  {$r['platform']} / {$r['entity_type']}: {$r['c']}\n";
}
echo 'cbjp_* options: ' . implode( ' ', $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cbjp_%' ORDER BY option_name" ) ) . "\n";
echo 'users: ' . count_users()['total_users'] . ' | orders: ' . count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ) . "\n";
echo 'ZZV- mappings left: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE remote_id LIKE 'ZZV-%'" ) . "\n";
PHP
    if wp eval-file "$tmp"; then rc=0; else rc=$?; fi
    rm -f "$tmp"
    [ "$rc" -eq 0 ] || exit "$rc"
    dir=$(mu_dir) || exit 1
    echo "mu-plugins: $(ls "$dir" 2>/dev/null | tr '\n' ' ')"
    ;;
  install)
    key=${2:?usage: mock-adapter.sh install <platform-key>}
    case "$key" in *[!a-z0-9_-]*|'') echo "invalid platform key: $key" >&2; exit 2 ;; esac
    dir=$(mu_dir) || exit 1
    mkdir -p "$dir"
    install_mu "$dir/$MU_FILE_NAME" "$MARKER" sed "s/__PLATFORM_KEY__/$key/g" "$TEMPLATE" || exit 1
    echo "mock adapter registered as '$key'"
    ;;
  run)
    file=${2:?usage: mock-adapter.sh run <php-file>}
    [ -f "$file" ] || { echo "no such file: $file" >&2; exit 2; }
    wp eval-file "$file"
    ;;
  uninstall)
    dir=$(mu_dir) || exit 1
    remove_mu "$dir/$MU_FILE_NAME" "$MARKER" || exit 1
    ;;
  pro-unlock)
    # Pro の有効化の記録を option の読み出し（`pre_option_cbjp_pro_license_state`）で「有効化済み」に差し替え、キーは空と読ませる
    # mu-plugin を置く。DB には書かない（詳細は templates/mu-plugin-pro-unlock.php の冒頭）。置いた後に、差し替えが実際に効いているかを
    # 肯定形で確かめる: 環境種別が production でない・Pro が起動している・差し替えのフィルターが登録された・Pro が「有効化済み」と読み、
    # キーを読めない。どれかが欠ければ失敗で終わる（ファイルは残す: 環境を直せばそのまま効く。外すときは pro-lock）。
    # 確かめる PHP は mu-plugin を置く前に作る（作れなければ何も置かずに終わる）。置いた後の失敗は、mu-plugin が残っていることを必ず知らせる。
    dir=$(mu_dir) || exit 1
    mkdir -p "$dir"
    tmp=$(eval_tmp pro-unlock) || exit 1
    cat > "$tmp" <<'PHP' || { rm -f "$tmp"; echo "could not write $tmp" >&2; exit 1; }
<?php
$env    = wp_get_environment_type();
// Pro が無効なら autoload が無いので class_exists() は偽。有効でも前提（WooCommerce・無料版の版）が欠ければ起動しない。
// Core\Plugin と LicenseStore は何も継承しないので、起動していないサイトで読み込んでも fatal にならない。
$booted = class_exists( \CartBridgeJP\Pro\Core\Plugin::class ) && \CartBridgeJP\Pro\Core\Plugin::instance()->is_booted();
$filter = false !== has_filter( 'pre_option_cbjp_pro_license_state' ) && false !== has_filter( 'pre_option_cbjp_pro_license_key' );
$store  = $booted ? new \CartBridgeJP\Pro\License\LicenseStore() : null;
$active = null !== $store && $store->is_activated();
$hidden = null !== $store && null === $store->key();
echo "environment: {$env}\n";
echo 'pro booted: ' . ( $booted ? 'yes' : 'no' ) . "\n";
echo 'unlock filters: ' . ( $filter ? 'yes' : 'no' ) . "\n";
echo 'pro activated: ' . ( $active ? 'yes' : 'no' ) . "\n";
echo 'license key hidden: ' . ( $hidden ? 'yes' : 'no' ) . "\n";
echo 'unlock effective: ' . ( 'production' !== $env && $booted && $filter && $active && $hidden ? 'yes' : 'no' ) . "\n";
PHP
    install_mu "$dir/$UNLOCK_FILE_NAME" "$UNLOCK_MARKER" cat "$UNLOCK_TEMPLATE" || { rm -f "$tmp"; exit 1; }
    if out=$(wp eval-file "$tmp"); then rc=0; else rc=$?; fi
    rm -f "$tmp"
    printf '%s\n' "$out"
    if [ "$rc" -ne 0 ]; then
      echo "installed but could not check whether the unlock is effective (wp eval-file failed). The mu-plugin is still installed; remove it with: mock-adapter.sh pro-lock" >&2
      exit "$rc"
    fi
    if printf '%s\n' "$out" | grep -qx 'unlock effective: yes'; then
      echo "Pro now treats this site as activated without a key (customers/orders/coupons and password emails run). Remove with: mock-adapter.sh pro-lock"
    else
      echo "installed but NOT effective (see the lines above: production site, Pro not booted, or the filters are not registered). Fix the environment or remove with: mock-adapter.sh pro-lock" >&2
      exit 1
    fi
    ;;
  pro-lock)
    dir=$(mu_dir) || exit 1
    remove_mu "$dir/$UNLOCK_FILE_NAME" "$UNLOCK_MARKER" || exit 1
    ;;
  *)
    sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 2
    ;;
esac
