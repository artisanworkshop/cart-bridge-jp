# dev-cycle 最終報告: feat/e2-4-export-ui-e2e

## 開発内容

- タスク: E2-4 エクスポートUI + 往復E2E（Phase 2完了タスク）
- PR: #53 https://github.com/artisanworkshop/cart-bridge-jp/pull/53
- 承認された計画の要約: React `ExportTab.tsx`に`ImportTab.tsx`と対称の実行フロー
  （エンティティ選択→dry-run→本番書込み警告→実行→進捗→結果レポート）を追加する
  フロントエンドのみの実装（PHP変更なし。バックエンドはE2-2/E2-3で完成済み）。加えて
  ColorMeテストショップに対する実機Woo→ColorMe→Woo往復E2Eで、Phase 2完了チェック
  （dry-run→サンプルエクスポート→再エクスポートでchecksum一致skip・重複ゼロ）を確認する。
- コミット一覧（19件、`main`からの差分）:
  - `7f57fb8` feat: add Export tab run flow (E2-4)
  - `cb85636` docs: record E2-4 export UI design decisions and E2E findings
  - `bad9060` fix: add missing CSS for Export tab run-flow cards
  - `4d62701` refactor: compute zero-written-entities banner value before render
  - `52d35a0` fix: R1 review findings in ExportTab run flow
  - `3065a65` docs: fix E2-4 E2E write-up accuracy after R1 review
  - `50ec6b3` docs: record R1 review round for E2-4
  - `a38f0bc` fix: address R2 review findings for E2-4
  - `ffca81a` docs: record R2 review round for E2-4 (APPROVE)
  - `9fd2e88` docs: record PR #53 creation in dev-cycle state
  - `f27c580` docs: record CI green and bot gate request for PR #53
  - `cae297f` fix: address G1 bot gate findings for PR #53
  - `a8e4da7` docs: record G1 gate findings and pending confirmation for PR #53
  - `e612c2a` docs: record G1 gate round for PR #53
  - `2f99c32` docs: record G2 bot gate request for PR #53
  - `ec94dd9` fix: redact remaining real test-shop hostname (G2 Copilot)
  - `c8531c2` docs: record G2 gate round for PR #53
  - `c71a53e` docs: record G3 bot gate request for PR #53
  - `f3e1cb7` fix: discard stale limits response after a new export run starts (G3 Codex)

## 設計ドキュメントからの逸脱

- **本番書込み警告（D17）の実装方式**: `docs/03-design-decisions.md`（D17/§10.4）は確認ダイアログの
  実装手段までは指定していないため厳密な逸脱ではないが、`ImportTab.tsx`の`window.confirm()`とは
  異なり、ExportTabでは常時表示の`Notice`+`CheckboxControl`で「Run export」ボタンをゲートする方式を
  採用した。理由: `.claude/rules/frontend.md`がネイティブ`window.confirm()`はブラウザ拡張の自動操作
  （Claude in Chrome等）をフリーズさせると既に指摘しており、本タスクの実機E2E自体がブラウザ自動操作で
  必要だったため。`docs/03-design-decisions.md` §10.2「E2-4」に記録。ImportTab側は本PRの差分範囲外で
  未変更（`docs/review-backlog.md`の`e2-4-export-ui-e2e/confirm-ux-asymmetry`）。
- 実機E2Eで、実装済みバックエンドの既知の設計判断（`Woo\Reader\CustomerReader::name()`の
  Western/Japanese順の非対称性等）を実地で再現したのみで、設計自体の変更は無い。

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | 自己レビュー2件（Low）+ 独立サブエージェント10件（Medium3・Low6・ドキュメント誤り1） | Medium3件・ドキュメント誤りを修正 | Low6件 + 対象外1件（`LimitsUpsellNotice`表示の既存の誤解を招く挙動） |
| R2 | 独立サブエージェント検証: R1指摘は解消（R1-10のみ一部未解消を追加修正）、新規Low1件 | R1-10の残存箇所を修正、新規Low1件はdocblock追記のみ | Low1件 |

R2でAPPROVE（R1のCritical/High/Medium全解消・新規Critical/Highゼロ）。詳細は`R1.md`・`R2.md`参照。

## Codex / Copilotゲート（同時依頼、各3ラウンド）

| ラウンド | bot | 新規指摘 | 修正 | 保留/誤検知/重複 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 1件（P1・Retryの同一タブ内競合） | 1件 | 0 | 未収束→次ラウンドへ |
| G1 | Copilot | 1件（個人ローカルパス露出） | 1件 | 0 | 未収束→次ラウンドへ |
| G2 | Codex | 1件（P1・`JobManager::retry()`のプラットフォーム単位ガード欠如） | 0 | 1件保留（**人間確認・ユーザー判断**） | 未収束→次ラウンドへ |
| G2 | Copilot | 本文1件（R2.mdの実サブドメイン残存の見落とし） | 1件 | 0 | 未収束→次ラウンドへ |
| G3 | Codex | 1件（P2・`/limits`応答の古さ判定漏れ） | 1件 | 0 | 依頼上限（3回）到達 |
| G3 | Copilot | 2件（`reportsAvailable`誤検知1件・G2-1と重複1件） | 0 | 2件（誤検知1・重複1） | 依頼上限（3回）到達 |

両bot共に依頼回数の上限（3回）に到達。「収束」（新規指摘0件）ではなく「上限到達」で終了。

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Codex | High(P1) | Retryボタンが自分自身の`starting`を見ておらず同一タブ内で新旧runが競合しうる | `cae297f` | [#discussion_r4079660083](https://github.com/artisanworkshop/cart-bridge-jp/pull/53#discussion_r4079660083) |
| G1-2 | Copilot | Low | 開発者ローカルの絶対パス（ユーザー名含む）がコミットされていた | `cae297f` | [#discussion_r4079669747](https://github.com/artisanworkshop/cart-bridge-jp/pull/53#discussion_r4079669747) |
| G2-2 | Copilot | Low | R1で修正したはずの実テストショップのサブドメインが別ファイル（R2.md）に残存 | `ec94dd9` | 本文指摘（スレッド無し） |
| G3-3 | Codex | Medium(P2) | 同一プラットフォーム内で新runが始まった場合`/limits`の古い応答で`LimitsUpsellNotice`が上書きされうる | `f3e1cb7` | [#discussion_r4080696929](https://github.com/artisanworkshop/cart-bridge-jp/pull/53#discussion_r4080696929)（Resolve済み） |

### 修正しなかった指摘（PR上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G2-1 | Codex | High（**保留・ユーザー判断**） | `JobManager::retry()`にプラットフォーム単位のアクティブjobガードが無く、別タブ/別セッションでの並行Retryは依然として二重書き込みのリスクが残る。根本修正はバックエンド変更が必要で本PR（フロントエンドのみ）のスコープを超えるため、ユーザー確認のうえ保留し`docs/review-backlog.md`の`e2-4-export-ui-e2e/G2-codex-retry-platform-wide-guard`に記録（Import側`cancel_run()`の既存backlog`f1-6-import-ui/R1-X1`と同根） | [#discussion_r4080522985](https://github.com/artisanworkshop/cart-bridge-jp/pull/53#discussion_r4080522985) |
| G3-1 | Copilot | 誤検知 | `reportsAvailable`はJSXの真偽値プロパティ省略記法で正しい記述（`ReferenceError`にはならない）。`tsc`/ビルドgreen・実機動作確認済み | [#discussion_r4080694226](https://github.com/artisanworkshop/cart-bridge-jp/pull/53#discussion_r4080694226) |
| G3-2 | Copilot | 重複 | G2-1（Codex）と同一の指摘。同じ理由で保留 | [#discussion_r4080694277](https://github.com/artisanworkshop/cart-bridge-jp/pull/53#discussion_r4080694277) |

## 実機E2E（ColorMeテストショップ）

新規プライベートアプリ＋新規テストショップを作成して接続し、以下を確認（詳細は
`docs/03-design-decisions.md` §10.2「E2-4」・`docs/10-tasks.md`のE2-4エントリ参照）:

1. dry-run export → Preview export results・CSVレポートDLが機能
2. 実export → ColorMe側に商品・顧客が実際に作成されたことをAPI直叩きで確認
3. Importタブで再取込み → 既存mappingにより重複作成されず更新（価格・在庫管理フラグ・
   郵便番号/pref_id・電話番号が正しく往復）。既知の制限（ネイティブWoo顧客の氏名順序非対称）を
   実地で再現（対応不要、既存docblockの想定通り）
4. 再exportで冪等性を確認 → 重複ゼロ達成（checksum一致skipは顧客/在庫で確認、商品は
   カテゴリマッピング未設定により`Updated`を繰り返すが既存remote_idへの上書きのみ）
- 実機E2Eの過程で`LimitsUpsellNotice`の表示文言の不正確さ（対象外・既存コード。
  `e2-4-export-ui-e2e/limits-upsell-message-misleading`）とwp-env固有の落とし穴
  （permalink_structure空によるOAuth callback URL不一致）を発見・記録した。

## 品質ゲート

- CI: green（[最新run](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/35826427122)含め全ラウンドで確認）
- 品質チェック: green（PHPUnit 1076件、`composer lint`/`composer analyze`、`npm run lint`/`npm run build`）

## 次にできること（人間の判断）

- **保留分の判断**: `G2-1`（`JobManager::retry()`のプラットフォーム単位ガード欠如。High、ユーザーが
  今回は保留と判断済み）を別issueとして起票し、Import側の`cancel_run()`分（`f1-6-import-ui/R1-X1`）と
  まとめて対応するかを検討すること
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
- `docs/review-backlog.md`に本PRで新たに記録した項目（G1/G2/G3分含め計10件強）は今後の別タスクで
  棚卸しすることを推奨
