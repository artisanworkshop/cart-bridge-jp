#!/usr/bin/env bash
# このリポジトリの品質チェック一式。wp-env のポートは .wp-env.json で固定している
# （dev 10010 / tests 10011。dev-env スキルの台帳のスロット 01）。
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
composer lint
composer analyze
composer test:wpenv
# wp-env がリポジトリのルートをマウントした wp-content/cbjp-dev が HTTP で読めないこと（資格情報・リハーサルの出力。D29・R3-7）
bin/check-dev-mount.sh
npm run lint
npm run test:js
# ビルドしてから、ソースの文字列とコミット済みの POT（無料版と Pro の languages/）が同じか確かめる（R3-2・R3-8d。CI の PHPUnit ジョブと同じ）
npm run i18n:check
# 開発補助スクリプト（bot レビュー本文の整形・ゲート 1 ターンのオーケストレーター・ラウンド記録の生成と投稿・bot の応答の判定）の回帰テスト。ネットワーク不要
.claude/skills/cbj-dev-cycle/scripts/test-gate-bodies.sh
.claude/skills/cbj-dev-cycle/scripts/test-gate-turn.sh
.claude/skills/cbj-dev-cycle/scripts/test-gate-record.sh
.claude/skills/cbj-dev-cycle/scripts/test-bot-wait.sh
# 配布 zip の Plugin Check の集計（bin/plugin-check-zip.sh --summarize）の回帰テスト。wp-env 不要
bin/test-plugin-check-zip.sh
# API Manager の実測の匿名化・フィクスチャとの比較（measure-api-manager。ホストの php。wp-env 不要）
php .claude/skills/measure-api-manager/php/test-anon.php
echo "quality: all green"
