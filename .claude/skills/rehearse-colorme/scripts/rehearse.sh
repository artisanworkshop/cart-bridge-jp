#!/usr/bin/env bash
# カラーミーのテストショップと wp-env の開発サイトの間で全件リハーサルを回す補助。詳細は ../SKILL.md。
# 使い方（リポジトリルートから）:
#   rehearse.sh php <name> [key=value ...]   php/<name>.php を wp eval-file で実行する（inspect / reset-local / seed-shop / snapshot / diff / check-import / run）
#   rehearse.sh limits-on '<json>'           無料版の上限を差し替える mu-plugin を置き、オプションに {entity: int|null} を保存する
#   rehearse.sh limits-off                   そのオプションと mu-plugin を消す（このスクリプトが置いたものだけ）
# wp-env は `npx wp-env`（グローバルの wp-env は使わない）。Node は .nvmrc の版を PATH に通しておくこと。
set -euo pipefail

MU_FILE_NAME="cbjp-rehearsal-limits.php"
MARKER="cbjp-rehearsal-limits: managed by .claude/skills/rehearse-colorme"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_REL=".claude/skills/rehearse-colorme"
TEMPLATE="$HERE/../templates/mu-plugin-rehearsal-limits.php"

# `mock-adapter.sh` と同じ: 取得できない・存在しないパスでは必ず失敗する（空のまま連結するとホストのルート直下を対象にする）。
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
# wp-env の失敗（未起動・PHP の致命的エラー・スクリプトの ABORT）は握りつぶさず、そのまま非ゼロで返す。
# `set -e` 下で `out=$(…)` を単独の代入文にすると、失敗時に出力を表示する前に終了するので `if` で受ける。
wp() {
  local out rc
  if out=$(npx wp-env run cli --env-cwd=wp-content/plugins/cart-bridge-jp wp "$@" 2>&1); then rc=0; else rc=$?; fi
  printf '%s\n' "$out" | grep -v '^ℹ\|^✔\|^$' || true
  return "$rc"
}

cmd=${1:-}
case "$cmd" in
  php)
    name=${2:?usage: rehearse.sh php <name> [key=value ...]}
    case "$name" in *[!a-z0-9-]*|'') echo "invalid script name: $name" >&2; exit 2 ;; esac
    file="$SKILL_REL/php/$name.php"
    [ -f "$file" ] || { echo "no such script: $file" >&2; exit 2; }
    shift 2
    wp eval-file "$file" "$@"
    ;;
  limits-on)
    json=${2:?usage: rehearse.sh limits-on '{"order":2}'}
    dir=$(mu_dir) || exit 1
    dest="$dir/$MU_FILE_NAME"
    mkdir -p "$dir"
    # 自分が置いたファイル以外は上書きしない（他セッション由来の mu-plugin を壊さない）。
    if [ -e "$dest" ] && ! grep -q "$MARKER" "$dest"; then
      echo "refusing to overwrite $dest (not managed by this skill)" >&2; exit 1
    fi
    # 値の検証（オブジェクトで、値が整数か null だけ）は PHP 側で行い、通らなければ保存しない。
    wp eval-file "$SKILL_REL/php/limits.php" "set=$json"
    cp "$TEMPLATE" "$dest"
    echo "installed $dest"
    ;;
  limits-off)
    wp eval-file "$SKILL_REL/php/limits.php" "clear=1"
    dir=$(mu_dir) || exit 1
    dest="$dir/$MU_FILE_NAME"
    if [ -e "$dest" ]; then
      if grep -q "$MARKER" "$dest"; then rm -f "$dest"; echo "removed $dest"; else echo "not managed by this skill; left in place: $dest" >&2; exit 1; fi
    else
      echo "nothing to remove ($dest does not exist)"
    fi
    ;;
  *)
    sed -n '2,7p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 2
    ;;
esac
