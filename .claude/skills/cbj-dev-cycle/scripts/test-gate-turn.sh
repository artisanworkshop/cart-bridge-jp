#!/usr/bin/env bash
# gate-turn.sh の回帰テスト（ネットワーク不要）。兄弟スクリプトと gh をスタブに差し替え、
# 呼び出しの順序・引数・終了コード・出力を検証する。
# 使い方: .claude/skills/cbj-dev-cycle/scripts/test-gate-turn.sh
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
GT="$HERE/gate-turn.sh"
FAILS=0

STUBS=$(mktemp -d)
trap 'rm -rf "$STUBS"' EXIT
LOG="$STUBS/calls.log"
mkdir -p "$STUBS/bin"

# mkstub <スクリプト名> <環境変数の接尾辞> <既定の標準出力> :
#   呼び出しを $STUB_LOG に記録し、STUB_OUT_<接尾辞>（標準出力）と STUB_RC_<接尾辞>（終了コード）で振る舞いを切り替える。
mkstub() {
  cat >"$STUBS/$1.sh" <<EOS
#!/usr/bin/env bash
echo "$1 \$*" >> "\$STUB_LOG"
printf '%s\n' "\${STUB_OUT_$2:-$3}"
exit "\${STUB_RC_$2:-0}"
EOS
  chmod +x "$STUBS/$1.sh"
}
mkstub ci-wait CI "ci stub output"
mkstub bot-request REQ "2026-09-24T10:00:00Z"
mkstub bot-wait WAIT "copilot=0 codex=1
DONE"
mkstub gate-threads THREADS "threads: 0 / unresolved bot threads: 0"
mkstub gate-bodies BODIES "no bot review bodies"

cat >"$STUBS/bin/gh" <<'EOS'
#!/usr/bin/env bash
echo "gh $*" >> "$STUB_LOG"
if [ "${STUB_RC_GH:-0}" -ne 0 ]; then exit "$STUB_RC_GH"; fi
printf '%s\n' "2026-09-24T10:03:00Z
Codex Review: Didn't find any major issues. Reviewed commit: abc123
---"
EOS
chmod +x "$STUBS/bin/gh"

# run_gt <環境変数の代入...> -- <gate-turn.sh の引数...> : 出力を $OUT に、終了コードを $RC に入れる。
run_gt() {
  local envs=()
  while [ "$1" != "--" ]; do envs+=("$1"); shift; done
  shift
  : >"$LOG"
  RC=0
  OUT=$(env CBJ_GATE_SCRIPTS_DIR="$STUBS" STUB_LOG="$LOG" PATH="$STUBS/bin:$PATH" "${envs[@]+"${envs[@]}"}" "$GT" "$@" 2>&1) || RC=$?
}

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1"; FAILS=$((FAILS + 1)); }
assert_rc() { if [ "$RC" -eq "$2" ]; then ok "$1"; else fail "$1: 終了コード ${RC}（期待 $2）"; fi; }
assert_out() { if grep -qF -- "$2" <<<"$OUT"; then ok "$1"; else fail "$1: 出力に見つからない: $2"; fi; }
assert_log() { if grep -qxF -- "$2" "$LOG"; then ok "$1"; else fail "$1: 呼び出しログに見つからない: $2"; fi; }
assert_no_call() { if grep -q -- "^$2" "$LOG"; then fail "$1: 呼んではならない: $2"; else ok "$1"; fi; }
T1="2026-09-24T10:00:00Z"

# 1. Codex ターン（通常）
run_gt -- 64 codex
assert_rc "codex: 終了コード 0" 0
assert_log "codex: CI を待つ" "ci-wait 64"
assert_log "codex: codex へ依頼する" "bot-request 64 codex"
assert_log "codex: codex だけを待つ" "bot-wait 64 $T1 --timeout=900 --copilot=0 --codex=1"
assert_log "codex: 系統 A（スレッド）を取得する" "gate-threads 64 $T1"
assert_log "codex: 系統 B（本文）を取得する" "gate-bodies 64 $T1"
assert_out "codex: T を出力する" "T=$T1"
assert_out "codex: 指摘なしの issue コメントを出す" "Didn't find any major issues"
assert_out "codex: 応答ありで終わる" "responded"
# 呼び出し順（CI → 依頼 → 待機 → 取得）
order=$(awk '{print $1}' "$LOG" | tr '\n' ' ')
if [ "$order" = "ci-wait bot-request bot-wait gate-threads gate-bodies gh " ]; then ok "codex: 呼び出し順"; else fail "codex: 呼び出し順が違う: $order"; fi

# 2. Copilot ターン
run_gt -- 64 copilot
assert_rc "copilot: 終了コード 0" 0
assert_log "copilot: copilot へ依頼する" "bot-request 64 copilot"
assert_log "copilot: copilot だけを待つ" "bot-wait 64 $T1 --timeout=900 --copilot=1 --codex=0"
assert_no_call "copilot: Codex の issue コメントは取らない" "gh "

# 3. --first（最初の Codex ターン）: 依頼せず、自動レビューを待ち、nudge を付ける
run_gt -- 64 codex --first
assert_rc "--first: 終了コード 0" 0
assert_no_call "--first: bot-request を呼ばない" "bot-request"
if grep -q -- "^bot-wait 64 20[0-9-]*T[0-9:]*Z --timeout=900 --copilot=0 --codex=1 --codex-nudge=300$" "$LOG"; then ok "--first: nudge 付きで待つ"; else fail "--first: bot-wait の引数が違う: $(grep '^bot-wait' "$LOG")"; fi

# 4. --first は Codex 専用
run_gt -- 64 copilot --first
assert_rc "--first と copilot は引数不正（2）" 2
assert_no_call "--first と copilot: 何も呼ばない" "ci-wait"

# 5. CI が green でない → 依頼しない
run_gt STUB_RC_CI=1 -- 64 codex
assert_rc "CI 失敗: 2" 2
assert_no_call "CI 失敗: 依頼しない" "bot-request"
assert_no_call "CI 失敗: 待たない" "bot-wait"
assert_out "CI 失敗: 理由を出す" "NOT green"

# 6. CI の状態を判定できない（ci-wait が 3）→ 3
run_gt STUB_RC_CI=3 -- 64 codex
assert_rc "CI 判定不能: 3" 3
assert_no_call "CI 判定不能: 依頼しない" "bot-request"

# 7. --no-ci-wait
run_gt -- 64 codex --no-ci-wait
assert_rc "--no-ci-wait: 終了コード 0" 0
assert_no_call "--no-ci-wait: CI を待たない" "ci-wait"
assert_log "--no-ci-wait: 依頼する" "bot-request 64 codex"

# 8. タイムアウト（bot-wait が 1）→ 指摘の取得まで続け、終了コード 1
run_gt STUB_RC_WAIT=1 STUB_OUT_WAIT="TIMEOUT" -- 64 copilot
assert_rc "タイムアウト: 1" 1
assert_log "タイムアウト: それでもスレッドを取得する" "gate-threads 64 $T1"
assert_log "タイムアウト: それでも本文を取得する" "gate-bodies 64 $T1"
assert_out "タイムアウト: 不完全かもしれないと表示する" "TIMEOUT"

# 9. bot-wait が API エラー（3）→ 取得へ進まず 3
run_gt STUB_RC_WAIT=3 STUB_OUT_WAIT="API_ERROR" -- 64 codex
assert_rc "待機の API エラー: 3" 3
assert_no_call "待機の API エラー: 取得へ進まない" "gate-threads"

# 10. 指摘の取得が失敗したら成功扱いにしない
run_gt STUB_RC_THREADS=1 -- 64 codex
assert_rc "gate-threads 失敗: 3" 3
run_gt STUB_RC_BODIES=1 -- 64 codex
assert_rc "gate-bodies 失敗: 3" 3
run_gt STUB_RC_GH=1 -- 64 codex
assert_rc "Codex コメント取得の失敗: 3" 3

# 11. 依頼の失敗（bot-request が非ゼロ）→ 3、待たない
run_gt STUB_RC_REQ=1 STUB_OUT_REQ="" -- 64 codex
assert_rc "依頼失敗: 3" 3
assert_no_call "依頼失敗: 待たない" "bot-wait"

# 12. 依頼時刻の形式が不正 → 3
run_gt STUB_OUT_REQ="not-a-time" -- 64 codex
assert_rc "依頼時刻の形式不正: 3" 3

# 13. --timeout の引き渡し
run_gt -- 64 copilot --timeout=30
assert_log "--timeout を bot-wait へ渡す" "bot-wait 64 $T1 --timeout=30 --copilot=1 --codex=0"

# 14. 引数不正
run_gt -- 64
assert_rc "bot 省略: 2" 2
run_gt -- abc codex
assert_rc "PR が数字でない: 2" 2
run_gt -- 64 gemini
assert_rc "未知の bot: 2" 2
run_gt -- 64 codex --bogus
assert_rc "未知のオプション: 2" 2
run_gt -- 64 codex --timeout=abc
assert_rc "--timeout が数字でない: 2" 2

if [ "$FAILS" -ne 0 ]; then
  echo "test-gate-turn: $FAILS 件失敗"
  exit 1
fi
echo "test-gate-turn: all ok"
