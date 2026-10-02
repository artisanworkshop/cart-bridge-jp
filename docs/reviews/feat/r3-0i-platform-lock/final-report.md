# dev-cycle 最終報告: feat/r3-0i-platform-lock

## 開発内容
- タスク: R3-0i (3)(4)。プラットフォーム単位のロックと条件付きの状態遷移を入れ、キャンセルした後も処理中のページを同時実行の判定に含める。closes #57
- PR: [#96](https://github.com/artisanworkshop/cart-bridge-jp/pull/96)
- 承認された計画（`~/.claude/plans/moonlit-leaping-marshmallow.md`）の要約:
  - `Support\PlatformLock`（新規）: core の `WP_Upgrader::create_lock()` と同じく、options に一意な INSERT IGNORE で行を作ってロックする。core と違う点は次の 4 つ。
    - 解放は、取得時の値（ハンドル）と一致するときだけ行う。
    - 期限切れのロックは CAS で回収する。
    - 期限は区間ごとに分ける。
    - shutdown 時にも解放する。
  - 判定 `JobRepository::is_platform_busy()`: 進行中のジョブに加えて、処理中の Action Scheduler アクションも数える（ユーザー決定 2026-10-03）。
  - 状態の変更は `transition()` に一本化した。期待する状態のときだけ変える。`cancel_run()` は 1 文の条件付き UPDATE にした。
  - ロックで囲む範囲: `start_run()`・`retry()` と、REST のツール 5 種（`run_exclusively()`）。
  - 409: コードは `cbjp_run_in_progress` のまま。`active_runs` が空のときだけ、待って再試行するよう促す文言にする。
  - フロントエンドは変更していない。

### コミット一覧
| sha | メッセージ |
|---|---|
| 5e2ad77 | feat: lock each platform while a run, retry or tool decides and acts (R3-0i items 3 and 4) |
| 949bcc6 | docs: record the platform lock and conditional job transitions (R3-0i items 3 and 4) |
| 9a9a80d | fix: keep a fresh long lock and never leave a half-cancelled run pending |
| 404ca18 | docs: record review-loop R1 for the platform lock |
| 7339cd0 | test: prove a refused prefecture repair neither queries the shop nor writes |
| cd92668 | docs: record review-loop R2 for the platform lock |
| 90ce2e8 | docs: update the R3-0i verification counts after review-loop |
| 09c8deb | fix: stop a prefecture repair batch at 120 seconds so it stays inside its lock |
| 6b01174 | docs: record dev-cycle gate round 1 |
| f05cbc0 | fix: size the long platform lock for the slowest locked lookup |
| 9059c5c | docs: record dev-cycle gate round 2 |
| （本コミット） | docs: record dev-cycle gate round 3 and the final report |

### 設計ドキュメントからの逸脱（`docs/03` §5「同時実行のロックと条件付きの状態遷移」に記載済み）
1. ロックを取れないときの応答は、既存のコード `cbjp_run_in_progress` のまま返す。`active_runs` が空のときだけ文言を変える。
2. TTL は区間ごとに決める。
   - `TTL_SHORT`: 60 秒。
   - `TTL_LONG`: 3,600 秒。最も遅い区間（120＋1,620 秒）から決めた（G2-1）。
   - shutdown でも解放する。期限切れのロックは CAS で回収する。期限の上限には時計のずれ 300 秒の余裕を足す（R1-1）。
3. 処理中の AS アクションも判定に加える（ユーザー決定）。AS が失敗扱いにするまでの既定 5 分間は、409 が続きうる。
4. 県コード修復は Scan（GET）もロックで囲む。修復の 1 バッチには、120 秒の時間予算を設ける（G1-1）。
5. `cancel_run` はロックを取らない。キャンセル済み run の失敗ジョブへの Retry は、従来どおり許す。
6. 範囲外として backlog に記録したもの:
   - Mappings の PUT はサーバーで拒否しない（2026-10-02 の決定のまま）。
   - `DELETE /connections`（`r3-0i-platform-lock/plan-X1`）。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | High 1・Low 6 | High 1・Low 4 | 3（R1-L1・R1-L2・R1-X1） |
| R2（独立サブエージェントで検証） | **APPROVE**／新規 Low 2 | Low 2 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（自動レビューが 5 分来なかったため、review コメントを自動投稿して依頼。「Didn't find any major issues」） |
| G1 | Copilot | 1 | 1 | 0 | 未収束 |
| G2 | Copilot | 1 | 1 | 0 | 未収束 |
| G3 | Copilot | 0 | 0 | 0 | 収束（🔵・Findings: None。依頼上限の 3 回に到達） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | High | 県コード修復の 1 バッチが、照会の再試行待ちの積み重なりでロックの期限（900 秒）を超えうる。対応として、120 秒を過ぎたら新しい行に取りかからず cursor を返す | 09c8deb | [r4169838779](https://github.com/artisanworkshop/cart-bridge-jp/pull/96#discussion_r4169838779) |
| G2-1 | Copilot | High | 受注の照会は HTTP が最大 3 本で、1 行でも期限を超えうる（push intent の link も同じ）。対応として `TTL_LONG` を 3,600 秒にし、見積もりの前提を不変条件テストで固定した | f05cbc0 | [r4170067834](https://github.com/artisanworkshop/cart-bridge-jp/pull/96#discussion_r4170067834) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし。backlog に送った review-loop の Low 3 件と計画時の 1 件は、`docs/review-backlog.md` に記録した。

## 品質ゲート
- CI: [run 37069952577](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37069952577) green（HEAD 9059c5c）
- 品質チェック（quality.sh）: green。PHPUnit 1486 件、Jest 87 件。
- ミューテーション: `mutate-check.sh` で 40 種がすべて CAUGHT。内訳は実装 33、review-loop 4、ゲート 3。
- 実機確認: wp-env の dev サイトと mock `mockv` で確認し、確認後に撤去した。
  - 2 プロセスの並走: ロックを持ったまま止まった `start_run` の間に、もう一方の開始・クリーンアップが 409 になり、run は 1 本だけ作られる。
  - キャンセル後のページ処理中: 開始・再構築は 409 になり、処理後もジョブは `cancelled` のまま。
  - 期限内のロックでは 409 になり、期限切れのロックは回収される。

## マージ前に人間が確認すべき点
- Copilot は G3 の総評で「DB ロック・非同期の書込み・キャンセルの相互作用は、指摘が残っていなくても最終的に人間の確認を」と述べた（指摘ではない）。特に次の 4 点の振る舞いを確認してほしい（docs/03 §5 に記載）。
  - キャンセルの直後、処理中のページが書き終わるまでの間は、開始・ツールが 409（「少し待って再試行」）になる。
  - ワーカーが強制終了された場合、AS が失敗扱いにする（既定 5 分）まで 409 が続く。
  - ツールのロックが強制終了（SIGKILL・OOM）で残った場合、最長 1 時間そのプラットフォームを塞ぐ。
  - 照会の遅い外部アダプタは、`TTL_LONG` の見積もりに含まれていない。
- 実 API（ColorMe）での確認はしていない。mock とモック HTTP で確認した。

## 次にできること（人間の判断）
- backlog の確認: `r3-0i-platform-lock/R1-L1`（キャンセル済みジョブの例外ログ）、`R1-L2`、`R1-X1`、`plan-X1`（`DELETE /connections` を同時実行の判定の対象にするか）。
- マージは GitHub 上で人間が行う。マージ後は `/post-merge` を実行する。
