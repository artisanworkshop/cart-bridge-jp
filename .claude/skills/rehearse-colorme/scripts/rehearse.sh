#!/usr/bin/env bash
# カラーミーのテストショップと wp-env の開発サイトの間で全件リハーサルを回す補助。詳細は ../SKILL.md。
# 使い方（リポジトリルートから）:
#   rehearse.sh php <name> [key=value ...]   php/<name>.php を wp eval-file で実行する（inspect / reset-local / seed-shop / snapshot / diff / check-import / run）
#   （R3-6a で無料版の上限を外したので、上限を差し替える limits-on / limits-off は削除した）
# wp-env は `npx wp-env`（グローバルの wp-env は使わない）。Node は .nvmrc の版を PATH に通しておくこと。
set -euo pipefail

SKILL_REL=".claude/skills/rehearse-colorme"

# wp-env の失敗（未起動・PHP の致命的エラー・スクリプトの ABORT）は握りつぶさず、そのまま非ゼロで返す。
# `set -e` 下で `out=$(…)` を単独の代入文にすると、失敗時に出力を表示する前に終了するので `if` で受ける。
wp() {
  local out rc
  if out=$(npx wp-env run cli --env-cwd=wp-content/cbjp-dev wp "$@" 2>&1); then rc=0; else rc=$?; fi
  printf '%s\n' "$out" | grep -v '^ℹ\|^✔\|^$' || true
  return "$rc"
}

# 出力先 .rehearsal/（リポジトリのルート。wp-env が wp-content/cbjp-dev にマウントし、Web から配信されうる場所。ルートの .htaccess も拒否する）に Apache のアクセス拒否を置き、
# 実際に HTTP で読めないことを確かめる。読めたら（または確かめられなければ）何も実行せずに止める（G3-1）。
# スナップショットは会員・受注を含み、wp-env は 0.0.0.0 で待ち受けるので同じネットワークからも届くため。
guard_output_dir() {
  local root dir port url code
  root="$(git rev-parse --show-toplevel)" || return 1
  dir="$root/.rehearsal"
  mkdir -p "$dir" || return 1
  [ -f "$dir/.htaccess" ] || printf '%s\n' '# rehearse-colorme: snapshots hold customer and order data. Never serve this directory.' 'Require all denied' > "$dir/.htaccess" || return 1
  [ -f "$dir/index.php" ] || printf '%s\n' '<?php' '// Silence is golden.' > "$dir/index.php" || return 1
  port=$(grep -m1 '"port"' "$root/.wp-env.json" | grep -o '[0-9][0-9]*') || { echo "could not read the dev port from .wp-env.json" >&2; return 1; }
  url="http://localhost:${port}/wp-content/cbjp-dev/.rehearsal/.probe.json"
  printf '{}\n' > "$dir/.probe.json" || return 1
  if code=$(curl -s -o /dev/null -w '%{http_code}' "$url"); then :; else code="curl-failed"; fi
  rm -f "$dir/.probe.json"
  case "$code" in
    403|404) return 0 ;;
    *) echo "refusing to run: $url answered '$code' (the rehearsal output must not be served over HTTP)" >&2; return 1 ;;
  esac
}

cmd=${1:-}
case "$cmd" in
  php)
    name=${2:?usage: rehearse.sh php <name> [key=value ...]}
    case "$name" in *[!a-z0-9-]*|'') echo "invalid script name: $name" >&2; exit 2 ;; esac
    file="$SKILL_REL/php/$name.php"
    [ -f "$file" ] || { echo "no such script: $file" >&2; exit 2; }
    shift 2
    guard_output_dir || exit 1
    wp eval-file "$file" "$@"
    ;;
  *)
    sed -n '2,6p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 2
    ;;
esac
