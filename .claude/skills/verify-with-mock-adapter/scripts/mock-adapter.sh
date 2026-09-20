#!/usr/bin/env bash
# wp-env の開発サイトで mock アダプタを使って検証するための補助。詳細は ../SKILL.md。
# 使い方（リポジトリルートから）:
#   mock-adapter.sh inspect                  開発サイトの既存データを表示する（検証の前後で比較する）
#   mock-adapter.sh install <platform-key>   mock アダプタを <platform-key> に登録する mu-plugin を置く
#   mock-adapter.sh run <php-file>           リポジトリ内の PHP を wp eval-file で実行する（seed / verify / cleanup）
#   mock-adapter.sh uninstall                このスクリプトが置いた mu-plugin だけを削除する
# wp-env は `npx wp-env`（グローバルの wp-env は使わない）。Node は .nvmrc の版を PATH に通しておくこと。
set -euo pipefail

MU_FILE_NAME="cbjp-verify-mock-adapter.php"
MARKER="cbjp-verify-mock-adapter: managed by .claude/skills/verify-with-mock-adapter"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="$HERE/../templates/mu-plugin-mock-adapter.php"

# `wp-env install-path` はこのリポジトリのインスタンスの場所（~/.wp-env/<hash>）を返す。
# 他プロジェクトの wp-env と取り違えないため、`docker ps` や hash の総当たりではなくこれを使う。
install_path() { npx wp-env install-path 2>/dev/null; }
mu_dir() { echo "$(install_path)/WordPress/wp-content/mu-plugins"; }
# wp-env の失敗（未起動・別プロジェクト・PHP の致命的エラー）は握りつぶさず、そのまま非ゼロで返す。
# 出力は wp-env の進捗行（ℹ / ✔）と空行だけを落とす。`grep -v` は「全行が落ちて出力が空」のとき終了コード 1 を返すが、
# それは失敗ではないので無視する（`|| true` をパイプ全体に付けると wp-env 側の失敗まで隠れてしまう）。
wp() {
  local out rc
  out=$(npx wp-env run cli --env-cwd=wp-content/plugins/cart-bridge-jp wp "$@" 2>&1); rc=$?
  printf '%s\n' "$out" | grep -v '^ℹ\|^✔\|^$' || true
  return "$rc"
}

cmd=${1:-}
case "$cmd" in
  inspect)
    tmp=".tmp-verify-inspect.php"
    cat > "$tmp" <<'PHP'
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
    wp eval-file "$tmp"
    rm -f "$tmp"
    echo "mu-plugins: $(ls "$(mu_dir)" 2>/dev/null | tr '\n' ' ')"
    ;;
  install)
    key=${2:?usage: mock-adapter.sh install <platform-key>}
    case "$key" in *[!a-z0-9_-]*|'') echo "invalid platform key: $key" >&2; exit 2 ;; esac
    dest="$(mu_dir)/$MU_FILE_NAME"
    mkdir -p "$(mu_dir)"
    # 自分が置いたファイル以外は上書きしない（他セッション由来の mu-plugin を壊さない）。
    if [ -e "$dest" ] && ! grep -q "$MARKER" "$dest"; then
      echo "refusing to overwrite $dest (not managed by this skill)" >&2; exit 1
    fi
    sed "s/__PLATFORM_KEY__/$key/g" "$TEMPLATE" > "$dest"
    echo "installed $dest (mock adapter registered as '$key')"
    ;;
  run)
    file=${2:?usage: mock-adapter.sh run <php-file>}
    [ -f "$file" ] || { echo "no such file: $file" >&2; exit 2; }
    wp eval-file "$file"
    ;;
  uninstall)
    dest="$(mu_dir)/$MU_FILE_NAME"
    if [ -e "$dest" ]; then
      if grep -q "$MARKER" "$dest"; then rm -f "$dest"; echo "removed $dest"; else echo "not managed by this skill; left in place: $dest" >&2; exit 1; fi
    else
      echo "nothing to remove ($dest does not exist)"
    fi
    ;;
  *)
    sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 2
    ;;
esac
