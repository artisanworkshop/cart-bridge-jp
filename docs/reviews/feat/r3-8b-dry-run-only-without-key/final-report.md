# dev-cycle 最終報告: feat/r3-8b-dry-run-only-without-key

## 開発内容
- タスク: R3-8b キーが無い間は dry-run だけ（D30。`docs/10-tasks.md` R3-8b・`docs/03` §10.7）
- PR: #124 https://github.com/artisanworkshop/cart-bridge-jp/pull/124
- 承認された計画の要約: 無料版に「拡張が自分の種類を dry-run だけにできる」汎用の口（`EntityType::dry_run_only_reason()`・REST の宣言・run の作成と Retry の拒否・画面の注記）を足し、Pro は `License\LicenseGate`（`LicenseStore::is_activated()`）で顧客・受注・クーポンを制限する。ユーザー決定（2026-10-11）: 制限中の種類も選べて dry-run に含め、Run import/export はその種類を送らない（実行できる種類が 0 のときだけ押せない）、制限中の種類の失敗したジョブの Retry もサーバーが断る
- コミット一覧:

| sha | メッセージ |
|---|---|
| 1895cdf | feat: let extensions limit their entity types to dry runs (R3-8b) |
| aeef048 | feat: show dry-run-only entity types as preview only in the admin UI (R3-8b) |
| ea5d6ff | chore: stop the ColorMe rehearsal before runs limited to dry runs (R3-8b) |
| 1e5e30c | docs: record the R3-8b dry-run-only restriction |
| fe419e5 | fix: address R3-8b review-loop R1 findings |
| f4868dc | docs: record R3-8b review-loop R1 |
| bdd7b07 | docs: update the R3-8b notes after review-loop R1 |
| 4e3cba2 | test: pin how the dry-run-only error joins the reasons (R3-8b R2-1) |
| 4645ca4 | docs: record R3-8b review-loop R2 |
| c238e93 | fix: describe activation as enabling the operations the shop supports |
| a81c12a | docs: correct the R3-8b default selection and test counts |
| ab4ed95 | docs: record dev-cycle gate round 1 |
| 48d28e8 | docs: correct the gate round 1 log time |
| fa2b828 | fix: recheck the job status under the lock before the dry-run-only check |
| fc5103d | docs: record dev-cycle gate round 2 |

- 設計ドキュメントからの逸脱: なし（画面の見せ方と Retry の拒否は §10.7 が R3-8b の計画に委ねた点で、計画時のユーザー決定）。範囲の限界（実行中の run の続きのページ・Retry したジョブの後ろの pending のジョブは止めない。§10.7「守ること」4）は docs/03 と backlog に記録した

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Critical/High/Medium 0・Low 6 | 6（画面・境界・横展開に関わるため R1 で修正） | 0 |
| R2 | R1 全解消・新規 Low 1 | 1 | 1（範囲の限界の記録） |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（「Didn't find any major issues」・👍） |
| G1 | Copilot | 4 | 4 | 0 | 未収束 |
| G2 | Copilot | 1（本文の Previously missed） | 1 | 0 | 未収束 |
| G3 | Copilot | 1（本文の一文。PR 本文の件数） | 1 | 0 | 上限（3 回目。🟢 Approval recommended・open findings 0） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | docs の「制限中の種類も既定で選ぶ」がエクスポートのベータ（受注）と食い違う → 計画どおり既定の選択は制限と独立のままにし、docs を直した | a81c12a | [r4241599698](https://github.com/artisanworkshop/cart-bridge-jp/pull/124#discussion_r4241599698) |
| G1-2 | Copilot | Low | docs/03 のテスト件数が古い | a81c12a | [r4241599710](https://github.com/artisanworkshop/cart-bridge-jp/pull/124#discussion_r4241599710) |
| G1-3 | Copilot | Low | docs/10 のテスト件数が古い | a81c12a | [r4241599728](https://github.com/artisanworkshop/cart-bridge-jp/pull/124#discussion_r4241599728) |
| G1-4 | Copilot | Low | Pro の設定画面の有効化済みの文が言い過ぎ（クーポンは取込みだけ等）→「接続先が扱える操作の範囲で」に | c238e93 | [r4241599744](https://github.com/artisanworkshop/cart-bridge-jp/pull/124#discussion_r4241599744) |
| G2-B1 | Copilot | Medium | ほぼ同時の Retry に負けた側が、制限がかかると false でなく 400 を返す → ロックの中で状態を読み直してから判定 | fa2b828 | なし（[review 5483381201](https://github.com/artisanworkshop/cart-bridge-jp/pull/124#pullrequestreview-5483381201)） |
| G3-B1 | Copilot | Low | PR 本文のテスト件数が古い → PR 本文を更新 | fc5103d（コード変更なし） | なし（[review 5483423209](https://github.com/artisanworkshop/cart-bridge-jp/pull/124#pullrequestreview-5483423209)） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
- なし

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38141873239 green（fc5103d）
- 品質チェック: green（PHPUnit 無料版 1277・Pro 757、Jest 99、PHPCS・PHPStan・マウントの 403・i18n:check）
- ガードの変異: 実装時 14・review-loop 5・ゲート 1、すべて検出
- dev サイト: 未有効化で宣言が制限中・`POST /runs` の import/export が 400 でジョブが増えない、有効化の記録で外れる、Pro を無効にすると宣言が全部 false。Import/Export タブの注記・Run import の無効化・Pro の設定画面を目視

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: R3-8c（更新の配信・Pro の zip・リリース）→ R3-8d（Pro の i18n。今回足した Pro の文字列も含む）→ R3-9 → R3-5b → R3-4
