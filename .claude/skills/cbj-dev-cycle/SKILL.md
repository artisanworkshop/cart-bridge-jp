---
name: cbj-dev-cycle
description: >
  Cart Bridge JP 専用の開発サイクル。グローバルの `dev-cycle`（計画→ブランチ→実装→review-loop→PR→CI→
  Codex/Copilot ゲート→最終報告）を、このリポジトリの規約・環境（docs/10-tasks.md のタスク台帳、
  wp-env のポート固定、`composer test:wpenv`、Codex は PR 作成時に自動レビュー・再依頼は `@codex review` コメント、レビュー返信は日本語）
  と同梱スクリプト（bot-request / bot-wait / ci-wait / gate-threads / gate-bodies / gate-reply / gate-resolve / quality）で
  具体化したもの。グローバル同様 `sequential` を付けると Codex → Copilot → Codex … と1体ずつ順番に
  ゲートを回す（既定は同時依頼）。「cbj-dev-cycle」「次のタスクを進めて」「F1-8 を実装して PR まで」
  「ゲートラウンドを回して」などと言われたら、`dev-cycle` の代わりにこちらを使う。人間の判断が必要な場面
  （計画承認・各ゲートラウンドの commit 判断・想定外の事象）では必ず停止する。
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
| 絶対ルール | `CLAUDE.md` の「コーディング規約」「アーキテクチャ原則」全項目に加え、触るファイルに対応する `.claude/rules/*.md`（カラーミー固有・WooCommerce API 固有・Sync/Export・フロントエンドの落とし穴。パス指定で読み込まれる。索引は CLAUDE.md 末尾）。レビューでは第一級項目として扱う |
| レビュー基準 | `docs/review-criteria.md` は無い → `review-loop` の重大度定義。`docs/review-baseline.md` / `docs/review-backlog.md` を必ず読む |
| PR 本文 | 「対応フェーズ / 変更概要 / テスト内容 / 設計ドキュメントからの逸脱 / review-loop サマリ」（日本語）+ システムプロンプト指定の署名 |
| ローカル環境 | wp-env。**ポートは `.wp-env.override.json`（gitignored）で固定する**（下記） |
| 状態ファイル | `docs/reviews/<ブランチ>/dev-cycle.md`、各ラウンドは `R<n>.md` / `G<n>.md` / `final-report.md`。**ブランチ名のスラッシュはディレクトリ階層としてそのまま使う**（`fix/15-foo` → `docs/reviews/fix/15-foo/`。ハイフンに潰すとラウンド自動判定が既存記録を見つけられない） |

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
  （実装詳細の小節。設計変更は計画承認で合意済みのものだけ）、`docs/review-backlog.md`、`CLAUDE.md` / `.claude/rules/*.md`
  （毎セッション効くハマりどころのみ。手順ものは書かない。**領域固有の落とし穴は該当する `.claude/rules/*.md`**
  〔`adapters-colorme` / `woocommerce-api` / `sync-export-tools` / `frontend`〕へ、どの領域にも効く汎用規約とアーキテクチャ原則だけ
  CLAUDE.md へ。CLAUDE.md を再び肥大化させない）。
- 実機確認は wp-env の dev サイトに対して `npx wp-env run cli wp eval-file <repo内の一時PHP>` で REST を
  `rest_do_request()` から通す。ライブの OAuth 接続なしで Tools/Import/Export の REST・UI を確認する手順
  （mock アダプタの mu-plugin・修正前データの再現・検証・完全撤去）は **`verify-with-mock-adapter` スキル**に
  まとめてある。一時ファイルはコミット前に削除する。
- 管理画面（React）の目視確認が必要なときは、**ログインをユーザーに依頼する**（アシスタントはパスワードを入力できない）。
  ビルドし直した後は **cmd+r でリロードする**（ハッシュだけが違う URL への `navigate` は SPA を再読込せず、古いバンドルが残る）。
- コミットは「backend（tests 込み）/ frontend / docs」程度の論理単位に分ける。

## Step 3（review-loop）の追加事項

- R1 では自己レビューに加えて、**フレッシュコンテキストの独立サブエージェント**（`general-purpose`、opus）に
  `git diff main...HEAD` をファイルへ書き出して渡し、`CLAUDE.md`・**変更ファイルに対応する `.claude/rules/*.md`**・
  `docs/review-baseline.md`・`docs/review-backlog.md` を読ませたうえで敵対的レビューをさせる
  （パス指定ルールがサブエージェントに自動適用されるかはドキュメントに記載が無いため、プロンプトで明示的に読ませる）（R1 では自己レビューが見落とした High を複数検出した実績あり）。
  R2 でも同様に「R1 指摘の解消判定 + 新規混入のみ」を検証させる。
- ボットの指摘同様、サブエージェントの重大度も鵜呑みにせず、実ソース（`~/.wp-env/<hash>/woocommerce`、
  `~/.wp-env/<hash>/WordPress`）や `wp eval` の実測で裏取りしてから判定する。

## Step 4〜7（push / CI / ボットゲート）はスクリプトで行う

以下の `scripts/` は `.claude/skills/cbj-dev-cycle/scripts/` の略記。**リポジトリルートから** `.claude/skills/cbj-dev-cycle/scripts/<name>.sh` として実行する（例: `.claude/skills/cbj-dev-cycle/scripts/ci-wait.sh 34`）。

| 目的 | コマンド | 備考 |
|---|---|---|
| ターン開始側をまとめて実行 | `scripts/gate-turn.sh <PR> <codex\|copilot> [--first] [--timeout=秒] [--ci-timeout=秒] [--no-ci-wait]` | CI green を待つ → 依頼 → 応答を待つ → 新規スレッド・整形した本文・Codex のコメントを続けて表示する（下の個別スクリプトを呼ぶだけの薄いラッパー）。`run_in_background`。**外側の `timeout` は `(--ci-timeout + --timeout + 60) × 1000` ミリ秒以上にする**（CI 待ち〈既定 600 秒〉と応答待ち〈既定 900 秒〉は直列なので既定なら **1560000**。短いと遅い CI や遅れて届いたレビューの途中で打ち切られ、指摘の取得もタイムアウトの報告もされない。起動時に必要な値を `budget:` 行で出力する）。Bash ツールの上限に収まらないときは、CI 待ちを別に実行して `--no-ci-wait` を使うか、`--timeout` / `--ci-timeout` を短くして分割する。標準出力の `T=<依頼時刻>` を以降の `gate-threads.sh`/`gate-bodies.sh` に使う（`gate-reply.sh` に渡すのは T ではなく、`gate-threads.sh` が返すコメントの `dbid`）。**CI が green でなければ依頼せず** exit 2（判定不能・API エラーは 3、応答待ちのタイムアウトは 1 で指摘は表示する）。数値オプションは先頭が 0 でも 10 進数として扱う（`--ci-timeout=0600` は 600）。`--first` は最初の Codex ターン専用（`bot-request.sh` を呼ばず自動レビューを待ち、`--codex-nudge=300` を付ける。T は CI 待ちより前に取る。`bot-wait.sh` は提出時刻だけで応答を判定するため、CI 待ちの間に PR の HEAD が動くと旧 HEAD への自動レビューが待ちを満たしてしまう。そこで CI 待ちの前後で HEAD を比べ、動いていたら待たずに exit 2 で終わる → `--first` を外して再実行し、新 HEAD に `@codex review` を依頼する。届いていた自動レビューは依頼 1 回目として数える）。レビューの commit_id は照合しないため、PR 作成後に別のコミットを push してから起動する場合は `--first` を使わず通常ターンにする。修正・commit・返信・Resolve・サマリコメントは判断を含むので対象外。回帰テストは `scripts/test-gate-turn.sh` |
| CI 待ち | `scripts/ci-wait.sh <PR>` | Bash `run_in_background`（timeout 600000）で実行し、完了通知を待つ。push 直後の古い HEAD を掴まず（ローカルの HEAD に PR が追いつくまで待つ）、後続の push で run が cancelled になったら新しい HEAD を監視し直す。"no checks reported" は再試行する（PR #48 の G2 で、古い HEAD の check-run を見て早期に抜けた）。**終了コードは `${PIPESTATUS[0]}` で見る**（`| tail` を付けると `tail` の 0 になる） |
| ボット依頼 | `T=$(scripts/bot-request.sh <PR> [both\|copilot\|codex])` | 標準出力が依頼時刻 T。状態ファイルに記録する。Codex は PR 作成（ready）時に自動でレビューし、2 回目以降は `@codex review` コメントで再依頼する。初回は自動レビューを応答として待ってよいが、**自動レビューが発火しないことがある**（PR #37 実績: 15 分 TIMEOUT → `@codex review` で 3 分弱で応答）。G1 で Codex だけが TIMEOUT した場合は、ユーザー確認を待たずに次ラウンドで `@codex review` により再依頼してよい（この場合も Codex の依頼回数は 1 回目として数える） |
| 応答待ち | `scripts/bot-wait.sh <PR> <T> [--copilot=0\|1] [--codex=0\|1] [--timeout=900] [--codex-nudge=秒]` | `run_in_background`（timeout 960000）。DONE/TIMEOUT。**G1 では `--codex-nudge=300` を付ける**: Codex の自動レビューが 5 分以内に応答しなければ `@codex review` を 1 回だけ自動投稿して待ち続ける（15 分待ち切ってから再依頼する無駄を省く。Codex への依頼 1 回として数える）。**G2 以降は付けない**（`bot-request.sh` が既に `@codex review` を投稿しているため二重依頼になる） |
| 新規スレッド取得（系統 A） | `scripts/gate-threads.sh <PR> <T>`（`--json` で生データ） | 未解決 かつ T 以降 かつ bot 起票のみ。`id=` が threadId、`dbid=` が返信用 |
| レビュー本文の指摘（系統 B） | `scripts/gate-bodies.sh <PR> <T>`（`--raw` で本文そのまま） | 判定見出し・インライン件数・`Findings`（重要度別）・`Open`（既存スレッドの dbid と `· New`）・**`Previously missed`（スレッドの無い新規指摘）**・`Suppressed comments` を抽出。**系統 A と必ず両方見る**（下記）。整形の回帰テストは `scripts/test-gate-bodies.sh`（ネットワーク不要。`quality.sh` と CI の `dev-tooling` ジョブで実行される） |
| 返信 | `scripts/gate-reply.sh <PR> <dbid> "<本文>"`（本文 `-` で標準入力） | 修正・保留どちらも**日本語**で返信。コミット sha を含める |
| Resolve | `scripts/gate-resolve.sh <threadId>...` | **修正したスレッドのみ**。保留は返信だけして未解決のまま残す |
| ラウンド記録の生成 | `scripts/gate-record.sh <init\|check\|summary\|replies> <PR> <n> [--since=T] [--force] [--file=PATH] [--out=DIR]` | `G<n>.md` を**唯一の情報源**にして、同じ内容を記録・スレッド返信・サマリコメントの 3 回書き直さないためのツール。**`init`**: PR・HEAD・レビューへのリンク・スレッドごとの ID/場所/dbid/URL・収束表の件数を埋めた骨組みを `docs/reviews/<branch>/G<n>.md` に作る（判断が要る欄は `TODO(記入)`。`--since` は `gate-threads.sh` の T で、付けるとレビューを T 以降の提出分として拾い対象 commit を併記する。既存の記録は `--force` なしで上書きしない）。**指摘を取得した直後（修正の前）に実行する**のが確実（修正を push した後だと、修正済みスレッドは outdated になって行番号が `?` になる）。**記入**: 各指摘の `要旨:` `判定:`（**修正〈修正済みも可〉／保留／対応不要**のどれかで始める。「修正不要」「保留中」のように漢字・かなが続く語は拒否する）`対応:` `コミット:` を書く（本文指摘は `### [G<n>-B1][Copilot][path:line]` を手で足し `スレッド: なし`。既存の G ファイルの書式 `**対応:**` 等も読める）。**`check`/`summary`/`replies`**: 記入漏れ（`TODO(記入)` の残り・判定なし/不明・対応なし・修正なのに sha なし/存在しない sha・見出しの崩れ・ID や thread の重複・スレッドの欄が無い/壊れている〈`discussion_r<dbid>` のリンクか、本文指摘を示す `なし` で始まる値のどちらかが必須〉・閉じていない HTML コメント／コードフェンス）を非ゼロで止め（sha はローカルに存在するかだけ確認する）、記入途中のものを PR に投稿させない。**`summary`** は PR のサマリコメント本文（各行にスレッドの `#discussion_r<dbid>` リンク列つき）を標準出力へ（ファイルに書いて `gh pr comment <PR> --body-file`）、**`replies`** はスレッドごとの返信ファイルと `gate-reply.sh`/`gate-resolve.sh` の実行コマンドを表示する（**投稿・Resolve は自動でしない**。対応不要のスレッドの Resolve コマンドは、ユーザー承認を得たうえで判定に固定の印 `【承認済み】` を書き添えたときだけ出す。「未承認」「承認待ち」などの語や HTML コメント内の語では出さない。記録に無い未解決スレッドは stderr に通知する）。回帰テストは `scripts/test-gate-record.sh`（`quality.sh` と CI の `dev-tooling` ジョブで実行される） |

- **指摘の取得元は 2 系統ある。片方だけ見て「指摘なし」と判断しない。** Copilot は判定が
  「🔵 Needs a closer look」のとき、インラインコメントを 1 件も投稿せず（`Comments generated: 0 new`）、
  指摘を本文の `Suppressed comments` に畳むことがある（PR #35 の 2 回目のレビューが実例。
  `gate-threads.sh` だけでは 2 件を丸ごと取り逃していた）。毎ラウンド `gate-threads.sh` と
  `gate-bodies.sh` の両方を実行し、`path:line` と要旨で重複排除してから仕分ける。
- **新形式の本文（`ccr-overview-v2`）では、新規指摘がインラインでも `Suppressed comments` でもなく、本文の
  `Previously missed`（変更していないコードへの指摘。スレッド無し）に出ることがある**（PR #61 の G2 が実例: インライン 0 件・
  `Open` は既存スレッドの再掲で、新規 1 件が `Previously missed` にだけあった。旧版の `gate-bodies.sh` は整形出力に
  出さず、`--raw` で読まなければ見落とすところだった）。整形出力の `Previously missed` の各項目は、系統 B の新規指摘として
  `G<n>-<k>` を振って仕分ける。
- **Copilot の最終ラウンドは、過去に返信済み・未解決のスレッドを本文の「Open」に再掲する**ことがある
  （PR #48 の G3: 判定 🔵 Needs a closer look・インライン 0 件で、Open の 2 件は G1-1/G2-1 の重複だった）。
  `gate-bodies.sh` の Open の各項目（`#discussion_r<dbid>`。`· New` の付いた項目は今回の新規スレッドで
  `gate-threads.sh` にも出る）を既存スレッドの `dbid` と突合し、
  既存なら新規ではなく重複として `G<n>.md` に記録する。総評（「データを書き換えるので最終的に人間の確認を」等）は
  修正対象ではなく、最終報告でマージ前にユーザーが確認すべき点として載せる。
- 本文指摘（系統 B）は**スレッドが無いため Resolve できない**。`G<n>.md` と PR サマリコメントの
  記録が唯一の処理済みマーカーになる（記録が無いと、次のラウンドで同じ指摘を再評価することになる）。
- ラウンド記録 `docs/reviews/<ブランチ>/G<n>.md` は `dev-cycle` のフォーマット。ラウンドのサマリは `gh pr comment` で投稿する
  （各スレッドの `#discussion_r<dbid>` リンク、本文指摘は `#pullrequestreview-<id>` リンクと `path:line`、sha を含める）。
  記録・スレッドへの返信・サマリコメントは `scripts/gate-record.sh` で 1 つの記録から作る（`init` で骨組み → `判定:`/`対応:`/`コミット:` を記入 →
  `check` → `replies` の返信ファイルとコマンドで `gate-reply.sh`/`gate-resolve.sh` → `summary` を `gh pr comment --body-file` で投稿）。
- 指摘の仕分けで迷う項目（設計変更・Sync 層のロック等）は確認ゲートの選択肢として提示し、勝手に決めない。
- 3 回目の依頼に対する修正は push して CI を待つが、**4 回目の依頼はしない**。

## Step 8 の追加事項

- `final-report.md` を書いたら状態ファイルを「完了」にして commit・push し、ユーザーへ報告して**停止**（マージしない）。
- マージ後は `/post-merge`。CLAUDE.md への蒸留はそこで行う（PR 中に追加した学びはそのまま残す）。

## 順番実行（`sequential` 指定時）

既定の同時依頼（Step 4〜7）は 1 ラウンドで両ボットに依頼し、両方の指摘をまとめて直す。同じ箇所を別々に
指摘されて二重に直しがちで、後から来たボットは直る前のコードを見ている。`sequential` は **1 体ずつ、前の
ボットの修正が入った HEAD を次のボットに見せる**。Step 4〜7 の同時依頼をこの流れに置き換える
（Step 0〜3・8 と `dev-cycle` の「絶対にしないこと」「人間に確認する条件」はそのまま有効）。

**順序**: Codex → Copilot → Codex → Copilot → Codex → Copilot（各ボット最大 3 回、合計最大 6 ターン）。

**1 ターン** = 1 体のボットに対する「CI 待ち（`ci-wait.sh`）→ 依頼・待ち（`bot-request.sh`/`bot-wait.sh`）→
指摘取得・仕分け・修正・確認ゲート・commit/push・GitHub への反映（`gate-threads.sh`/`gate-bodies.sh`/
`gate-reply.sh`/`gate-resolve.sh`）」。**ターンが完結してから次のターンに進み、他のボットへの依頼を
先に出さない。**

- **ターンの開始側は `gate-turn.sh` で 1 コマンド**: `scripts/gate-turn.sh <PR> codex`（最初の Codex ターンは `--first`）／`scripts/gate-turn.sh <PR> copilot`。
  内部で下の `ci-wait.sh` → `bot-request.sh` → `bot-wait.sh` → `gate-threads.sh`/`gate-bodies.sh` を順に呼ぶ（個別に実行してもよい）。
- **依頼はそのターンのボットだけ**（`gate-turn.sh` を使わず個別に実行する場合）:
  ```bash
  T=$(scripts/bot-request.sh <PR> codex)                          # Codex のターン
  scripts/bot-wait.sh <PR> "$T" --copilot=0 --codex=1              # 最初の Codex ターン（G1）だけ --codex-nudge=300 を追加

  T=$(scripts/bot-request.sh <PR> copilot)                        # Copilot のターン
  scripts/bot-wait.sh <PR> "$T" --copilot=1 --codex=0
  ```
  Bash は同時依頼と同じく `run_in_background`（`ci-wait.sh` は timeout 600000、`bot-wait.sh` は timeout 960000）。
  最初の Codex のターン（G1）は Step 4〜7 の表と同じく PR 作成時の自動レビューを応答として待ってよく、
  発火していなければ `bot-request.sh <PR> codex` で明示依頼する（この場合も依頼 1 回目として数える）。
- **対象スレッドの絞り込み**: `gate-threads.sh`/`gate-bodies.sh` は全ボットのスレッド・本文を返すため、
  そのターンのボットの author（Codex: `chatgpt-codex-connector`、Copilot: `copilot-pull-request-reviewer`）
  だけに絞って仕分ける。`gate-bodies.sh` は Copilot のターンだけ読む。他のボットが前のターンで保留にして
  未解決のまま残したスレッドは判断済みなので対象外。
- **記録**: ターン番号は通し連番（G1 = 1 ターン目）。`G<n>.md` の見出しの下に「bot: Codex（2 回目）」の
  ように書く。指摘 ID は `G<n>-<k>`。状態ファイルに各ボットの依頼回数と「次のターン」を書く。
- **次のターンのボット**は順序で次のボット。ただし次のいずれかなら飛ばして、その次のボットにする:
  - 収束済み（新規指摘が 0 件だった）
  - 依頼回数が 3 に達した
  - そのボットが最後にレビューした HEAD から変わっていない（同じ HEAD への再依頼は同じ指摘を返すだけ）
- **終了**: 飛ばされずに残るボットがいなくなったら Step 8 へ。片方のボットだけが残った場合は、そのボットが
  CI 待ちを挟みながら続けてターンを取る。最後のターンの修正は push して CI を待つが、再依頼はしない
  （既定と同じ）。
- **確認ゲート**は既定と同じくターンごとに必ず発生する。`auto-commit` 指定時のみ飛ばせる。
- **TIMEOUT・Copilot の依頼が登録されない場合**の扱いは既定（Step 4〜7 の表）と同じ。TIMEOUT で「待たずに
  進める」を選んだ時は、そのボットを「未確認」として記録し、依頼回数には数えたまま次のターンへ進む。
