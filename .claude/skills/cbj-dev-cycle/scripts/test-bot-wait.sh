#!/usr/bin/env bash
# bot-wait.sh の応答の判定の回帰テスト（ネットワーク不要）。gh をスタブに差し替え、--timeout=0 で 1 回だけ問い合わせて
# DONE（exit 0）か TIMEOUT（exit 1）かを確かめる。
# 使い方: .claude/skills/cbj-dev-cycle/scripts/test-bot-wait.sh
#
# fixtures/bot-wait/ の出所（公開の PR のコメント・リアクション）:
#   summary-completed.json  PR #127 の「Codex Review Summary」（Completed・commit 866cf15）
#   didnt-find.json         PR #125 の「Codex Review: Didn't find any major issues」（Reviewed commit 0a05bef729）
#   thumbs.json             PR #125 の Codex の 👍（2026-10-11T22:52:29Z）
#   summary-running.json    合成: summary-completed.json の状態の行を、PR #127 の G1 で表示された Running の行（commit e3523bc）に置き換えた
#   summary-unknown.json    合成: 状態を読めない語（Paused）に置き換えた
set -euo pipefail
if ! HERE=$(cd "$(dirname "$0")" && pwd) || [ -z "$HERE" ]; then
  echo "test-bot-wait: could not resolve the script directory" >&2
  exit 1
fi
BW="$HERE/bot-wait.sh"
FX="$HERE/fixtures/bot-wait"
FAILS=0
if ! WORK=$(mktemp -d) || [ -z "$WORK" ]; then
  echo "test-bot-wait: could not create a temporary directory (mktemp failed)" >&2
  exit 1
fi
# `set -e` と EXIT トラップの両方があると、macOS の bash 3.2 は異常終了でも 0 を返しうる。最後まで走ったことを DONE で確かめる
# （.claude/rules/skill-scripts.md。test-gate-record.sh と同じ）。
DONE=0
trap 'rm -rf "$WORK"; if [ "$DONE" != 1 ]; then echo "test-bot-wait: stopped before the end (an unexpected error aborted the script)" >&2; exit 1; fi' EXIT
mkdir -p "$WORK/bin"
EMPTY="$WORK/empty.json"
echo '[]' >"$EMPTY"

# gh: `pr view` は STUB_HEAD（PR の HEAD）を返す。`api` はエンドポイントに応じて STUB_REVIEWS / STUB_COMMENTS / STUB_REACTIONS の
# JSON に --jq の式を当てて返す（issue コメントは URL の since で updated_at を絞る。GitHub の API と同じ）。`pr comment` は記録だけ。
cat >"$WORK/bin/gh" <<'EOS'
#!/usr/bin/env bash
echo "gh $*" >> "$STUB_LOG"
if [ "${1:-}" = pr ] && [ "${2:-}" = view ]; then
  if [ "${STUB_RC_PRVIEW:-0}" -ne 0 ]; then exit "$STUB_RC_PRVIEW"; fi
  printf '%s\n' "$STUB_HEAD"
  exit 0
fi
if [ "${1:-}" = pr ] && [ "${2:-}" = comment ]; then exit 0; fi
[ "${1:-}" = api ] || exit 1
url=""; filter="."
while [ $# -gt 0 ]; do
  case "$1" in
    --jq) filter=$2; shift 2 ;;
    --paginate|api) shift ;;
    *) url=$1; shift ;;
  esac
done
case "$url" in
  */reviews) jq -r "$filter" "${STUB_REVIEWS:-$STUB_EMPTY}" ;;
  */comments\?since=*)
    since=${url#*since=}
    jq --arg s "$since" '[.[] | select(.updated_at >= $s)]' "${STUB_COMMENTS:-$STUB_EMPTY}" | jq -r "$filter"
    ;;
  */reactions) jq -r "$filter" "${STUB_REACTIONS:-$STUB_EMPTY}" ;;
  *) exit 1 ;;
esac
EOS
chmod +x "$WORK/bin/gh"

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1"; FAILS=$((FAILS + 1)); }

# run_bw <環境変数の代入...> -- <bot-wait.sh の引数...> : 標準出力と標準エラーを $OUT に、終了コードを $RC に入れる。
run_bw() {
  local envs=()
  while [ "$1" != "--" ]; do envs+=("$1"); shift; done
  shift
  : >"$WORK/calls.log"
  RC=0
  OUT=$(env PATH="$WORK/bin:$PATH" STUB_LOG="$WORK/calls.log" STUB_EMPTY="$EMPTY" "${envs[@]+"${envs[@]}"}" "$BW" "$@" 2>&1) || RC=$?
}
expect_rc() { # <label> <expected rc>
  if [ "$RC" = "$2" ]; then ok "$1"; else fail "$1: exit $RC (expected $2): $(printf '%s' "$OUT" | tr '\n' ' ')"; fi
}
expect_out() { # <label> <fixed string>
  if grep -qF -- "$2" <<<"$OUT"; then ok "$1"; else fail "$1: missing '$2' in: $(printf '%s' "$OUT" | tr '\n' ' ')"; fi
}

H866="866cf15$(printf '0%.0s' $(seq 1 33))"
HE35="e3523bc$(printf '0%.0s' $(seq 1 33))"
H9E9="9e9f645$(printf '0%.0s' $(seq 1 33))"
CODEX_ONLY=(--copilot=0 --codex=1 --timeout=0)

# 1. まとめのコメントが Running のうちは応答と数えない（PR #127 G2・G3 では Running で DONE になっていた）。
run_bw STUB_HEAD="$HE35" STUB_COMMENTS="$FX/summary-running.json" -- 127 2026-10-12T02:20:00Z "${CODEX_ONLY[@]}"
expect_rc "running: not a response (TIMEOUT)" 1
expect_out "running: says why it waits" "codex: review running for e3523bc"
expect_out "running: codex=0" "copilot=0 codex=0"

# 2. Completed で、commit が PR の HEAD なら応答。
run_bw STUB_HEAD="$H866" STUB_COMMENTS="$FX/summary-completed.json" -- 127 2026-10-12T03:33:11Z "${CODEX_ONLY[@]}"
expect_rc "completed for the head: DONE" 0
expect_out "completed for the head: codex=1" "copilot=0 codex=1"

# 3. Completed でも、前の commit なら応答と数えない（新しい HEAD のレビューはまだ）。
run_bw STUB_HEAD="$H9E9" STUB_COMMENTS="$FX/summary-completed.json" -- 127 2026-10-12T03:33:11Z "${CODEX_ONLY[@]}"
expect_rc "completed for an older commit: TIMEOUT" 1
expect_out "completed for an older commit: says so" "the summary is Completed for 866cf15, not the PR head 9e9f645"

# 4. 状態を読めないまとめのコメントは数えない（知らない形で DONE にしない）。
run_bw STUB_HEAD="$H866" STUB_COMMENTS="$FX/summary-unknown.json" -- 127 2026-10-12T03:33:11Z "${CODEX_ONLY[@]}"
expect_rc "unrecognized summary status: TIMEOUT" 1
expect_out "unrecognized summary status: says so" "unrecognized status"

# 5. まとめのコメントが T より前に更新されたきりなら、問い合わせ（since）に出てこない。
run_bw STUB_HEAD="$H866" STUB_COMMENTS="$FX/summary-completed.json" -- 127 2026-10-12T04:00:00Z "${CODEX_ONLY[@]}"
expect_rc "summary updated before T: TIMEOUT" 1

# 6. 指摘が無いときの「Didn't find any major issues」は応答。
run_bw STUB_HEAD="$H866" STUB_COMMENTS="$FX/didnt-find.json" -- 125 2026-10-11T22:50:00Z "${CODEX_ONLY[@]}"
expect_rc "didn't find any major issues: DONE" 0

# 7. PR への 👍 は、T より後なら応答、T より前なら数えない。
run_bw STUB_HEAD="$H866" STUB_REACTIONS="$FX/thumbs.json" -- 125 2026-10-11T22:50:00Z "${CODEX_ONLY[@]}"
expect_rc "thumbs up after T: DONE" 0
run_bw STUB_HEAD="$H866" STUB_REACTIONS="$FX/thumbs.json" -- 125 2026-10-11T23:00:00Z "${CODEX_ONLY[@]}"
expect_rc "thumbs up before T: TIMEOUT" 1

# 8. Codex のレビュー（指摘あり）は今までどおり応答。
echo '[{"user":{"login":"chatgpt-codex-connector[bot]"},"submitted_at":"2026-10-12T03:35:51Z"}]' >"$WORK/codex-review.json"
run_bw STUB_HEAD="$H866" STUB_REVIEWS="$WORK/codex-review.json" -- 127 2026-10-12T03:33:11Z "${CODEX_ONLY[@]}"
expect_rc "codex review: DONE" 0

# 9. Copilot だけを待つときは、Codex のコメント・👍・PR の HEAD を問い合わせない。
echo '[{"user":{"login":"copilot-pull-request-reviewer[bot]"},"submitted_at":"2026-10-12T03:39:02Z"}]' >"$WORK/copilot-review.json"
run_bw STUB_HEAD="$H866" STUB_REVIEWS="$WORK/copilot-review.json" STUB_COMMENTS="$FX/summary-running.json" -- 127 2026-10-12T03:33:11Z --copilot=1 --codex=0 --timeout=0
expect_rc "copilot only: DONE" 0
if grep -qE "pr view|/comments|/reactions" "$WORK/calls.log"; then fail "copilot only: queried Codex data: $(tr '\n' ' ' <"$WORK/calls.log")"; else ok "copilot only: no Codex queries"; fi

# 10. PR の HEAD を読めないときは、応答なしとして扱う（DONE にしない）。
run_bw STUB_HEAD="not-a-sha" STUB_COMMENTS="$FX/didnt-find.json" -- 125 2026-10-11T22:50:00Z "${CODEX_ONLY[@]}"
expect_rc "unreadable PR head: not DONE" 1
expect_out "unreadable PR head: says so" "unexpected PR head"

# 11. Codex の応答が無いまま両方を待つと TIMEOUT（Copilot の応答だけでは DONE にしない）。
run_bw STUB_HEAD="$H866" STUB_REVIEWS="$WORK/copilot-review.json" STUB_COMMENTS="$FX/summary-running.json" -- 127 2026-10-12T02:20:00Z --copilot=1 --codex=1 --timeout=0
expect_rc "both, codex still running: TIMEOUT" 1

DONE=1
if [ "$FAILS" -ne 0 ]; then
  echo "test-bot-wait: $FAILS failure(s)"
  exit 1
fi
echo "test-bot-wait: all ok"
