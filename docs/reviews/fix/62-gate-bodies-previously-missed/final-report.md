# dev-cycle 最終報告: fix/62-gate-bodies-previously-missed

## 開発内容
- タスク: issue #62 — `gate-bodies.sh` が Copilot 新形式（`ccr-overview-v2`）の「Previously missed」指摘を整形出力に出さない
- PR: #64 https://github.com/artisanworkshop/cart-bridge-jp/pull/64（`Closes #62`）
- 実行モード: ユーザー指示「Codex と Copilot を最大 3 回ずつ順番に、自動で全 6 回」に従い、`sequential` + `auto-commit`（修正はコミット・push まで自動。マージはしない）。順序は Codex → Copilot → Codex → Copilot → Codex → Copilot
- 内容: `gate-bodies.sh` の整形を新形式に対応（`Findings` の重要度別件数・`Open` の dbid と `· New`・`Previously missed`）、`--format` の切り出し、回帰テスト `test-gate-bodies.sh` と fixtures、`SKILL.md` / `.claude/rules/skill-scripts.md` の更新。ゲートで指摘された分として、テストスクリプトの失敗可視化、誤記の修正、`quality.sh` と CI への組み込み、未知の節（fail-open）のテスト追加が加わった
- コミット一覧:
  | sha | メッセージ |
  |---|---|
  | 1a9a7ef | fix: report Copilot's "Previously missed" findings in gate-bodies.sh |
  | c21b8db | fix: keep test-gate-bodies.sh failures visible under set -e |
  | 8461f6d | docs: fix a misspelling in the gate-bodies.sh header comment |
  | e1b3903 | ci: run the gate-bodies regression test in quality.sh and CI |
  | f715cc3 | test: cover the fail-open handling of unknown details sections |
  | 88b5cf7 / e1c7a05 / c6f1039 / c92814c / 04e5ee8 / 704c8f1 | docs: 各ゲートターン（G1〜G6）の記録 |
- 設計ドキュメントからの逸脱:
  1. **共有 CI パイプラインへの追加**（G4-1 の対応）: `.github/workflows/ci.yml` に独立した新ジョブ `dev-tooling` を追加した（既存 3 ジョブは無変更）。自動実行の指示の範囲で行ったが、CI 設定の変更なので**マージ前にご確認ください**（追加のみで差し戻し可能）。
  2. **収束済みボットの再依頼**: `cbj-dev-cycle` の既定では、新規指摘 0 件で収束したボットは以降飛ばす。今回は「全 6 回」の指示に従い、Codex を G3・G5 で再依頼した（前回のレビュー以降 HEAD が変わっていることを条件にした）。

## review-loop（PR 前）
この PR は `/start-task` で作成し、PR 前の review-loop は実施していない（小さな開発補助スクリプトの修正のため）。レビューは全て bot ゲートで行った。

## Codex / Copilot ゲート（順番実行・全 6 ターン）
| ターン | bot | 対象 HEAD | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|---|
| G1 | Codex（1 回目） | 1a9a7ef | 1（P1） | 1 | 0 | 未収束 |
| G2 | Copilot（1 回目） | 88b5cf7 | 1（Low、🟢 Approval recommended） | 1 | 0 | 未収束 |
| G3 | Codex（2 回目） | e1c7a05 | 0（「no major issues」） | — | — | 収束 |
| G4 | Copilot（2 回目） | c6f1039 | 1（Medium、**本文の `Previously missed` のみ**） | 1 | 0 | 未収束 |
| G5 | Codex（3 回目） | c92814c | 0（「no major issues」） | — | — | 収束・上限到達 |
| G6 | Copilot（3 回目） | 04e5ee8 | 1（Medium） | 1 | 0 | 上限到達（4 回目は依頼しない） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Codex | P1 | `test-gate-bodies.sh` の `set -e` 下の単独代入 `out=$(format ...)` が、失敗時にラベル付きの失敗記録・サマリの前に終了させる（`skill-scripts.md` のルールにこの PR のテスト自身が違反していた）→ `load` ヘルパーで戻り値を受けて失敗を可視化。fixture を外すミューテーションで確認 | c21b8db | [r4091975087](https://github.com/artisanworkshop/cart-bridge-jp/pull/64#discussion_r4091975087)（Resolve 済み） |
| G2-1 | Copilot | Low | コメントの誤記「スレット」→「スレッド」（他に同じ誤記が無いことも確認） | 8461f6d | [r4092049277](https://github.com/artisanworkshop/cart-bridge-jp/pull/64#discussion_r4092049277)（Resolve 済み） |
| G4-1 | Copilot | Medium | 回帰テストが `quality.sh` にも CI にも組み込まれていない（**本文の `Previously missed` の指摘でスレッド無し**）→ `quality.sh` の末尾と、CI の新ジョブ `dev-tooling` に追加。Ubuntu（mawk）で 5 秒で pass し移植性も確認 | e1b3903 | 本文指摘（`G4.md` と PR サマリコメントが記録） |
| G6-1 | Copilot | Medium | 未知の `<details>` 節を出す（fail-open）挙動の回帰テストが無い → 実物（`Resolved since last review`）と合成の fixture を追加。未知の節を握りつぶすミューテーションで 5 件失敗することを確認 | f715cc3 | [r4092360435](https://github.com/artisanworkshop/cart-bridge-jp/pull/64#discussion_r4092360435)（Resolve 済み） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし。スレッドは 3 件とも Resolve 済みで、保留した指摘はありません。

## 品質ゲート
- CI: 最終 HEAD `704c8f1` で PHP quality (8.2/8.3)・PHPUnit (wp-env)・JS/TS・**Dev tooling (cbj-dev-cycle scripts)** の 5 ジョブすべて green。https://github.com/artisanworkshop/cart-bridge-jp/pull/64/checks
- ローカル: `test-gate-bodies.sh` 全件 ok（実物 fixture 4 件 + 合成 2 件）、`bash -n` / `shellcheck -S warning` 問題なし。各修正はミューテーションで検出力を確認済み

## 気付いたこと
- **この PR で直した `gate-bodies.sh` が、そのままレビューで役に立った。** G4 では、インライン 0 件・`Findings: None` のレビューの `Previously missed`（スレッド無し）に Medium の指摘があり、整形出力（非 `--raw`）だけでそれを拾えた。旧版のままなら見落としていた。また未知の節 `Resolved since last review` も出力された（→ G6-1 でこの挙動をテスト化）。
- ゲートで出た指摘はすべて、この PR 自身の品質に関する妥当なものだった（テスト自身がルール違反、CI 未組み込み、約束のテスト漏れ）。

## 次にできること（人間の判断）
- **`ci.yml` の新ジョブ `dev-tooling` の追加を確認**してマージ（GitHub 上で人間が行う）。マージすると issue #62 が自動クローズされる。マージ後は `/post-merge`
- 順序依存テストの issue #63 は未着手のまま
- グローバル `~/.claude/skills/fix-copilot-review/scripts/gate-threads.sh` の `bodies` にも同種のギャップの可能性がある（`Previously missed` を扱うコードが見当たらない。未検証・リポジトリ外）
- `main` の未 push コミット `362a6e2`（PR #61 の学びの蒸留）が残っている
