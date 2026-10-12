# dev-cycle 最終報告: fix/121-orphaned-job-recovery

## 開発内容
- タスク: R3-9（issue #121）— 停止・致命的エラーで Action Scheduler（AS）のアクションを失ったジョブが進行中のまま残り、そのプラットフォームの新しい run・ツール・切断が 409 で止まり続ける問題の回復
- PR: #131 https://github.com/artisanworkshop/cart-bridge-jp/pull/131（`Closes #121`）
- 承認された計画の要約（`~/.claude/plans/snazzy-booping-lemur.md`）:
  - 孤立の検出（案 B）を土台にする（`Sync\OrphanedRunRecovery`）。失敗したジョブを持たず、run のどのジョブにも AS の生きたアクションが無く、最後の活動から 15 分を過ぎた進行中の run の最初の未終了のジョブを `action_lost` の失敗にする。既存の Retry で続きから進められる。
  - ハートビート（`Sync\JobHeartbeat`）で、長いページを処理の途中で誤って回復しないようにする。
  - Retry では残った pending のアクションを取り消す。致命的エラーは `action_scheduler_unexpected_shutdown` で即時に `fatal_error` にする。
  - 画面は失敗の理由のコードを訳す。
  - 停止時のフック・再有効化のときの即時の回復・自動の再開は採らない。
- コミット:
  - `a8895ec` fix: recover runs whose Action Scheduler actions were lost (R3-9)
  - `eeb910c` feat: explain lost-action and fatal-error job failures in the run progress
  - `1092534` docs: record the orphaned job recovery (R3-9)
  - `057d0cf` fix: fail only the first unfinished job when recoveries race (R3-9 R1)
  - `9c1083f` docs: record review-loop R1 of the orphaned job recovery
  - `9509809` fix: keep namespaces and URLs intact in fatal error messages (R3-9 R2)
  - `a69b42a` docs: record review-loop R2 of the orphaned job recovery
  - `44bf2c1` docs: record dev-cycle gate round 1
- 設計ドキュメントからの逸脱（`docs/03` に記録済み）:
  - §3 のステートマシンに「進行中 → failed（`action_lost`・`fatal_error`）」を足した。
  - §5 に「孤立したジョブの回復」の小節を足した。`GET /runs` は「読むだけの表示はロックを取らない」の例外として、run 単位の条件つきの CAS で書き換える。
  - 回復の正しさは「1 ページは AS の失敗扱いの時間（5 分）に収まる」という前提に依らない。
- 計画から変わった点:
  - 致命的エラーのフックは、WP-CLI でキューを処理したときは走らない（WP-CLI の `wp_die` のハンドラーが終了するため）。Web のリクエスト（WP-Cron・非同期のキューランナー）では即時に効くことを実測した。WP-CLI の場合は、AS の失敗扱いの後に土台が `action_lost` で回復する。
  - `action_lost` の説明に「処理の途中で PHP が止まった場合」を含めた。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 1・Low 4 | 5（Medium 1・Low 4） | 0 |
| R2 | 新規 Low 3（APPROVE） | 2 | 1（R2-3 は記録のみ） |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（Didn't find any major issues） |
| G1 | Copilot | 0 | 0 | 0 | 収束（🔵 Needs a closer look・0 open findings。総評のみ） |

### 修正した指摘
なし（ゲートでの指摘なし）。

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし。

## 品質ゲート
- CI: 対象 HEAD `a69b42a` の run は green（PHP quality 8.2/8.3・PHPUnit〔wp-env〕・JS/TS・Distribution・Dev tooling）。記録の commit `44bf2c1` の CI は、最終報告の時点で走っている。
- 品質チェック（`quality.sh`）: green（無料版 PHPUnit 1312 件・Pro 841 件・Jest 104 件・PHPCS・PHPStan・i18n:check・dev-mount）。
- ミューテーション: ガード 30 件余りを外し、テストが落ちることを確かめた。例外は 2 件で、どちらも理由を R1.md に記録した。
  - `WP_CONTENT_DIR` の置換: テスト環境では定数を差し替えられないため検出できない。
  - 致命的エラーのフックの `is_int`: `strict_types` との多重の防御。
- 実機確認（wp-env の dev サイト・mock アダプタ）:
  - 停止 → キューの実行 → 再有効化 → 回復 → Retry で完了 → 新しい run を始められることを確認した。
  - 本物の致命的エラーは 2 経路を確認した（Web のリクエストでは即時に `fatal_error`、WP-CLI では AS の失敗扱いの後に `action_lost`）。
  - 検証のデータは撤去済み。
  - 管理画面の目視は未実施（dev サイトが未ログイン。表示の差し替えは Jest で確認）。

## マージ前に人が確認すること
- Copilot の総評: 「ラン間の回復・DB のロック・AS の失敗の扱いは、テストが厚くても最後に人の確認を」。特に次の 2 点。
  - `JobRepository::mark_failed_if_idle()` の自己結合の UPDATE（MySQL/MariaDB の複数テーブル UPDATE・InnoDB のロック付きの読み）。
  - ロックの外の `GET /runs` での書換え。
- 管理画面の目視: Import タブで失敗の理由（`action_lost`・`fatal_error`）の表示を確かめる（任意）。

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`（蒸留の候補: 「WP-CLI の `wp_die` のハンドラーは終了するので、WP-CLI で処理したキューでは `shutdown` アクション〔AS の `FatalErrorMonitor`〕が走らない」「ロックの外で書く処理は run 単位の前提を書換えの SQL の条件に入れる」）
- backlog の `fix/121-orphaned-job-recovery/R2-3`（Cancel との理論上のデッドロック）は記録のみ。
- 次のタスク: R3-5b（セキュリティ最終監査）→ R3-4（Pro の販売サイトの準備は別に進める）。
