#!/usr/bin/env bash
# ゲート 1 ターンの「開始側」を 1 コマンドにする: CI green を待つ → ボットへ依頼 → 応答を待つ →
# 新規の指摘（スレッド・レビュー本文・Codex のコメント）を続けて表示する。
# 使い方: gate-turn.sh <PR番号> <codex|copilot> [--first] [--timeout=秒] [--no-ci-wait]
#   --first        最初の Codex ターン用（Codex 専用）。PR 作成時の自動レビューを待つので bot-request.sh は
#                  呼ばず、bot-wait.sh に --codex-nudge=300 を付ける。T は起動時刻（CI 待ちの間に自動レビューが
#                  届いても取りこぼさないよう、CI 待ちより前に取る）。G2 以降で付けると二重依頼になる。
#   --timeout=秒   応答待ちの上限（既定 900）。
#   --no-ci-wait   CI 待ちを省く（直前に CI green を確認済みのときだけ）。
# 標準出力に `T=<依頼時刻>` を出す。以降の gate-threads.sh / gate-bodies.sh / gate-reply.sh の T にはこれを使う。
# Bash の run_in_background で使う想定（CI 待ち＋応答待ちで最長 25 分ほどかかる）。
#
# 終了コード: 0=応答あり / 1=応答待ちがタイムアウト（取得できる指摘は表示する）
#            2=引数不正、または CI が green でない（依頼していない）/ 3=API エラー・依頼失敗
#
# 兄弟スクリプトを呼ぶだけの薄いオーケストレーター（ロジックを重複させない）。修正・コミット・返信・Resolve・
# サマリコメントはターンごとに判断を含むため、このスクリプトの対象外。
# テスト用に CBJ_GATE_SCRIPTS_DIR で兄弟スクリプトのディレクトリを差し替えられる（test-gate-turn.sh 参照）。
set -uo pipefail

usage() {
  echo "usage: gate-turn.sh <pr> <codex|copilot> [--first] [--timeout=秒] [--no-ci-wait]" >&2
  exit 2
}

PR=${1:-}
BOT=${2:-}
[[ "$PR" =~ ^[0-9]+$ ]] || usage
case "$BOT" in
  codex | copilot) ;;
  *) usage ;;
esac

FIRST=0
TIMEOUT=900
CI_WAIT=1
for arg in "${@:3}"; do
  case "$arg" in
    --first) FIRST=1 ;;
    --timeout=*) TIMEOUT=${arg#*=} ;;
    --no-ci-wait) CI_WAIT=0 ;;
    *) echo "unknown option: $arg" >&2; usage ;;
  esac
done
[[ "$TIMEOUT" =~ ^[0-9]+$ ]] || { echo "--timeout must be a number of seconds (got: '$TIMEOUT')" >&2; exit 2; }
if [ "$FIRST" -eq 1 ] && [ "$BOT" != "codex" ]; then
  echo "--first is for the first Codex turn only" >&2
  exit 2
fi

DIR=${CBJ_GATE_SCRIPTS_DIR:-$(cd "$(dirname "$0")" && pwd)}
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

T=""
if [ "$FIRST" -eq 1 ]; then
  T=$(date -u +%Y-%m-%dT%H:%M:%SZ)
fi

# 1. CI green を待つ（未確認の HEAD にボットへ依頼しない）。ci-wait の出力は長いので失敗時に末尾だけ出す。
if [ "$CI_WAIT" -eq 1 ]; then
  if "$DIR/ci-wait.sh" "$PR" >"$TMP/ci.out" 2>&1; then
    echo "ci: green"
  else
    rc=$?
    tail -n 12 "$TMP/ci.out" >&2
    if [ "$rc" -eq 3 ]; then
      echo "ci: could not be determined (ci-wait exit 3); not requesting a review" >&2
      exit 3
    fi
    echo "ci: NOT green (ci-wait exit $rc); not requesting a review" >&2
    exit 2
  fi
fi

# 2. 依頼（--first は自動レビューを待つので依頼しない）。標準出力の依頼時刻を T とする。
if [ "$FIRST" -eq 0 ]; then
  if T=$("$DIR/bot-request.sh" "$PR" "$BOT" 2>"$TMP/req.err"); then
    :
  else
    rc=$?
    cat "$TMP/req.err" >&2
    echo "request to $BOT failed (bot-request.sh exit $rc)" >&2
    exit 3
  fi
fi
if ! [[ "$T" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]; then
  echo "unexpected request time: '$T'" >&2
  exit 3
fi
echo "T=$T"

# 3. 応答を待つ。タイムアウト（1）は指摘の取得まで続ける。それ以外の非ゼロ（API エラー等）は 3。
WAIT_ARGS=("--timeout=$TIMEOUT")
if [ "$BOT" = "copilot" ]; then
  WAIT_ARGS+=(--copilot=1 --codex=0)
else
  WAIT_ARGS+=(--copilot=0 --codex=1)
  if [ "$FIRST" -eq 1 ]; then WAIT_ARGS+=(--codex-nudge=300); fi
fi
wait_rc=0
"$DIR/bot-wait.sh" "$PR" "$T" "${WAIT_ARGS[@]}" >"$TMP/wait.out" 2>&1 || wait_rc=$?
tail -n 4 "$TMP/wait.out"
if [ "$wait_rc" -ne 0 ] && [ "$wait_rc" -ne 1 ]; then
  echo "wait: failed (bot-wait exit $wait_rc)" >&2
  exit 3
fi

# 4. 指摘の取得。系統 A（スレッド）と系統 B（本文）は必ず両方出す。どちらかが失敗したら成功扱いにしない。
echo
echo "=== threads (unresolved, after T) ==="
if ! "$DIR/gate-threads.sh" "$PR" "$T"; then
  echo "gate-threads.sh failed" >&2
  exit 3
fi
echo
echo "=== review bodies (after T) ==="
if ! "$DIR/gate-bodies.sh" "$PR" "$T"; then
  echo "gate-bodies.sh failed" >&2
  exit 3
fi

# 5. Codex は指摘なしを issue コメント（"Didn't find any major issues" + Reviewed commit）で返す。
if [ "$BOT" = "codex" ]; then
  echo
  echo "=== codex issue comments (after T) ==="
  if out=$(gh api --paginate "repos/{owner}/{repo}/issues/$PR/comments?since=$T" \
    --jq ".[] | select(.user.login == \"chatgpt-codex-connector[bot]\" and .created_at > \"$T\") | \"\\(.created_at)\\n\\(.body[0:600])\\n---\""); then
    if [ -n "$out" ]; then printf '%s\n' "$out"; else echo "(none)"; fi
  else
    echo "gh api (issue comments) failed" >&2
    exit 3
  fi
fi

echo
if [ "$wait_rc" -eq 0 ]; then
  echo "gate-turn: PR #$PR $BOT — T=$T — responded"
else
  echo "gate-turn: PR #$PR $BOT — T=$T — TIMEOUT (no response within ${TIMEOUT}s; the findings above may be incomplete)"
fi
exit "$wait_rc"
