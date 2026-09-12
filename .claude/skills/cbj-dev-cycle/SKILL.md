---
name: cbj-dev-cycle
description: >
  Cart Bridge JP 専用の開発サイクル。グローバルの `dev-cycle`（計画→ブランチ→実装→review-loop→PR→CI→
  Codex/Copilot ゲート→最終報告）を、このリポジトリの規約・環境（docs/10-tasks.md のタスク台帳、
  wp-env のポート固定、`composer test:wpenv`、Codex は PR 作成時に自動レビュー・再依頼は `@codex review` コメント、レビュー返信は日本語）
  と同梱スクリプト（bot-request / bot-wait / ci-wait / gate-threads / gate-reply / gate-resolve / quality）で
  具体化したもの。「cbj-dev-cycle」「次のタスクを進めて」「F1-8 を実装して PR まで」「ゲートラウンドを回して」
  などと言われたら、`dev-cycle` の代わりにこちらを使う。人間の判断が必要な場面（計画承認・各ゲートラウンドの
  commit 判断・想定外の事象）では必ず停止する。
---

# /cbj-dev-cycle — Cart Bridge JP の開発サイクル

グローバルの `dev-cycle` スキルの手順（Step 0〜8、確認ゲート、絶対にしないこと、報告フォーマット）を
**そのまま**適用し、以下の点だけをこのリポジトリ向けに固定する。矛盾する場合はこのファイルが優先。
汎用の説明は繰り返さないので、手順の詳細は `dev-cycle` を参照すること。

## このリポジトリの設定（dev-cycle「プロジェクト設定の読み取り」の確定値）

| 項目 | 値 |
|---|---|
| 品質チェック | `.claude/skills/cbj-dev-cycle/scripts/quality.sh`（= `composer lint` → `composer analyze` → `composer test:wpenv` → `npm run lint` → `npm run build`） |
| ブランチ命名 | `feat/{タスクID小文字}-{短い説明}`（例: `feat/f1-7-tools-verification-report`）。バグ対応は `fix/{issue番号}-{短い説明}` |
| 計画ドキュメント | `docs/10-tasks.md`（着手タスクはここから。隣接タスクのまとめ方は同ファイル「進め方」2 と memory の PR/branch grouping） |
| 設計ドキュメント | `docs/03-design-decisions.md`（他と矛盾したらこちら優先）、`docs/00〜04`、`docs/20`（v2.0 検討事項） |
| 絶対ルール | `CLAUDE.md` の「コーディング規約」「アーキテクチャ原則」全項目（レビューでは第一級項目として扱う） |
| レビュー基準 | `docs/review-criteria.md` は無い → `review-loop` の重大度定義。`docs/review-baseline.md` / `docs/review-backlog.md` を必ず読む |
| PR 本文 | 「対応フェーズ / 変更概要 / テスト内容 / 設計ドキュメントからの逸脱 / review-loop サマリ」（日本語）+ システムプロンプト指定の署名 |
| ローカル環境 | wp-env。**ポートは `.wp-env.override.json`（gitignored）で固定する**（下記） |
| 状態ファイル | `docs/reviews/<ブランチ>/dev-cycle.md`、各ラウンドは `R<n>.md` / `G<n>.md` / `final-report.md` |

## Step 0 の追加事項（環境）

- `npx wp-env`（グローバルの `wp-env` は使わない）。`docker ps --format '{{.Names}}\t{{.Ports}}'` で
  このリポジトリのインスタンスが起動しているか確認する。8888/8889 が別プロジェクトに使われている場合、
  `.wp-env.override.json` に空きポートを書いてから `npx wp-env start` する:

  ```json
  { "port": 8895, "testsPort": 8896 }
  ```

  これで `composer test:wpenv` / `npx wp-env run ...` に `WP_ENV_PORT=` を前置する必要が無くなる。
- `.nvmrc`（Node 20）と `node -v` の一致を確認する。

## Step 1（計画）の追加事項

- 計画には「`docs/03` からの逸脱候補」を必ず列挙する（これがそのまま PR 本文の「設計ドキュメントからの逸脱」になる）。
- `docs/review-backlog.md` の持ち越し項目を本タスクに含めるかを計画で決める（含めないなら別 issue 化を明記）。

## Step 2（実装）の追加事項

- 実装完了時に更新するドキュメント: `docs/10-tasks.md`（チェック + 実装サマリ）、`docs/03-design-decisions.md`
  （実装詳細の小節。設計変更は計画承認で合意済みのものだけ）、`docs/review-backlog.md`、`CLAUDE.md`（毎セッション効く
  ハマりどころのみ。手順ものは書かない）。
- 実機確認は wp-env の dev サイトに対して `npx wp-env run cli wp eval-file <repo内の一時PHP>` で REST を
  `rest_do_request()` から通す（管理画面へのログインは行わない）。一時ファイルはコミット前に削除する。
- コミットは「backend（tests 込み）/ frontend / docs」程度の論理単位に分ける。

## Step 3（review-loop）の追加事項

- R1 では自己レビューに加えて、**フレッシュコンテキストの独立サブエージェント**（`general-purpose`、opus）に
  `git diff main...HEAD` をファイルへ書き出して渡し、`CLAUDE.md`・`docs/review-baseline.md`・`docs/review-backlog.md`
  を読ませたうえで敵対的レビューをさせる（R1 では自己レビューが見落とした High を複数検出した実績あり）。
  R2 でも同様に「R1 指摘の解消判定 + 新規混入のみ」を検証させる。
- ボットの指摘同様、サブエージェントの重大度も鵜呑みにせず、実ソース（`~/.wp-env/<hash>/woocommerce`、
  `~/.wp-env/<hash>/WordPress`）や `wp eval` の実測で裏取りしてから判定する。

## Step 4〜7（push / CI / ボットゲート）はスクリプトで行う

以下の `scripts/` は `.claude/skills/cbj-dev-cycle/scripts/` の略記。**リポジトリルートから** `.claude/skills/cbj-dev-cycle/scripts/<name>.sh` として実行する（例: `.claude/skills/cbj-dev-cycle/scripts/ci-wait.sh 34`）。

| 目的 | コマンド | 備考 |
|---|---|---|
| CI 待ち | `scripts/ci-wait.sh <PR>` | Bash `run_in_background`（timeout 600000）で実行し、完了通知を待つ |
| ボット依頼 | `T=$(scripts/bot-request.sh <PR> [both\|copilot\|codex])` | 標準出力が依頼時刻 T。状態ファイルに記録する。Codex は PR 作成（ready）時に自動でレビューし、2 回目以降は `@codex review` コメントで再依頼する。初回は自動レビューを応答として待ってよい |
| 応答待ち | `scripts/bot-wait.sh <PR> <T> [--copilot=0\|1] [--codex=0\|1] [--timeout=900]` | `run_in_background`（timeout 960000）。DONE/TIMEOUT |
| 新規スレッド取得 | `scripts/gate-threads.sh <PR> <T>`（`--json` で生データ） | 未解決 かつ T 以降 かつ bot 起票のみ。`id=` が threadId、`dbid=` が返信用 |
| 返信 | `scripts/gate-reply.sh <PR> <dbid> "<本文>"`（本文 `-` で標準入力） | 修正・保留どちらも**日本語**で返信。コミット sha を含める |
| Resolve | `scripts/gate-resolve.sh <threadId>...` | **修正したスレッドのみ**。保留は返信だけして未解決のまま残す |

- ラウンド記録 `docs/reviews/<ブランチ>/G<n>.md` は `dev-cycle` のフォーマット。ラウンドのサマリは `gh pr comment` で投稿する
  （各スレッドの `#discussion_r<dbid>` リンクと sha を含める）。
- 指摘の仕分けで迷う項目（設計変更・Sync 層のロック等）は確認ゲートの選択肢として提示し、勝手に決めない。
- 3 回目の依頼に対する修正は push して CI を待つが、**4 回目の依頼はしない**。

## Step 8 の追加事項

- `final-report.md` を書いたら状態ファイルを「完了」にして commit・push し、ユーザーへ報告して**停止**（マージしない）。
- マージ後は `/post-merge`。CLAUDE.md への蒸留はそこで行う（PR 中に追加した学びはそのまま残す）。
