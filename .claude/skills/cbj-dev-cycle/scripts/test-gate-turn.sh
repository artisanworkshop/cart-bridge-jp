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
#   呼び出しを $STUB_LOG に記録し、STUB_OUT_<接尾辞>（標準出力）・STUB_RC_<接尾辞>（終了コード）・
#   STUB_SLEEP_<接尾辞>（待つ秒数）で振る舞いを切り替える。終了時刻は $STUB_LOG.<名前>.end に残す。
mkstub() {
  cat >"$STUBS/$1.sh" <<EOS
#!/usr/bin/env bash
echo "$1 \$*" >> "\$STUB_LOG"
sleep "\${STUB_SLEEP_$2:-0}"
date -u +%Y-%m-%dT%H:%M:%SZ > "\$STUB_LOG.$1.end"
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

# gh: `pr view`（--first の HEAD 固定）は 1 回目に STUB_HEAD、2 回目以降に STUB_HEAD_AFTER（未指定なら STUB_HEAD と同じ）を返す。
#   STUB_SLEEP_PRVIEW（1 回目だけ待つ秒数）・STUB_FAIL_PRVIEW_AFTER=1（2 回目以降を失敗させる）で振る舞いを切り替え、
#   1 回目の完了時刻を $STUB_LOG.prview.end に残す。それ以外の gh 呼び出しは Codex の issue コメントを返す。
cat >"$STUBS/bin/gh" <<'EOS'
#!/usr/bin/env bash
echo "gh $*" >> "$STUB_LOG"
if [ "${STUB_RC_GH:-0}" -ne 0 ]; then exit "$STUB_RC_GH"; fi
if [ "$1" = "pr" ] && [ "$2" = "view" ]; then
  n=$(cat "$STUB_LOG.prview.n" 2>/dev/null || echo 0)
  n=$((n + 1))
  echo "$n" > "$STUB_LOG.prview.n"
  if [ "$n" -eq 1 ]; then
    sleep "${STUB_SLEEP_PRVIEW:-0}"
    date -u +%Y-%m-%dT%H:%M:%SZ > "$STUB_LOG.prview.end"
  elif [ "${STUB_FAIL_PRVIEW_AFTER:-0}" = 1 ]; then
    exit 1
  fi
  if [ "$n" -eq 1 ]; then
    printf '%s\n' "${STUB_HEAD-1111111111111111111111111111111111111111}"
  else
    printf '%s\n' "${STUB_HEAD_AFTER-${STUB_HEAD-1111111111111111111111111111111111111111}}"
  fi
  exit 0
fi
printf '%s\n' "2026-09-24T10:03:00Z
Codex Review: Didn't find any major issues. Reviewed commit: abc123
---"
EOS
chmod +x "$STUBS/bin/gh"

# mktemp: STUB_MKTEMP_FAIL=1 のときだけ失敗させる（macOS の mktemp -d は TMPDIR を見ないので、環境変数では再現できない）。
cat >"$STUBS/bin/mktemp" <<'EOS'
#!/usr/bin/env bash
if [ "${STUB_MKTEMP_FAIL:-0}" = 1 ]; then echo "mktemp: stubbed failure" >&2; exit 1; fi
exec /usr/bin/env -i PATH=/usr/bin:/bin mktemp "$@"
EOS
chmod +x "$STUBS/bin/mktemp"

# run_gt <環境変数の代入...> -- <gate-turn.sh の引数...> : 出力を $OUT に、終了コードを $RC に入れる。
run_gt() {
  local envs=()
  while [ "$1" != "--" ]; do envs+=("$1"); shift; done
  shift
  : >"$LOG"
  rm -f "$LOG.prview.n" "$LOG.prview.end"
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
assert_log "codex: CI を待つ（上限つき）" "ci-wait 64 --timeout=600"
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

# 14. CI 待ちの上限と、外側のタイムアウトの見積り（CI 待ちと応答待ちは直列）
run_gt -- 64 codex --ci-timeout=30
assert_log "--ci-timeout を ci-wait へ渡す" "ci-wait 64 --timeout=30"
run_gt -- 64 codex
assert_out "既定の外側タイムアウトの見積りを出す（600+900+60 秒）" "outer timeout of at least 1560000 ms"
run_gt -- 64 codex --no-ci-wait
assert_out "--no-ci-wait の見積りは応答待ちだけ（900+60 秒）" "outer timeout of at least 960000 ms"
run_gt -- 64 copilot --timeout=30 --ci-timeout=20
assert_out "オプションを反映した見積り（20+30+60 秒）" "outer timeout of at least 110000 ms"

# 14b. 先頭が 0 の秒数は 8 進数ではなく 10 進数として扱う（0600 は 384、0900 は算術エラーになる）
run_gt -- 64 codex --timeout=0900 --ci-timeout=0600
assert_rc "先頭 0 の秒数: 終了コード 0（0900 で算術エラーにならない）" 0
assert_log "--ci-timeout=0600 は 600 として ci-wait へ渡す" "ci-wait 64 --timeout=600"
assert_log "--timeout=0900 は 900 として bot-wait へ渡す" "bot-wait 64 $T1 --timeout=900 --copilot=0 --codex=1"
assert_out "先頭 0 でも見積りは 10 進数（600+900+60 秒）" "outer timeout of at least 1560000 ms"
run_gt -- 64 codex --timeout=010 --ci-timeout=020
assert_log "8 進数として読める値（020 は 16）も 10 進数の 20 として渡す" "ci-wait 64 --timeout=20"
assert_out "先頭 0 の値の見積り（20+10+60 秒）" "outer timeout of at least 90000 ms"

# 15. nudge の記録を落とさない（依頼回数の上限 3 回を数え違えないため）。連続する同一の進捗行だけ畳む。
NUDGE_LINE="codex: no response after 300s; posted '@codex review' (counts as Codex request #1)"
NUDGE_WAIT=$(printf 'copilot=0 codex=0\ncopilot=0 codex=0\n%s\ncopilot=0 codex=0\ncopilot=0 codex=0\ncopilot=0 codex=0\ncopilot=0 codex=1\nDONE' "$NUDGE_LINE")
run_gt STUB_OUT_WAIT="$NUDGE_WAIT" -- 64 codex --first
assert_rc "nudge 後に複数回ポーリングしても終了コード 0" 0
assert_out "nudge の投稿記録を出力に残す" "posted '@codex review'"
assert_out "最終行に nudge が依頼 1 回目として数えられることを出す" "was posted by the nudge"
polls=$(grep -c '^copilot=0 codex=0$' <<<"$OUT" || true)
if [ "$polls" -eq 2 ]; then ok "連続する同一の進捗行は畳む（nudge を挟んだ 2 塊）"; else fail "進捗行の畳み方が違う: $polls 塊"; fi
run_gt -- 64 codex --first
if grep -qF "was posted by the nudge" <<<"$OUT"; then fail "nudge が無いのに nudge の注記が出ている"; else ok "nudge が無ければ注記を出さない"; fi

# 16. --first の順序契約: T は CI 待ちより前に取る（CI 待ちの間に届いた自動レビューを取りこぼさない）
run_gt STUB_SLEEP_CI=2 -- 64 codex --first
t_first=$(sed -n 's/^bot-wait 64 \([^ ]*\) .*/\1/p' "$LOG")
ci_end=$(cat "$LOG.ci-wait.end")
if [ "$(( ${t_first//[^0-9]/} ))" -lt "$(( ${ci_end//[^0-9]/} ))" ]; then ok "--first: T は CI 待ちの完了より前の時刻"; else fail "--first: T が CI 待ちの後になっている（T=${t_first}, CI 完了=${ci_end}）"; fi

# 17. mktemp が失敗したら、何も呼ばずに 3（TMP が空のまま /ci.out へ書かない）
run_gt STUB_MKTEMP_FAIL=1 -- 64 codex
assert_rc "mktemp 失敗: 3" 3
assert_no_call "mktemp 失敗: 何も呼ばない" "ci-wait"

# 18. --first の HEAD 固定: T は CI 待ちより前に取るので、待つ間に PR の HEAD が動くと旧 HEAD への自動レビューが
#     応答として数えられる（bot-wait は提出時刻だけで判定する）。CI 待ちの前後で HEAD を比べ、動いていたら待たない。
SHA_A=1111111111111111111111111111111111111111
SHA_B=2222222222222222222222222222222222222222
run_gt -- 64 codex --first
order=$(awk '{print $1}' "$LOG" | tr '\n' ' ')
if [ "$order" = "gh ci-wait gh bot-wait gate-threads gate-bodies gh " ]; then ok "--first: CI 待ちの前後で HEAD を読む（呼び出し順）"; else fail "--first: 呼び出し順が違う: $order"; fi
run_gt STUB_HEAD=$SHA_A STUB_HEAD_AFTER=$SHA_B -- 64 codex --first
assert_rc "--first: CI 待ちの間に HEAD が動いたら 2" 2
assert_no_call "--first: HEAD が動いたら応答を待たない" "bot-wait"
assert_no_call "--first: HEAD が動いたら依頼もしない" "bot-request"
assert_no_call "--first: HEAD が動いたら指摘も取らない" "gate-threads"
assert_out "--first: HEAD が動いたことを理由に出す（旧→新の短縮 sha）" "moved during the CI wait (1111111 -> 2222222)"
assert_out "--first: 再実行の手順を案内する" "re-run without --first"
run_gt STUB_HEAD=$SHA_A STUB_HEAD_AFTER=$SHA_A -- 64 codex --first
assert_rc "--first: HEAD が動かなければ 0" 0
run_gt STUB_HEAD=$SHA_A STUB_HEAD_AFTER=$SHA_B -- 64 codex --first --no-ci-wait
assert_rc "--first --no-ci-wait: CI 待ちが無いので HEAD の比較もしない（0）" 0
assert_no_call "--first --no-ci-wait: HEAD を読まない" "gh pr view"
run_gt STUB_HEAD=$SHA_A STUB_HEAD_AFTER=$SHA_B -- 64 codex
assert_rc "通常ターン: HEAD の比較をしない（0）" 0
assert_no_call "通常ターン: HEAD を読まない" "gh pr view"
# 判定の前提になる取得はフェイルクローズ（取得失敗・空の値を「動いていない」に化けさせない）
run_gt STUB_RC_GH=1 -- 64 codex --first
assert_rc "--first: 最初の HEAD を取得できない: 3" 3
assert_no_call "--first: 最初の HEAD を取得できなければ CI 待ちへ進まない" "ci-wait"
run_gt STUB_HEAD= -- 64 codex --first
assert_rc "--first: 最初の HEAD が空: 3" 3
assert_no_call "--first: 最初の HEAD が空なら CI 待ちへ進まない" "ci-wait"
run_gt STUB_FAIL_PRVIEW_AFTER=1 -- 64 codex --first
assert_rc "--first: CI 待ち後の HEAD を取得できない: 3" 3
assert_no_call "--first: CI 待ち後の HEAD を取得できなければ待たない" "bot-wait"
run_gt STUB_HEAD_AFTER= -- 64 codex --first
assert_rc "--first: CI 待ち後の HEAD が空: 3" 3
assert_no_call "--first: CI 待ち後の HEAD が空なら待たない" "bot-wait"
# 順序契約: HEAD は T より先に読む（逆順だと、T と HEAD の間に入った push を検出できない）
run_gt STUB_SLEEP_PRVIEW=2 -- 64 codex --first
t_first=$(sed -n 's/^bot-wait 64 \([^ ]*\) .*/\1/p' "$LOG")
head_read=$(cat "$LOG.prview.end")
if [ "$(( ${t_first//[^0-9]/} ))" -ge "$(( ${head_read//[^0-9]/} ))" ]; then ok "--first: 最初の HEAD を読み終えた後に T を取る"; else fail "--first: T が最初の HEAD の取得より前になっている（T=${t_first}, HEAD 取得完了=${head_read}）"; fi

# 19. 引数不正
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
run_gt -- 64 codex --ci-timeout=abc
assert_rc "--ci-timeout が数字でない: 2" 2

if [ "$FAILS" -ne 0 ]; then
  echo "test-gate-turn: $FAILS 件失敗"
  exit 1
fi
echo "test-gate-turn: all ok"
