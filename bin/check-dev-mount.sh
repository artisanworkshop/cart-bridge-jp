#!/usr/bin/env bash
# wp-env がリポジトリのルートをマウントした wp-content/cbjp-dev が HTTP で読めないことを確かめる（D29・R3-7）。
#
# ルートには gitignore 済みの資格情報（colorme.env）とリハーサルの出力（会員・受注を含む）があり、wp-env は 0.0.0.0 で待ち受ける。
# ルートの .htaccess（Require all denied）が効いていれば 403 になる。dev と tests の両方のサイトで、追跡済みのファイルを取りに行き、
# 403 以外（200・404・接続できない）はすべて失敗にする（確かめられないことを「守られている」と読まない）。
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

[ -f .htaccess ] || { echo "check-dev-mount: .htaccess is missing at the repository root" >&2; exit 1; }

port_of() {
	local key="$1" value
	if ! value=$(grep -m1 "\"${key}\"" .wp-env.json | grep -o '[0-9][0-9]*'); then
		echo "check-dev-mount: could not read ${key} from .wp-env.json" >&2
		return 1
	fi
	printf '%s\n' "$value"
}

rc=0
for key in port testsPort; do
	port=$(port_of "$key") || exit 1
	url="http://localhost:${port}/wp-content/cbjp-dev/composer.json"
	if code=$(curl -s -o /dev/null -w '%{http_code}' "$url"); then :; else code="curl-failed"; fi
	if [ "$code" = "403" ]; then
		echo "check-dev-mount: ${url} -> 403 (not served)"
	else
		echo "check-dev-mount: ${url} answered '${code}', expected 403. The repository root must not be served over HTTP." >&2
		rc=1
	fi
done
exit "$rc"
