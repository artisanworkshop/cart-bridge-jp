# dev-cycle 最終報告: feat/dev-tooling-gate-approval-guard

## 開発内容

- タスク: dev-tooling — 確認ゲート → commit の順序の機械検査（`gate-record.sh approve` + `check`）/ `verify-with-mock-adapter` の push 検証 example / cbj-dev-cycle Step 3 の注記（PR #80 の `/post-merge` で出した提案 3 件）
- PR: [#81](https://github.com/artisanworkshop/cart-bridge-jp/pull/81)（開発補助 `.claude/skills/**`・`.claude/rules/**` のみ。プラグイン本体は変更なし）
- 承認された計画の要約:
  1. 「commit の前にユーザーへ確認する」がメモに書いてあっても計 4 回破られた（PR #79 G1・PR #80 G1〜G3）原因は、`check` が「修正」の指摘に commit の sha を要求して先行 commit を誘うこと。`approve` が記録に承認時刻を書き、`check`/`summary`/`replies` が修正の commit の committer 時刻と比べて、確認前の commit を非ゼロで止める
  2. `examples/push-intent-resolution/`（`verify-rest.php`・`cleanup.php`）を追加し、push intent の解除フローを `rest_do_request()` で PASS/FAIL 検証する
  3. Step 3 に「UI 状態・エラー表示・非同期競合の Low は数行で直せるなら R1 で直す」を追記
- コミット一覧（実装〜G3 の 20 件。以降は G3 の記録と本報告のコミット）:
  | 区分 | sha | 内容 |
  |---|---|---|
  | 実装 | 2a79960 / 0a66b60 / 7ce4ed5 / c25d5dd / e3ccd6c | `approve` + `check` の順序検査、テストの完了マーカー、`init` の案内、docs、push 検証 example |
  | R1・R2 | eb00583 / 92af892 / 17451f1 / 94d0220 / 796d5c5 | 独立レビュー R1（Medium 2・Low 12）と R2（新規 Low 4）への対応 |
  | G1 | 42cf0aa / 537fae7 | 承認行の許可リスト・重複行の拒否、link 404 検証の順序、非配列 seed の中止 |
  | G2 | 5ce2478 / 2f001eb | 読めない承認行の降格拒否、補足の文法を末尾まで縛る、cleanup を mock 登録時のみ実行、verify の残り検査に jobs を追加 |
  | G3 | 45a56b6 / b13f876 | 括弧の種類の対応付け、verify を mock・トークン無しの key だけに限定 |
  | 記録 | 4a0764c / 9d05a75 / 055758d / 64a81e9 ほか | dev-cycle 状態・R1/R2/G1〜G3 の記録・最終報告 |

### 設計ドキュメントからの逸脱

なし（開発補助のみで `docs/03` に該当なし）。

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 2・Low 10（独立レビュー）＋ Low 2（自己レビュー） | すべて修正 | なし |
| R2（検証） | 新規 Low 4・対象外 2 | 修正 4（新規 3・対象外 1）・限界の明記 1 | 2 |

判定: **APPROVE**（R2 で収束）。詳細: `docs/reviews/feat/dev-tooling-gate-approval-guard/R1.md`・`R2.md`

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留/対応不要 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot（1 回目） | 3 | 3 | 0 | 未収束 |
| G1 | Codex（1 回目） | 2 | 1 | 1（保留: 同じ秒の commit の扱い） | 未収束 |
| G2 | Copilot（2 回目） | 5 | 5（G2-2 は指摘の前提が誤り。暗黙の依存の明示のみ） | 0 | 未収束 |
| G2 | Codex（2 回目） | 0 | — | — | **収束**（055758d に「大きな問題なし」） |
| G3 | Copilot（3 回目・上限） | 2 | 2 | 0 | 依頼上限に到達 |
| G4 | Copilot（**依頼していない自発的なレビュー**。最終 HEAD ea081d7 が対象） | 2（スレッド 1・本文 1） | 2 | 0 | 再依頼しない |

指摘は 3 ラウンドとも、承認行の文法の厳密化（`Z-junk` → 重複行 → 補足の末尾 → 括弧の対応）と、example のガード（mock 限定・残りの検査）に集中した。検出できる範囲を広げるたびに次の境界が指摘される構造で、G3 の 2 件は最終 HEAD で Copilot の再確認を受けていない。

### 修正した指摘（PR 上で Resolve 済み）

| ID | bot | 重大度 | 内容 | コミット |
|---|---|---|---|---|
| G1-1 | Copilot | High（Low と判断） | 非配列の共有 `cbjp_verify_seed` を verify が上書きして失う | 537fae7 |
| G1-2 | Copilot | Medium | 承認時刻の直後の `Z-junk`・`Z!x` が通る | 42cf0aa |
| G1-3 | Copilot | Medium | 商品 1 件だと link の 404 を SKIP のまま ALL PASS になる | 537fae7 |
| G1-5 | Codex | P2 | 承認行が複数あると先頭だけ読み、古い auto-commit が時刻承認を隠す（`- PR:` の重複も同様に対応） | 42cf0aa |
| G2-1 | Copilot | High | 読めない承認行を `--force --auto-commit` で auto-commit に置き換えられる | 5ce2478 |
| G2-2 | Copilot | High（前提は誤り） | テストが記録ファイルを作っていないという指摘。実際は作成済みでテストは通っていた。遠い節への暗黙の依存を明示した | 5ce2478 |
| G2-3 | Copilot | High | mock 未登録でも cleanup のガードを通る | 2f001eb |
| G2-4 | Copilot | High | cleanup が消すもの（jobs・ログ）と verify の開始前チェックが揃っていない（一部対応: run ID での限定は過剰と判断） | 2f001eb |
| G2-5 | Copilot | Medium | 括弧の補足の後ろの余りが通る | 5ce2478 |
| G3-1 | Copilot | High | verify が mock 登録しか確認せず、実アダプタ・トークン残りの key でも実 export を走らせる | b13f876 |
| G3-2 | Copilot | Medium | 全角・半角の括弧が食い違う承認行が通る | 45a56b6 |
| G4-1 | Copilot | High（Low〜Medium 相当） | 41 文字以上の `対象 HEAD` が先頭 40 文字に切り詰められ、祖先の除外に化ける | f1840b8 |
| G4-B1 | Copilot（本文・スレッド無し） | Medium | verify の開始前チェックがログだけの残りを見ない（cleanup が消す対象と不一致） | 915df30 |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-4 | Codex | P2 | 承認と同じ秒の commit を通す点。committer 時刻も承認時刻も 1 秒単位で、同秒は順序を判別できない。等号を違反にすると `approve && git commit` の連鎖という正しい手順を誤検出するため、通す設計にした。SKILL.md とヘッダの限界に明記済み | [r4118734874](https://github.com/artisanworkshop/cart-bridge-jp/pull/81#discussion_r4118734874) |

### review-loop backlog（`docs/review-backlog.md`。今回は対応しない）

- `dev-tooling-gate-approval-guard/R2-3`: verify-rest.php の step 3 の `warned` は他の警告と区別できない（印のある local_id ごとの確認が厳密）
- `dev-tooling-gate-approval-guard/R2-X2`: cleanup が Action Scheduler の complete 行を消さない（実害なし）

## 品質ゲート

- CI: [PR #81](https://github.com/artisanworkshop/cart-bridge-jp/pull/81/checks) 5 ジョブ green（最終の CI 結果は本報告の commit 後に確認して報告する）
- 品質チェック: `.claude/skills/cbj-dev-cycle/scripts/quality.sh` all green（`composer lint`/`analyze`/`test:wpenv`〔PHPUnit 1200 件〕、`npm run lint`/`build`、`test-gate-record.sh` 400 項目・`test-gate-turn.sh` 86・`test-gate-bodies.sh` 31）。macOS bash 3.2 と Ubuntu 24.04（bash 5.2）の両方で dev-tooling テストが通ることを確認
- ミューテーション（`review-loop/scripts/mutate-check.sh`）: 実装 13 種 + R1 8 種 + R2 2 種 + G1 8 種 + G2 4 種 + G3 2 種 + G4 1 種 = 38 種をすべて検出。NOT CAUGHT だった冗長なガード 1 つ（`git cat-file` の事前確認）は削除
- wp-env 実機確認（`/verify-with-mock-adapter`、platform key `mockv`）: verify-rest.php ALL PASS → cleanup（`left` 全 0）→ uninstall → `inspect` が検証前と一致。共有オプション（`customers` キー）が verify → cleanup の後も残ること、`colorme`・mock 未登録・非配列 seed・残りがある状態の再実行がそれぞれ拒否・ABORT されることを実測
- **実運用での確認**: この PR 自身の G1〜G3 で、確認ゲート → `approve` → commit → `check` → push の順序を 3 回とも通した（PR #79・#80 で 4 回破られていた順序）。`check` は 3 回とも、承認より後の commit として OK を返した

## 次にできること（人間の判断）

- **未確認の範囲**: Copilot は 3 回の依頼上限に達した後、最終 HEAD（ea081d7）に自発的なレビューを返し、G4 の 2 件を修正した（f1840b8・915df30）。G4 の修正は Copilot の再確認を受けていない。Codex は 055758d までの確認で、G2〜G4 の修正コミットは未レビュー。G3 までの傾向どおり、ゲートのたびに次の境界（入力検証・cleanup と verify の対象の不一致）が指摘されている。G4 の本文サマリには「critical 1・moderate 3」とあるが、列挙された指摘は 2 件で、残りは特定できなかった
- 保留分の判断: G1-4（同じ秒の commit を通す設計）は据え置いた。等号を違反にしたい場合は指示いただければ追加対応する（`approve && git commit` の連鎖を誤検出する副作用がある）
- 承認行の文法は 3 ラウンドで厳密化を重ねた。これ以上の厳密化は「検出であって防止ではない」という設計上の限界（手で遡った時刻・`CBJ_GATE_NOW` 等は防げない）に対する効果が小さいので、追加の指摘は限界の明記で足りると考える
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`（CLAUDE.md・`.claude/rules/`・SKILL.md への知見蒸留を含む）
