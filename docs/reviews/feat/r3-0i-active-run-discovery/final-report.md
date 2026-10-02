# dev-cycle 最終報告: feat/r3-0i-active-run-discovery

## 開発内容
- タスク: R3-0i の (1)(2) — 進行中 run の発見（closes #70）。(3)(4)（issue #57: ロックと条件付きの状態更新）は次の PR
- PR: #95 https://github.com/artisanworkshop/cart-bridge-jp/pull/95
- 承認された計画の要約（`~/.claude/plans/mutable-plotting-reddy.md`）:
  - `GET /runs?platform=&status=active`（`JobRepository::find_active_runs_for_platform()`）と、409 `cbjp_run_in_progress` の `data.active_runs`
  - 4 タブ（Import / Export / Tools / Mappings）で一覧を照会し、Import/Export は自分の種別の run を取り込み、別タブの run は案内（リンク＋キャンセル）してボタンを止める（ユーザー決定）
  - Mappings の保存の無効化（R3-0m の宿題）、Import/Export のハッシュの `?platform=`、localStorage ヘルパーの共通化
- コミット一覧:

| sha | メッセージ |
|---|---|
| `09c4b22` | feat: list active runs per platform and include them in run-in-progress errors (R3-0i) |
| `dbe44da` | feat: discover and adopt runs the admin UI lost track of (R3-0i) |
| `98fbb3e` | docs: record active-run discovery (R3-0i items 1 and 2) |
| `d00defb` | fix: keep stale cleanup previews blocked and scope discovered runs to their platform (R3-0i R1) |
| `8d53d11` | docs: record review-loop round 1 for R3-0i active-run discovery |
| `952da64` | fix: keep the cleanup gate through failed re-previews and refresh the run list on start (R3-0i R2) |
| `3be66b2` | docs: record review-loop round 2 for R3-0i active-run discovery |
| `c9c010d` | fix: match 409 run lists by platform selection and keep polling an empty list (R3-0i G1) |
| `18bd371` | docs: record dev-cycle gate round 1 for PR #95 |
| `407f96a` | docs: describe the 30-second observation window in backlog item R1-X2 (R3-0i G2) |
| `0e448ec` | docs: record dev-cycle gate round 2 for PR #95 |

- 設計ドキュメントからの逸脱:
  - 409 の data に `active_runs` を追加（追加のみ。コード・status は不変）
  - `ActiveRunNotice` にキャンセルボタン（ユーザー決定の「案内＋リンク」に加えたもの。Tools に並ぶ未接続 platform の run は Import/Export で開けないため）
  - Import/Export タブがハッシュの `?platform=` を受け取る
  - Mappings タブの保存の無効化は UI だけ（サーバーは拒否しない。R3-0m の判断どおり）。G1-4/G1-5 で、一覧が空でも 30 秒ごとに照会し、初回取得まで保存を止める形にした（ユーザー決定。サーバー側の 409 は採らなかった）。別ブラウザで run が始まってから次の照会までの最大 30 秒は保存できてしまう
  - キャンセルしたジョブが `paused`/`completed` で上書きされる問題と同時実行ガードの非原子性は、R3-0i の (3)(4)（issue #57）で直す

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己レビュー＋独立サブエージェント） | High 1・Medium 3・Low 7・対象外 2 | High 1・Medium 3・Low 7 | Low 1・対象外 2 |
| R2（独立サブエージェントで検証） | R1 の High/Medium すべて解消・新規 Medium 1・Low 3 | Medium 1・Low 3 | 0 |

判定: R2 で **APPROVE**。R1 の High は「Tools のクリーンアップが run と重なった古いプレビューのまま実行できる退行」（件数 0 なら確認ダイアログなしで run が書いたデータを消しうる）。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot（1 回目） | 4（Medium 4） | 4 | 0 | 未収束 |
| G1 | Codex（1 回目。自動レビューが 5 分で来ず review コメントを自動投稿） | 1（P1） | 1 | 0 | 未収束 |
| G2 | Copilot（2 回目） | 1（Low） | 1 | 0 | 未収束（🟢 Approval recommended） |
| G2 | Codex（2 回目） | 0 | 0 | 0 | **収束** |
| G3 | Copilot（3 回目） | 0 | 0 | 0 | **収束**（🟢・Findings: None） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | push intent の解除応答が A→B→A の切り替え後に古い 409 の一覧を取り込みうる → 選択の番号で照合 | `c9c010d` | [r4163811147](https://github.com/artisanworkshop/cart-bridge-jp/pull/95#discussion_r4163811147) |
| G1-2 | Copilot | Medium | 一覧の世代が platform ごとに数え直され、reconcile を誤って飛ばす → 版を単調増加に | `c9c010d` | [r4163811177](https://github.com/artisanworkshop/cart-bridge-jp/pull/95#discussion_r4163811177) |
| G1-3 | Copilot | Medium | Import の開始・Retry の 409 の照合が platform 名で、A→B→A で古い応答が通る → 全呼び出し元を選択の番号に | `c9c010d` | [r4163811213](https://github.com/artisanworkshop/cart-bridge-jp/pull/95#discussion_r4163811213) |
| G1-4 | Copilot | Medium | 空の一覧で照会が止まり、あとから始まった run を見逃して Mappings の保存がすり抜ける → 30 秒ごとに照会＋初回取得まで保存停止（ユーザー決定） | `c9c010d` | [r4163811241](https://github.com/artisanworkshop/cart-bridge-jp/pull/95#discussion_r4163811241) |
| G1-5 | Codex | P1（Medium と判定） | G1-4 と同じ | `c9c010d` | [r4163819922](https://github.com/artisanworkshop/cart-bridge-jp/pull/95#discussion_r4163819922) |
| G2-1 | Copilot | Low | backlog R1-X2 の記述が 30 秒の照会と食い違う | `407f96a` | [r4164527858](https://github.com/artisanworkshop/cart-bridge-jp/pull/95#discussion_r4164527858) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（すべて修正・Resolve 済み）。

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36991324951 green（対象 `0e448ec`）
- 品質チェック: green（PHPUnit 1407 件・Jest 87 件・PHPCS・PHPStan・eslint・tsc・build）
- ミューテーション: PHP 6 種・JS 16 種すべて CAUGHT（R1・R2・G1 の追加分を含む）
- 実機（wp-env・mock `mockv`）: 見失った run の発見・取り込み・案内・キャンセル、409 の経路、失敗で止まった run、同種別の 2 本目の案内、Tools のプレビューの「取り直して」、Mappings の保存の復帰と 30 秒ごとの照会を確認。検証データは撤去済み
- 実 API: 未確認（この PR はジョブ表と管理画面だけを扱い、ASP の API を呼ばない）

## backlog に送ったもの
- `r3-0i-active-run-discovery/R1-L1`: フックの単体テストが無い（`@testing-library/react` の導入判断が要る）
- `r3-0i-active-run-discovery/R1-X1`: Import の Retry/Cancel の catch がプラットフォームの照合なしに書く（既存。`fix-46-pref-state-repair/R1-X1` と一緒に）
- `r3-0i-active-run-discovery/R1-X2`: プレビュー後、次の照会（最大 30 秒）より前に始まって終わった run は見えない（既存の制限）

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- その後、R3-0i の (3)(4)（issue #57: options テーブルのロックと条件付きの状態更新）を `/cbj-dev-cycle` の plan mode から始める（計画の概要は `~/.claude/plans/mutable-plotting-reddy.md` の PR-B）。チェックボックス R3-0i は (3)(4) の完了時に付ける
- 両 bot とも「収束」なので、後日の再確認は不要
