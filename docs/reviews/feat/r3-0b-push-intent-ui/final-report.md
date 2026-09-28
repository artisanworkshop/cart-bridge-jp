# dev-cycle 最終報告: feat/r3-0b-push-intent-ui

## 開発内容

- タスク: R3-0b（D21-B、issue #73）PR 2/2 — Export タブへの push intent 解除 UI。バックエンド + REST は PR #79（マージ済み）
- PR: [#80](https://github.com/artisanworkshop/cart-bridge-jp/pull/80)
- 承認された計画の要約: `PushIntentsPanel`（新規コンポーネント）を Export タブに組み込み、未解決の push intent が
  あれば常時表示の `Notice` + 表を出す。行ごとに「未作成として解除」（`not_created`）と remote_id 入力＋
  「リンクして解除」（`link`）を提供し、`window.confirm()` は使わない。あわせて `PushIntent` 型の追加、
  `.claude/skills/verify-with-mock-adapter` のモックアダプタへの push 検証切替の追加、`docs/10-tasks.md`・
  `docs/03-design-decisions.md`・`docs/review-backlog.md` の更新を行う
- コミット一覧:
  | sha | メッセージ |
  |---|---|
  | 27b9045 | feat: add PushIntent type for the Export tab resolution UI |
  | 7a530be | feat: add push-intent resolution UI to Export tab (D21-B, PR 2/2) |
  | 31af65e | feat: add push-failure toggle to mock-adapter verification template |
  | a8c5ea4 | docs: close out R3-0b (issue #73) in task ledger, design decisions, and backlog |
  | 426c568 | fix: refetch push intents after an export run completes (R1-1) |
  | 3f358e9 | docs: record R1 review round |
  | 482ad08 | docs: record R2 APPROVE |
  | 0f96f1d〜6321a55 | docs: dev-cycle 状態更新・PR 番号記録 |
  | f1e6b88 | fix: harden push-intent resolve against stale GET races and 404s (G1) |
  | 9002c60 | docs: record gate round G1 |
  | 691cb6e | docs: dev-cycle 状態更新（G1後） |
  | ef716e7 | fix: refetch (not locally filter) after resolving a push intent (G2) |
  | c456694 | docs: record gate round G2 |
  | bf28f8a | docs: dev-cycle 状態更新（G2後） |
  | af27464 | fix: guard $seed itself before array access in verify-mock-adapter template (G3) |
  | c81ec3b | docs: record gate round G3（最終） |

### 設計ドキュメントからの逸脱

なし。`docs/03-design-decisions.md` §10.2 D21-B が要求する UI 要件（Notice + 一覧、`not_created`/`link`、
`window.confirm()` 不使用）をそのまま実装した。実装が進むにつれて設計ドキュメント自体を実装に合わせて
更新した箇所が3回ある（いずれも記述の精度向上であり、設計方針そのものの変更ではない）:
1. REST 応答の実際のキー構造（`details` オブジェクトが entity_type 別に異なるサブセットを持つ）が
   当初の設計メモ（`DryRunLabel`）より詳細化されている旨を記載（R1）
2. 一覧の再取得タイミング（マウント時・export 完了時）を追記（R1）
3. 解除成功時／404時も一覧を再取得する設計（ローカル除去のみという記述から更新。G2の修正内容に
   ドキュメントを追随させた、G3）

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 1（独立レビュー）・Low 2（自己レビュー） | Medium 1・Low 2 全て修正 | Low 7件（独立レビュー） |
| R2（検証） | 新規混入 Low 1 | 修正せず backlog へ（後にG1で修正） | Low 1 |

判定: **APPROVE**（R2で収束）。詳細: `docs/reviews/feat/r3-0b-push-intent-ui/R1.md`・`R2.md`

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留/対応不要 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex（1回目） | 3（実質） | 2 | 1（対応不要: 設計判断） | 収束済み指摘なし→次ラウンドへ |
| G1 | Copilot（1回目） | 6（実質7、重複除く） | 5（重複除くと） | 1（保留: 部分修正） | 同上 |
| G2 | Codex（2回目） | 1 | 1 | 0 | 同上 |
| G2 | Copilot（2回目） | 2 | 2 | 0 | 同上 |
| G3 | Codex（3回目・上限） | 1 | 1 | 0 | **収束**（依頼上限到達） |
| G3 | Copilot（3回目・上限） | 2 | 2 | 0 | **収束**（依頼上限到達） |

G1・G2 で見つかった指摘の多くは、直前のラウンドの修正自体が引き起こした新規の回帰・見落としだった
（例: R1-1 の「export 完了後に一覧を再取得する」修正が G1-1/G1-6 の競合を生み、G1 の競合修正が
G2-1 の別の競合を生んだ）。G2 で `resolve()` の再取得経路を `fetchIntents()` 一本に統一したことで、
この種の競合クラス自体を解消した。詳細: `docs/reviews/feat/r3-0b-push-intent-ui/G1.md`・`G2.md`・`G3.md`

### 修正した指摘（PR 上で Resolve 済み）

| ID | bot | 重大度 | 内容 | コミット |
|---|---|---|---|---|
| R1-1 | 独立レビュー | Medium | export完了後に一覧が再取得されず主要動線が機能しない | 426c568 |
| G1-1/G1-6 | Codex/Copilot | Medium | 解除POSTと再取得GETの競合で解除済み行が復活しうる | f1e6b88 |
| G1-3/G1-4 | Codex/Copilot | Medium/P3 | order行にdate_created（受注日時）が表示されていない | f1e6b88 |
| G1-5 | Copilot | Medium | 再取得失敗時に既存一覧がエラーで丸ごと消える | f1e6b88 |
| G1-7 | Copilot | Medium | 404（既に解除済み）のとき行が永久に消せない | f1e6b88 |
| G1-9 | Copilot | Low | isBusyが押していないボタンにも付く | f1e6b88 |
| G2-1 | Codex | Medium | G1修正が新たな競合（新規intentの取りこぼし）を生んだ | ef716e7 |
| G2-2 | Copilot | Medium | 一覧が空配列のときエラーが埋もれる | ef716e7 |
| G2-3 | Copilot | Low | backlogに修正済み項目が未起票のまま残存 | ef716e7 |
| G3-1/G3-3 | Codex/Copilot | P2/Low | 設計ドキュメントの解除挙動の記述が実装と食い違う | af27464 |
| G3-2 | Copilot | **High** | mock-adapterテンプレートの`$seed`型チェック漏れ（壊れたオプションで全リクエスト停止） | af27464 |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-2 | Codex | P2/Medium相当 | 「未作成として解除」への確認チェックボックス要求。`docs/03-design-decisions.md` §10.2 D21-Bの「残る制限」に実装前から明記済みの受容済みリスクであり、計画承認済みの設計どおり（対応不要と判断） | [r4116002135](https://github.com/artisanworkshop/cart-bridge-jp/pull/80#discussion_r4116002135) |
| G1-8（残り） | Copilot | Medium | import実行中の解除ボタン無効化。文言は修正済みだが、本体対応にはプラットフォーム単位の進行中run検出（R3-0i、issue #70/#57。別タスクとして計画済み）が必要で本PRの範囲を超える | [r4116003326](https://github.com/artisanworkshop/cart-bridge-jp/pull/80#discussion_r4116003326)（保留として返信済み、未Resolve） |

### review-loop backlog（`docs/review-backlog.md`。今回は対応しない）

- `r3-0b-push-intent-ui/R1-L4`: `exists===false`時の型（`details`が配列 `[]` になる）とTS型定義の不一致（実害なし）
- `r3-0b-push-intent-ui/R1-L7`: `.claude/skills/verify-with-mock-adapter/SKILL.md`に`push`シード項目の説明が無い（テンプレートのdocblockにはある。2026-09-28にObsidianノートには反映済み）

## 品質ゲート

- CI: [run](https://github.com/artisanworkshop/cart-bridge-jp/actions) green（最終HEAD c81ec3b）
- 品質チェック: `composer lint && composer analyze && composer test:wpenv`（PHPUnit 1200件）・
  `npm run lint && npm run build` すべて green（実装完了時点で確認。以降のG1〜G3の修正はTS/PHPテンプレート/
  docsのみで、都度該当するチェック（`npm run lint`/`build`、`composer lint`）を通した）
- wp-env 実機確認（`/verify-with-mock-adapter`、platform key `mockv`）: push=disabled で
  `UnsupportedOperationException`／`create_failure=ambiguous_5xx`でintentが残る→以後のexportがブロック
  （`skipped`+`PUSH_OUTCOME_UNCONFIRMED`）→一覧に反映→`not_created`解除後の再exportで作成される、を
  `rest_do_request()`経由で確認。UI目視確認（実colorme接続に手動でintent行を挿入）でNotice・表・
  編集リンク・「未作成として解除」の押下（フリーズなし・行が消える）・remote_id入力によるボタン活性化を確認。
  検証データは全て撤去済み（`mock-adapter.sh inspect`で手順1の状態に復帰したことを確認）

## 次にできること（人間の判断）

- 保留分の判断: G1-2（確認チェックボックス要否）・G1-8（import実行中のボタン無効化、issue #70/#57待ち）は
  設計判断として据え置いた。異なる判断を希望する場合は指示いただければ追加対応する
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`（issue #73 のクローズ、CLAUDE.mdへの知見蒸留を含む）
