# dev-cycle 最終報告: fix/54-retry-platform-guard

## 開発内容
- タスク: issue #54 — `JobManager::retry()` がプラットフォーム単位の同時実行ガードを経由せず並行runと競合しうる
- PR: #56 https://github.com/artisanworkshop/cart-bridge-jp/pull/56
- 承認された計画の要約: `JobRepository::has_active_job_for_platform_excluding_run()` を新設し、対象ジョブ
  自身の `run_id` を除外して「別runが同一プラットフォームで進行中か」を判定。`JobManager::retry()` は
  ガードに引っかかると既存の `RunAlreadyInProgressException` を投げ、`RestController::retry_job()` は
  既存の `run_in_progress_error()`（409）で応答する。フロントエンドの同型ギャップ
  （`ImportTab.tsx` の `retryDisabled` が自分の種別の `starting` を見ていなかった）も修正。
  `cancel_run()` の同種の競合（`f1-6-import-ui/R1-X1`）は根本原因が異なるため本PRのスコープに含めない、
  という計画時の判断を維持した。
- コミット一覧:
  - `67a891d` fix: guard JobManager::retry() against concurrent runs on the same platform
  - `ae0e908` fix: block ImportTab retry while the same run type is starting
  - `a82046f` docs: record the retry() concurrency guard fix (issue #54)
  - `3243c25` docs: record review-loop R1 (APPROVE) for retry() guard fix
  - `3a20ef7` docs: record final HEAD for review-loop R1
  - `90db57e` docs: record PR #56 creation in dev-cycle state
  - `af83d3a` docs: record G1 bot review request state
  - `04fc9d4` docs: record gate round G1 (Codex/Copilot) — hold the atomic-guard finding
  - `c150b11` docs: record G2 bot review request state
- 設計ドキュメントからの逸脱: なし。`RunAlreadyInProgressException`・`run_in_progress_error()` の再利用は
  既存パターンの横展開。

## review-loop（PR前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Critical/High/Medium 0件、Low 4件 | Low 2件（stale comment・状態ファイルの絶対パス）を即修正 | Low 2件（非原子的ガード・境界ケーステスト不足）+ 対象外1件（ExportTab.tsxの同種stale comment） |

判定: **APPROVE**（R1のみで収束）。独立サブエージェント（`general-purpose`/opus）による敵対的レビューを併用。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1 | 0 | 1 | 収束（新規は0だがG1-1は未解決のまま残す） |
| G1 | Codex(P2) | 1（Copilotと同一箇所） | 0 | 1 | 収束（同上） |
| G2 | Copilot | 0 | - | - | 収束 |
| G2 | Codex | 0 | - | - | 収束 |

### 修正した指摘
なし（G1-1は保留）。

### 修正しなかった指摘（PR上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Copilot・Codex(P2) | Medium（保留・要判断。ユーザー確認済み） | `has_active_job_for_platform_excluding_run()`の判定→更新が非原子的で、狭いウィンドウでは依然として並行書き込みが起こりうる。本PRが新たに持ち込んだ競合ではなく`start_run()`自身が元から持つ同種の競合（自分自身のR1レビューでも`fix-54-retry-platform-guard/R1-L1`として検出済み）。完全に塞ぐには`start_run()`側も含むプラットフォーム単位のロック設計が必要で、承認済み計画のスコープ（`retry()`へのrun_id考慮ガード追加）を超えるため、ユーザー判断のもと保留とした | [Copilot](https://github.com/artisanworkshop/cart-bridge-jp/pull/56#discussion_r4083878403) / [Codex](https://github.com/artisanworkshop/cart-bridge-jp/pull/56#discussion_r4083933827) |

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/35876862600 green
  （G1後のdocsのみpushも https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/35890351951 でgreen再確認）
- 品質チェック: green（`composer lint && composer analyze && composer test:wpenv` = PHPUnit 1079件 +
  `npm run lint && npm run build`）

## 次にできること（人間の判断）
- G1-1（非原子的ガード）は `docs/review-backlog.md` の `fix-54-retry-platform-guard/R1-L1` に記録済み。
  根本対応（`start_run()`/`retry()`/`cancel_run()`が共有するプラットフォーム単位ロック設計）は、同根の
  既存backlog（`f1-6-import-ui/R1-X1`・`f1-7-tools/R1-L3`・`f1-7-tools/G1-6`）と合わせて別issueとして
  起票するかどうかの判断が必要
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
