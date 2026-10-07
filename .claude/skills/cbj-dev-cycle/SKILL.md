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
| 品質チェック | `.claude/skills/cbj-dev-cycle/scripts/quality.sh`（= `composer lint` → `composer analyze` → `composer test:wpenv` → `npm run lint` → `npm run test:js` → `npm run i18n:check`〈ビルドしてから、ソースの文字列とコミット済みの POT が同じか確かめる〉） |
| ブランチ命名 | `feat/{タスクID小文字}-{短い説明}`（例: `feat/f1-7-tools-verification-report`）。バグ対応は `fix/{issue番号}-{短い説明}` |
| 計画ドキュメント | `docs/10-tasks.md`（着手タスクはここから。隣接タスクのまとめ方は同ファイル「進め方」2 と memory の PR/branch grouping） |
| 設計ドキュメント | `docs/03-design-decisions.md`（他と矛盾したらこちら優先）、`docs/00〜04`、`docs/20`（v2.0 検討事項） |
| 絶対ルール | `CLAUDE.md` の「コーディング規約」「アーキテクチャ原則」全項目に加え、触るファイルに対応する `.claude/rules/*.md`（カラーミー固有・WooCommerce API 固有・Sync/Export・フロントエンドの落とし穴。パス指定で読み込まれる。索引は CLAUDE.md 末尾）。レビューでは第一級項目として扱う |
| レビュー基準 | `docs/review-criteria.md` は無い → `review-loop` の重大度定義。`docs/review-baseline.md` / `docs/review-backlog.md` を必ず読む |
| PR 本文 | 「対応フェーズ / 変更概要 / テスト内容 / 設計ドキュメントからの逸脱 / review-loop サマリ」（日本語）+ システムプロンプト指定の署名 |
| ローカル環境 | wp-env。**ポートは `.wp-env.json` で固定（dev 10010 / tests 10011。dev-env スキルの台帳のスロット 01）**（下記） |
| 状態ファイル | `docs/reviews/<ブランチ>/dev-cycle.md`、各ラウンドは `R<n>.md` / `G<n>.md` / `final-report.md`。**ブランチ名のスラッシュはディレクトリ階層としてそのまま使う**（`fix/15-foo` → `docs/reviews/fix/15-foo/`。ハイフンに潰すとラウンド自動判定が既存記録を見つけられない） |

## Step 0 の追加事項（環境）

- `npx wp-env`（グローバルの `wp-env` は使わない）。`docker ps --format '{{.Names}}\t{{.Ports}}'` で
  このリポジトリのインスタンス（10010/10011）が起動しているか確認する。ポートは `.wp-env.json` で固定済みなので、
  `WP_ENV_PORT=` の前置も `.wp-env.override.json` も使わない。起動が `port is already allocated` で失敗したら、
  dev-env スキルの `ports.js check` で誰がそのポートを持っているかを確かめる（別プロジェクトのコンテナは止めない）。
- **旧手順の `.wp-env.override.json` が残っていないか確認する**（2026-10-01 以前のクローン）。以前はこのファイル
  （gitignored）に `{ "port": 8895, "testsPort": 8896 }` 等を書いていた。wp-env は override を `.wp-env.json` より
  優先するため、残っていると旧ポートのまま起動する:

  ```bash
  [ -f .wp-env.override.json ] && cat .wp-env.override.json
  ```

  中身が `port` / `testsPort` だけならファイルごと消す。ほかの設定もあれば `port` / `testsPort` のキーだけ消す。
  旧ポートで起動中なら `npx wp-env stop` → `npx wp-env start` で切り替え、`docker ps` で `*-wordpress-1` が
  10010、`*-tests-wordpress-1` が 10011 になったことを確かめる。
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
- **状態ファイル（`dev-cycle.md` のログ）・R/G の記録に書く時刻は推測で書かない**。`TZ=Asia/Tokyo date '+%H:%M'` か、該当コミットの時刻（`git log -1 --format=%cd --date=format-local:%H:%M <sha>`）を使う。見込みの時刻を書くとレビューより後の時刻が残り、Copilot に「記録が不正確」と指摘される（PR #89 G1-B1）

## Step 3（review-loop）の追加事項

- R1 では自己レビューに加えて、**フレッシュコンテキストの独立サブエージェント**（`general-purpose`、opus）に
  `git diff main...HEAD` をファイルへ書き出して渡し、`CLAUDE.md`・**変更ファイルに対応する `.claude/rules/*.md`**・
  `docs/review-baseline.md`・`docs/review-backlog.md` を読ませたうえで敵対的レビューをさせる
  （パス指定ルールがサブエージェントに自動適用されるかはドキュメントに記載が無いため、プロンプトで明示的に読ませる）（R1 では自己レビューが見落とした High を複数検出した実績あり）。
  R2 でも同様に「R1 指摘の解消判定 + 新規混入のみ」を検証させる。
- ボットの指摘同様、サブエージェントの重大度も鵜呑みにせず、実ソース（`~/.wp-env/<hash>/woocommerce`、
  `~/.wp-env/<hash>/WordPress`）や `wp eval` の実測で裏取りしてから判定する（PR #80: 独立レビューが「`??` の isset 意味論で
  `$seed` が非配列でも安全」と断定したが、`stdClass` では Error になり Copilot が正しかった。断定は `php -r` で実測する）。
- **独立レビューが出した Low のうち、画面の状態遷移・エラー時の表示・非同期の競合に関わるものは、数行で直せるなら R1 で直してよい**
  （review-loop 本体の「R1 の Low は直さない」の、このリポジトリでの例外）。bot はこの種の Low を再指摘して Medium に格上げする傾向がある
  （PR #80: R1/R2 で backlog に送った Low 5 件〔404 時の行の扱い・受注日時の表示・ボタンの `isBusy`・文言・再取得失敗時の一覧〕が G1/G2 で
  Copilot に Medium として再指摘され、ゲートが 3 ラウンドかかった）。直したら R1.md には「Low → 修正済み」と記録する。直すのが大きい Low、
  画面・非同期に関わらない Low は従来どおり backlog へ送る。

## Step 4〜7（push / CI / ボットゲート）はスクリプトで行う

以下の `scripts/` は `.claude/skills/cbj-dev-cycle/scripts/` の略記。**リポジトリルートから** `.claude/skills/cbj-dev-cycle/scripts/<name>.sh` として実行する（例: `.claude/skills/cbj-dev-cycle/scripts/ci-wait.sh 34`）。

| 目的 | コマンド | 備考 |
|---|---|---|
| ターン開始側をまとめて実行 | `scripts/gate-turn.sh <PR> <codex\|copilot> [--first] [--timeout=秒] [--ci-timeout=秒] [--no-ci-wait]` | CI green を待つ → 依頼 → 応答を待つ → 新規スレッド・整形した本文・Codex のコメントを続けて表示する（下の個別スクリプトを呼ぶだけの薄いラッパー）。`run_in_background`。**外側の `timeout` は `(--ci-timeout + --timeout + 60) × 1000` ミリ秒以上にする**（CI 待ち〈既定 600 秒〉と応答待ち〈既定 900 秒〉は直列なので既定なら **1560000**。短いと遅い CI や遅れて届いたレビューの途中で打ち切られ、指摘の取得もタイムアウトの報告もされない。起動時に必要な値を `budget:` 行で出力する）。Bash ツールの上限に収まらないときは、CI 待ちを別に実行して `--no-ci-wait` を使うか、`--timeout` / `--ci-timeout` を短くして分割する。標準出力の `T=<依頼時刻>` を以降の `gate-threads.sh`/`gate-bodies.sh` に使う（`gate-reply.sh` に渡すのは T ではなく、`gate-threads.sh` が返すコメントの `dbid`）。**CI が green でなければ依頼せず** exit 2（判定不能・API エラーは 3、応答待ちのタイムアウトは 1 で指摘は表示する）。数値オプションは先頭が 0 でも 10 進数として扱う（`--ci-timeout=0600` は 600）。`--first` は最初の Codex ターン専用（`bot-request.sh` を呼ばず自動レビューを待ち、`--codex-nudge=300` を付ける。T は CI 待ちより前に取る。`bot-wait.sh` は提出時刻だけで応答を判定するため、CI 待ちの間に PR の HEAD が動くと旧 HEAD への自動レビューが待ちを満たしてしまう。そこで CI 待ちの前後で HEAD を比べ、動いていたら待たずに exit 2 で終わる → `--first` を外して再実行し、新 HEAD に `@codex review` を依頼する。届いていた自動レビューは依頼 1 回目として数える）。レビューの commit_id は照合しないため、PR 作成後に別のコミットを push してから起動する場合は `--first` を使わず通常ターンにする。修正・commit・返信・Resolve・サマリコメントは判断を含むので対象外。回帰テストは `scripts/test-gate-turn.sh` |
| CI 待ち | `scripts/ci-wait.sh <PR>` | Bash `run_in_background`（timeout 600000）で実行し、完了通知を待つ。push 直後の古い HEAD を掴まず（ローカルの HEAD に PR が追いつくまで待つ）、後続の push で run が cancelled になったら新しい HEAD を監視し直す。"no checks reported" は再試行する（PR #48 の G2 で、古い HEAD の check-run を見て早期に抜けた）。**終了コードは `${PIPESTATUS[0]}` で見る**（`| tail` を付けると `tail` の 0 になる） |
| ボット依頼 | `T=$(scripts/bot-request.sh <PR> [both\|copilot\|codex])` | 標準出力が依頼時刻 T。状態ファイルに記録する。Codex は PR 作成（ready）時に自動でレビューし、2 回目以降は `@codex review` コメントで再依頼する。初回は自動レビューを応答として待ってよいが、**自動レビューが発火しないことがある**（PR #37 実績: 15 分 TIMEOUT → `@codex review` で 3 分弱で応答）。G1 で Codex だけが TIMEOUT した場合は、ユーザー確認を待たずに次ラウンドで `@codex review` により再依頼してよい（この場合も Codex の依頼回数は 1 回目として数える）。**Copilot の登録は `bot-request.sh` が確認しない**（確認方法は下の「同時依頼の最初のラウンド G1」の 4） |
| 応答待ち | `scripts/bot-wait.sh <PR> <T> [--copilot=0\|1] [--codex=0\|1] [--timeout=900] [--codex-nudge=秒]` | `run_in_background`（timeout 960000）。DONE/TIMEOUT。**G1 では `--codex-nudge=300` を付ける**: Codex の自動レビューが 5 分以内に応答しなければ `@codex review` を 1 回だけ自動投稿して待ち続ける（15 分待ち切ってから再依頼する無駄を省く。Codex への依頼 1 回として数える）。**G2 以降は付けない**（`bot-request.sh` が既に `@codex review` を投稿しているため二重依頼になる） |
| 新規スレッド取得（系統 A） | `scripts/gate-threads.sh <PR> <T>`（`--json` で生データ） | 未解決 かつ T 以降 かつ bot 起票のみ。`id=` が threadId、`dbid=` が返信用 |
| レビュー本文の指摘（系統 B） | `scripts/gate-bodies.sh <PR> <T>`（`--raw` で本文そのまま） | 判定見出し・インライン件数・`Findings`（重要度別）・`Open`（既存スレッドの dbid と `· New`）・**`Previously missed`（スレッドの無い新規指摘）**・`Suppressed comments` を抽出。**系統 A と必ず両方見る**（下記）。整形の回帰テストは `scripts/test-gate-bodies.sh`（ネットワーク不要。`quality.sh` と CI の `dev-tooling` ジョブで実行される） |
| 返信 | `scripts/gate-reply.sh <PR> <dbid> "<本文>"`（本文 `-` で標準入力） | 修正・保留どちらも**日本語**で返信。コミット sha を含める |
| Resolve | `scripts/gate-resolve.sh <threadId>...` | **修正したスレッドのみ**。保留は返信だけして未解決のまま残す |
| ラウンド記録の生成 | `scripts/gate-record.sh <init\|check\|summary\|replies\|approve> <PR> <n> [--since=T] [--force] [--auto-commit] [--file=PATH] [--out=DIR]` | `G<n>.md` を**唯一の情報源**にして、同じ内容を記録・スレッド返信・サマリコメントの 3 回書き直さないためのツール。**`init`**: PR・HEAD・レビューへのリンク・スレッドごとの ID/場所/dbid/URL・収束表の件数を埋めた骨組みを `docs/reviews/<branch>/G<n>.md` に作る（判断が要る欄は `TODO(記入)`。`--since` は `gate-threads.sh` の T で、付けるとレビューを T 以降の提出分として拾い対象 commit を併記する。既存の記録は `--force` なしで上書きしない）。**指摘を取得した直後（修正の前）に実行する**のが確実（修正を push した後だと、修正済みスレッドは outdated になって行番号が `?` になる）。**`approve`**: 確認ゲート（AskUserQuestion）を通した印 `- 承認: <UTC 時刻>` を `- PR:` 行の直後に書く（`--auto-commit` は `- 承認: auto-commit`。既存の承認は `--force` なしで上書きしない）。**ユーザーが承認した直後・`git commit` の前に実行する**。`check`/`summary`/`replies` は、判定が「修正」の指摘があるとき、承認行が無い・読めない、または修正の指摘が挙げた commit の committer 時刻が承認より前だと非ゼロで止める（下の「確認ゲート → commit の順序」）。**記入**: 各指摘の `要旨:` `判定:`（**修正〈修正済みも可〉／保留／対応不要**のどれかで始める。「修正不要」「保留中」のように漢字・かなが続く語は拒否する）`対応:` `コミット:` を書く（本文指摘は `### [G<n>-B1][Copilot][path:line]` を手で足し、`スレッド: なし（[review <id>](…#pullrequestreview-<id>)）` と書くとサマリの表に元のレビューへのリンクが出る。**指摘が 0 件のラウンド**は、最初の指摘の見出しより前に `指摘なし: <確認した内容>` と書いて明示する。既存の G ファイルの書式 `**対応:**` 等も読める。本文に `TODO(記入)` という文字列そのものは書けない）。**`check`/`summary`/`replies`**: 記入漏れ（`TODO(記入)` の残り・判定なし/不明・対応なし・修正なのに sha なし/存在しない sha・要旨なし・**記録の PR 番号（`- PR: #<n>`）とラウンド（`# ゲートラウンド G<n>`）が引数と違う〈`--file` で別 PR の記録を渡して別 PR へ返信するコマンドを作らないため〉**・修正した指摘があるのに検証欄が空・見出しの崩れ（`####` や `##` など階層違いの指摘マーカーも）・ID や thread の重複・スレッドの欄が無い/壊れている〈`discussion_r<dbid>` のリンクか、本文指摘を示す `なし` で始まる値のどちらかが必須〉・閉じていない HTML コメント／コードフェンス〈フェンスは開きと同じ文字で同じかそれより長い区切りでだけ閉じる〉）を非ゼロで止め（sha はローカルに存在するかだけ確認する）、記入途中のものを PR に投稿させない。**`summary`** は PR のサマリコメント本文（各行にスレッドの `#discussion_r<dbid>` リンク列つき）を標準出力へ（ファイルに書いて `gh pr comment <PR> --body-file`）、**`replies`** はスレッドごとの返信ファイルと `gate-reply.sh`/`gate-resolve.sh` の実行コマンドを表示する（**投稿・Resolve は自動でしない**。対応不要のスレッドの Resolve コマンドは、ユーザー承認を得たうえで判定に固定の印 `【承認済み】` を書き添えたときだけ出す。「未承認」「承認待ち」などの語や HTML コメント内の語では出さない。記録に無い未解決スレッドは stderr に通知する）。回帰テストは `scripts/test-gate-record.sh`（`quality.sh` と CI の `dev-tooling` ジョブで実行される） |

- **同時依頼（既定。`sequential` でない）の最初のラウンド G1 の手順**（`gate-turn.sh --first` は Codex 専用で両 bot の同時依頼には使えない。この順序は PR #76 で確認した）:
  1. 初回 push は `~/.claude/skills/dev-cycle/scripts/gate-round.sh push` で行い、出力の `T=`（**PR 作成前**の UTC 時刻）を G1 の `bot-wait.sh` / `gate-threads.sh` / `gate-bodies.sh` / `gate-record.sh init --since` に使う。`bot-request.sh` が返す T（CI 待ちの後）だと、それより前に届いた Codex の自動レビューを応答と数えられず、待ち切るか `@codex review` を余分に投稿することになる
  2. `gh pr create` → `ci-wait.sh <PR>`（background）で green を確認する
  3. `bot-request.sh <PR> copilot`（**`both` にしない**。Codex は PR 作成時の自動レビューを待ち、`both` は `@codex review` を追加投稿して二重依頼になる）
  4. **Copilot の登録を確かめる**: `gh api repos/{owner}/{repo}/issues/<PR>/timeline --jq '.[] | select(.event=="review_requested") | "\(.created_at) \(.requested_reviewer.login)"'` に `Copilot` の行が出れば登録済み（PR #76 ではほぼ即時）。GraphQL の `pullRequest.reviewRequests.nodes.requestedReviewer`（`... on Bot { login }` が `copilot-pull-request-reviewer`）でも確認できる。**`gh pr view --json reviewRequests` と REST `pulls/<PR>/requested_reviewers` は、登録済みでも空を返す**（PR #76 で実測）ので、空だけで未登録と決めつけて二重に依頼しない
  5. `bot-wait.sh <PR> <手順 1 の T> --copilot=1 --codex=1 --codex-nudge=300`（background、timeout 960000）。Codex の応答はレビューではなく **issue コメント**（「Didn't find any major issues」）で届くことがあり、`gate-threads.sh`/`gate-bodies.sh` には出ない。`gh api "repos/{owner}/{repo}/issues/<PR>/comments?since=<T>"` の `chatgpt-codex-connector[bot]` を見る（`gate-turn.sh` はこれも自動で表示する）。**Codex は「Codex Review Summary」の issue コメントで応答することもある**: 同じコメントが状態 Running → Completed に書き換わり、指摘が無ければ PR に 👍 が付く（PR #105 G1）。`bot-wait.sh` は Running の時点でこのコメントを応答とみなして DONE になるので、Completed（またはレビュー・スレッド）になるまで待ってから指摘を取得し、収束を判定する。Copilot の指摘で `gate-record.sh init` した後に Codex が Completed になったら、記入前に `init --force` で作り直す（Codex のスレッドが記録に入らないため。PR #106 G1）
  6. G2 以降は未収束の bot だけ `gate-turn.sh <PR> <copilot|codex>`（`--first` は付けない）
- **初回 push（PR 作成前）に含める状態ファイル `dev-cycle.md` は、PR 作成後も偽にならないように書く**: 「PR: 未作成」「現在のステップ: 4（PR 作成前）」のまま push すると、Copilot が「PR 作成済みなのに古い記録」と指摘する（PR #76 G1-1。修正・返信・Resolve・再依頼で余分な 1 ラウンドを使った）。初回版は「PR: 作成後に番号を記入」「現在のステップ: 4〜5（PR 作成・CI 待ち）」のように書き、番号は G1 の記録コミットで入れる。PR 作成後に追加 push して直すと、HEAD が動くため `gate-turn.sh --first` が使えなくなる
- **PR に投稿する本文（サマリ・スレッド返信）と、その元になる `G<n>.md` の `再依頼:`・要旨・対応の欄に、`@codex` という文字列を書かない**。バッククォートで囲んでも Codex が反応し、約 10 秒後に「To use Codex here, create an environment for this repo」という定型コメントを PR に残す（直近 45 PR の issue コメントで実測: `@codex review` だけの 114 件は 0 件、`@codex` を含む長い本文 13 件中 9 件、含まない 152 件は 0 件）。「Codex へ review コメントを自動投稿」のように書く。再依頼コメントそのもの（本文が `@codex review` だけ。`bot-request.sh` と `bot-wait.sh --codex-nudge` が投稿する）は対象外
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
- **判定が「🟡 Changes recommended」で `Findings: None`・インライン 0 件でも、見出し直下の一文がそのまま指摘のことがある**（PR #89 G1: 「状態ファイルの時刻がレビュー時刻より後」という一文だけだった）。`gate-bodies.sh` の整形出力はこの一文を出さないので、判定が 🟡/🔵 のときは `--raw` で見出しの直下を読み、指摘なら `G<n>-B<k>` として仕分ける。
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
  記録・スレッドへの返信・サマリコメントは `scripts/gate-record.sh` で 1 つの記録から作る（`init` で骨組み〈指摘を取得した直後・修正の前〉→ 修正（commit しない）→
  確認ゲート → **`approve`** → `git commit` → `判定:`/`対応:`/`コミット:` を記入 → `check`〈**push の前**〉→ push → `replies` の返信ファイルとコマンドで
  `gate-reply.sh`/`gate-resolve.sh` → `summary` を `gh pr comment --body-file` で投稿）。
- コード変更の無い指摘（PR 本文の更新など）を「修正」で記録するときは、`コミット:` にレビュー対象の HEAD を挙げる（`check` は対象 HEAD の祖先について承認の順序を検査しないので通る。PR #92 G3）。
- **確認ゲート → commit の順序は `gate-record.sh approve` で機械的に検査する**。「commit の前に確認する」は、メモや手順書に書いても PR #79 の G1、PR #80 の G1〜G3 の
  計 4 回、先に commit された。構造的な原因は、`check` が「修正」の指摘に commit の sha を要求すること（記録を完成させるには先に commit が要る、と手が動く）。
  順序: ① 修正して品質チェックを green にする（**commit しない**）→ ② 修正内容と仕分けを AskUserQuestion で提示 → ③ ユーザーが承認した**直後**に
  `gate-record.sh approve <PR> <n>`（`auto-commit` 指定時は `--auto-commit`）→ ④ `git commit` → ⑤ 記録に sha を書く → ⑥ `check` → ⑦ push。
  記録の記入前に承認が必要な間は、`コミット:` を空のままにしておけばよい（記入は commit の後）。`check` は、修正の指摘が挙げた commit の committer 時刻が
  承認時刻より前なら `predates the approval` で止まる（ただし記録の「対象 HEAD」の祖先の commit は検査しない。レビューが届く前からあった commit ＝
  前のラウンドで直した指摘を bot が再指摘した場合を、判定を偽らずに記録できるようにするため。祖先と確かめられなければ検査する）。
  **push の前に `check` を通す**ので、順序違反は GitHub への返信・Resolve・サマリより前に見つかる。
  見つかったら、未 push の commit を `git reset --soft` で戻し、`approve --force` → commit をやり直す
  （`approve --force --auto-commit` で時刻の承認〔読めない承認行を含む〕を auto-commit に置き換えることは拒否される。1 コマンドで違反が合格に変わってしまうため）。
  承認行の書式は UTC 時刻（`2026-09-28T04:12:33Z`。2000〜2099 年）か `auto-commit` だけで（後ろに付けてよいのは、行末で閉じる括弧の補足だけ）、読めない値は止まる。
  **限界**: これは検出であって防止ではない。`approve` をユーザーの回答より前に実行する、`- 承認:` 行を手で遡った時刻に書き換える、`CBJ_GATE_NOW`
  （テスト用の時刻の上書き。使うと警告する）を本番で使う、といった偽装までは防げない（失敗の原因は「省略」だったので、明示的な `approve` の実行を強制すれば
  足りると判断した）。`git commit --amend`・rebase は committer 時刻を変えるので、承認後に作り直した commit は通る。
  時刻は 1 秒単位なので、承認と同じ秒の commit は正しい順序として通す（`approve && git commit` の連鎖を誤検出しないため。逆順でも同じ秒なら通る）。
  ヘッダに `- 承認:`（`- PR:`）の行が 2 行以上ある記録は、先頭の古い `auto-commit` 行が後ろの時刻承認を隠さないよう、曖昧な記録として止まる。
  対象 HEAD が修正の commit 自身かその子孫のとき（修正を push した後に `init` した、記録を手で書き換えた）も、祖先の除外で検査を飛ばす（`init` は修正の前に実行する。
  除外した commit は `note:` として stderr に出るので目で確かめる）。
  保留・対応不要だけの記録（修正の commit が無いラウンド）は承認行が無くても通る。**導入前の記録**（承認行の無い過去の `G<n>.md`）を `--file` などで
  再検査すると、判定が「修正」のものは承認なしで止まる（open な PR の記録には影響しない）。
- 指摘の仕分けで迷う項目（設計変更・Sync 層のロック等）は確認ゲートの選択肢として提示し、勝手に決めない。
- 3 回目の依頼に対する修正は push して CI を待つが、**4 回目の依頼はしない**。

## Step 8 の追加事項

- `final-report.md` を書いたら状態ファイルを「完了」にして commit・push し、ユーザーへ報告して**停止**（マージしない）。
- マージ後は `/post-merge`。CLAUDE.md への蒸留はそこで行う（PR 中に追加した学びはそのまま残す）。
- 並行する PR が同じファイル（`docs/review-backlog.md` の末尾、`docs/10` など）に追記していると、先にマージされた側のせいで後の PR が CONFLICTING になる。後の PR のブランチに `main` を**マージ**して両方を残す（rebase・force push はしない）。取り込むのがレビュー済みの変更だけなら bot へは再依頼しない（PR #90／#92）。
- **最終報告後・マージ後の追加作業は、bot への依頼・修正・push の各段階の前に `gh pr view <PR> --json state` で OPEN を確認する**。ユーザーが先にマージしていることがある（PR #81: マージの 1 分後に Copilot へ 4 回目を依頼し、G5 の修正をマージ済みのブランチへ push して main に入らなかった）。マージ済みなら依頼・push をせず、必要な修正は main から新しいブランチを切って cherry-pick するフォローアップ PR で入れる（PR #82）。
- Copilot は依頼上限（3 回）の後や最終報告の後でも、依頼していないレビューを返すことがある（PR #81 の HEAD `ea081d7`、PR #82 の `e48e032`。原因は未確認）。最終報告の前後に、最終 push の T 以降について `scripts/gate-threads.sh <PR> <T>` と `scripts/gate-bodies.sh <PR> <T>` で、新しい本文・スレッドが無いか確認する（リポジトリ側の `gate-threads.sh` に `status` サブコマンドは無い。`status` はグローバルの fix-copilot-review 版のもの）。

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
- **確認ゲート**は既定と同じくターンごとに必ず発生する。`auto-commit` 指定時のみ飛ばせる。順序（確認 → `gate-record.sh approve` → commit → `check` → push）も既定と同じ。
- **TIMEOUT・Copilot の依頼が登録されない場合**の扱いは既定（Step 4〜7 の表）と同じ。TIMEOUT で「待たずに
  進める」を選んだ時は、そのボットを「未確認」として記録し、依頼回数には数えたまま次のターンへ進む。
