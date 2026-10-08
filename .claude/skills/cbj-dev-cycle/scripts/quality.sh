#!/usr/bin/env bash
# このリポジトリの品質チェック一式。wp-env のポートは .wp-env.json で固定している
# （dev 10010 / tests 10011。dev-env スキルの台帳のスロット 01）。
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
composer lint
composer analyze
composer test:wpenv
npm run lint
npm run test:js
# ビルドしてから、ソースの文字列とコミット済みの POT（languages/）が同じか確かめる（R3-2。CI の PHPUnit ジョブと同じ）
npm run i18n:check
# 開発補助スクリプト（bot レビュー本文の整形・ゲート 1 ターンのオーケストレーター・ラウンド記録の生成）の回帰テスト。ネットワーク不要
.claude/skills/cbj-dev-cycle/scripts/test-gate-bodies.sh
.claude/skills/cbj-dev-cycle/scripts/test-gate-turn.sh
.claude/skills/cbj-dev-cycle/scripts/test-gate-record.sh
echo "quality: all green"
