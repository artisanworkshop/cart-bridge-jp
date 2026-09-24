#!/usr/bin/env bash
# ゲート 1 ターンの「開始側」を 1 コマンドにする: CI green を待つ → ボットへ依頼 → 応答を待つ →
# 新規の指摘（スレッド・レビュー本文・Codex のコメント）を続けて表示する。
# 使い方: gate-turn.sh <PR番号> <codex|copilot> [--first] [--timeout=秒] [--ci-timeout=秒] [--no-ci-wait]
#   --first        最初の Codex ターン用（Codex 専用）。PR 作成時の自動レビューを待つので bot-request.sh は
#                  呼ばず、bot-wait.sh に --codex-nudge=300 を付ける。T は起動時刻（CI 待ちの間に自動レビューが
#                  届いても取りこぼさないよう、CI 待ちより前に取る）。G2 以降で付けると二重依頼になる。
#                  bot-wait.sh は提出時刻だけで応答を判定するため、CI 待ちの間に PR の HEAD が動くと旧 HEAD への
#                  自動レビューが待ちを満たしてしまい、CI で green を確認した新 HEAD が未レビューのまま「応答あり」になる。
#                  そこで CI 待ちの前後で PR の HEAD を比べ、動いていたら待たずに終了する（2）。`--first` を外して
#                  再実行すれば新 HEAD に `@codex review` を依頼できる（届いていた自動レビューは依頼 1 回目として数える）。
#                  レビューの commit_id は照合していないため、PR 作成後に別のコミットを push してから起動した場合（起動時点で
#                  すでに HEAD が動いている場合）は旧 HEAD への自動レビューと区別できない。その場合は `--first` を使わず
#                  通常ターン（bot-request.sh 経由の依頼）にする。
#   --timeout=秒   応答待ち（bot-wait.sh）の上限（既定 900）。
#   --ci-timeout=秒 CI 待ち（ci-wait.sh）の上限（既定 600）。
#   --no-ci-wait   CI 待ちを省く（直前に CI green を確認済みのときだけ）。
# 標準出力に `T=<依頼時刻>` を出す。以降の gate-threads.sh / gate-bodies.sh の T にはこれを使う。
# gate-reply.sh に渡すのは T ではなく、gate-threads.sh が返すコメントの dbid。
# Bash の run_in_background で使う想定。CI 待ちと応答待ちは**直列**なので、最悪の所要時間は
# --ci-timeout + --timeout（既定で 600 + 900 秒）。呼び出し側の外側のタイムアウト（Bash ツールの timeout）は
# **(--ci-timeout + --timeout + 60) × 1000 ミリ秒以上**にすること（既定なら 1560000）。短いと、遅い CI や遅れて届いた
# レビューの途中でプロセスごと打ち切られ、指摘の取得もタイムアウトの報告もされない。起動時に必要な値を出力する。
# ツールの上限に収まらないときは、CI 待ちを別に実行して --no-ci-wait を使うか、--timeout / --ci-timeout を短くする。
#
# 終了コード: 0=応答あり / 1=応答待ちがタイムアウト（取得できる指摘は表示する）
#            2=引数不正 / CI が green でない / --first の HEAD が CI 待ちの間に動いた（いずれも依頼も応答待ちもしていない）
#            3=API エラー・依頼失敗
#
# 兄弟スクリプトを呼ぶだけの薄いオーケストレーター（ロジックを重複させない）。修正・コミット・返信・Resolve・
# サマリコメントはターンごとに判断を含むため、このスクリプトの対象外。
# テスト用に CBJ_GATE_SCRIPTS_DIR で兄弟スクリプトのディレクトリを差し替えられる（test-gate-turn.sh 参照）。
set -uo pipefail

usage() {
  echo "usage: gate-turn.sh <pr> <codex|copilot> [--first] [--timeout=秒] [--ci-timeout=秒] [--no-ci-wait]" >&2
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
CI_TIMEOUT=600
CI_WAIT=1
for arg in "${@:3}"; do
  case "$arg" in
    --first) FIRST=1 ;;
    --timeout=*) TIMEOUT=${arg#*=} ;;
    --ci-timeout=*) CI_TIMEOUT=${arg#*=} ;;
    --no-ci-wait) CI_WAIT=0 ;;
    *) echo "unknown option: $arg" >&2; usage ;;
  esac
done
[[ "$TIMEOUT" =~ ^[0-9]+$ ]] || { echo "--timeout must be a number of seconds (got: '$TIMEOUT')" >&2; exit 2; }
[[ "$CI_TIMEOUT" =~ ^[0-9]+$ ]] || { echo "--ci-timeout must be a number of seconds (got: '$CI_TIMEOUT')" >&2; exit 2; }
# 先頭が 0 の値は、後続の `$(( ))`（と子スクリプトの算術）で 8 進数として解釈される（0600 は 384、0900 はエラー）。
# 検証を通った後に基数 10 へ正規化してから計算・子スクリプトへ渡す。
TIMEOUT=$((10#$TIMEOUT))
CI_TIMEOUT=$((10#$CI_TIMEOUT))
if [ "$FIRST" -eq 1 ] && [ "$BOT" != "codex" ]; then
  echo "--first is for the first Codex turn only" >&2
  exit 2
fi

DIR=${CBJ_GATE_SCRIPTS_DIR:-$(cd "$(dirname "$0")" && pwd)}
# mktemp が失敗したまま進むと TMP が空になり、`$TMP/ci.out` が `/ci.out` になってしまう。
if ! TMP=$(mktemp -d) || [ -z "$TMP" ]; then
  echo "could not create a temporary directory (mktemp failed)" >&2
  exit 3
fi
trap 'rm -rf "$TMP"' EXIT

# 必要な外側のタイムアウトを起動時に示す（CI 待ちと応答待ちは直列）。
if [ "$CI_WAIT" -eq 1 ]; then
  budget=$((CI_TIMEOUT + TIMEOUT + 60))
  echo "budget: ci<=${CI_TIMEOUT}s + wait<=${TIMEOUT}s + 60s overhead => run with an outer timeout of at least $((budget * 1000)) ms"
else
  budget=$((TIMEOUT + 60))
  echo "budget: wait<=${TIMEOUT}s + 60s overhead => run with an outer timeout of at least $((budget * 1000)) ms"
fi

pr_head() { gh pr view "$PR" --json headRefOid --jq .headRefOid; }

T=""
HEAD0=""
if [ "$FIRST" -eq 1 ]; then
  # HEAD0 は T より先に読む。逆順だと、T と HEAD0 の間に入った push が CI 待ち後の比較で検出できない。
  if [ "$CI_WAIT" -eq 1 ]; then
    if ! HEAD0=$(pr_head) || [ -z "$HEAD0" ]; then
      echo "could not resolve the head of PR #$PR" >&2
      exit 3
    fi
  fi
  T=$(date -u +%Y-%m-%dT%H:%M:%SZ)
fi

# 1. CI green を待つ（未確認の HEAD にボットへ依頼しない）。ci-wait の出力は長いので失敗時に末尾だけ出す。
if [ "$CI_WAIT" -eq 1 ]; then
  if "$DIR/ci-wait.sh" "$PR" "--timeout=$CI_TIMEOUT" >"$TMP/ci.out" 2>&1; then
    echo "ci: green"
    # --first の T は CI 待ちより前なので、待つ間に HEAD が動くと旧 HEAD への自動レビューが応答として数えられてしまう。
    if [ "$FIRST" -eq 1 ]; then
      if ! HEAD1=$(pr_head) || [ -z "$HEAD1" ]; then
        echo "could not resolve the head of PR #$PR after the CI wait" >&2
        exit 3
      fi
      if [ "$HEAD1" != "$HEAD0" ]; then
        echo "the PR head moved during the CI wait (${HEAD0:0:7} -> ${HEAD1:0:7}); an automatic Codex review would be for the old head, not the commit CI just validated. Not waiting; re-run without --first to request a review of the new head (count the automatic review as request #1 if it arrived)" >&2
        exit 2
      fi
    fi
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
# 連続する同一行（毎回の `copilot=0 codex=0` の進捗）だけを畳み、nudge 投稿などの記録は残す。
# `tail` で末尾だけにすると、nudge の後に 2 回以上ポーリングした場合に「@codex review を投稿した
# （Codex への依頼 1 回目として数える）」の行が落ちて、依頼回数（上限 3 回）を数え違える。
uniq "$TMP/wait.out"
NUDGED=0
if grep -q "posted '@codex review'" "$TMP/wait.out"; then NUDGED=1; fi
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
NOTE=""
if [ "$NUDGED" -eq 1 ]; then NOTE=" — '@codex review' was posted by the nudge (counts as Codex request #1)"; fi
if [ "$wait_rc" -eq 0 ]; then
  echo "gate-turn: PR #$PR $BOT — T=$T — responded${NOTE}"
else
  echo "gate-turn: PR #$PR $BOT — T=$T — TIMEOUT (no response within ${TIMEOUT}s; the findings above may be incomplete)${NOTE}"
fi
exit "$wait_rc"
