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
# 開発補助スクリプト（bot レビュー本文の整形・ゲート 1 ターンのオーケストレーター・ラウンド記録の生成）の回帰テスト。ネットワーク不要
.claude/skills/cbj-dev-cycle/scripts/test-gate-bodies.sh
.claude/skills/cbj-dev-cycle/scripts/test-gate-turn.sh
.claude/skills/cbj-dev-cycle/scripts/test-gate-record.sh
echo "quality: all green"
