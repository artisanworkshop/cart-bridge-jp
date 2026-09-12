#!/usr/bin/env bash
# このリポジトリの品質チェック一式。wp-env のポートは .wp-env.override.json で固定する
# （8888 が別プロジェクトに使われている環境向け。無ければ既定の 8888/8889）。
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
composer lint
composer analyze
composer test:wpenv
npm run lint
npm run build
echo "quality: all green"
