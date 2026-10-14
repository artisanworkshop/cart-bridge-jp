# dev-cycle 最終報告: fix/r3-11-push-intent-link-unreadable

## 開発内容

- タスク: R3-11 — push intent の「リンクして解除」が、読めない実体・登録の無い種類を「存在しない」と答える問題（backlog `r3-0b-push-intent-backend/R1-X1`、High。`docs/03` §2 D20 規則 7 の決着）。`next auto-commit` で起動し、残りの v1.0 のタスク（R3-4b）が販売サイト待ちのため、ユーザーが backlog の High を選んだ
- PR: #136 https://github.com/artisanworkshop/cart-bridge-jp/pull/136
- 承認された計画の要約（`~/.claude/plans/staged-moseying-leaf.md`）:
  - ID 指定取得の null は 404 だけ。接続先が返した項目を読めないときは無料版の新しい `Adapters\RemoteEntityUnreadableException`（ColorMe の商品・Pro の顧客〔非会員・メールの無い行も〕・受注）
  - 解除の理由: 読めない・ID の不一致は 422 `cbjp_remote_entity_unreadable`（Logs に原因つきの 1 行）、登録の無い種類は link・not_created とも 409 `cbjp_entity_type_unavailable` で一覧から外す（ユーザー決定〔案 C〕）
  - 拡張点の版 5・日本語訳・`verify-with-mock-adapter` の 4b／7・docs
- コミット:

| sha | メッセージ |
|---|---|
| 4e86898 | fix: stop reporting unreadable remote items as missing when linking push intents (R3-11) |
| af856b0 | chore(i18n): translate the push intent link errors (R3-11) |
| 1c496e5 | chore(skills): verify unreadable and unregistered push intents with the mock adapter (R3-11) |
| efff36b | docs: record R3-11 (unreadable remote items and unregistered types in push intent linking) |
| cb2c795 | fix: log why a push intent link was refused and reword the unavailable-type error (R3-11) |
| 7b519e4 | chore(skills): count the seed keys that the push intent cleanup removes (R3-11) |
| 9a6ebad | docs: record the R3-11 review-loop round 1 |
| b0c3469 | chore(skills): count every value the push intent cleanup removes (R3-11) |
| 5798be7 | docs: record the R3-11 review-loop round 2 |
| d82080b | fix: escape the values in the unreadable remote item exception message (R3-11) |
| 456a227 | docs: record dev-cycle gate round 1 |
| 2ac8f19 | chore(skills): read back the seed writes in the push intent example (R3-11) |
| 2801731 | docs: record dev-cycle gate round 2 |
| 9678fd9 | chore(skills): count the cleanup leftovers from the database (R3-11) |
| 1dd1048 | docs: record dev-cycle gate round 3 |

- 設計ドキュメントからの逸脱（PR 本文と同じ）:
  1. §10.2: `link` の「接続先に無い」（404）を ID 指定取得が null のときだけにし、読めない・ID の不一致を 422 REMOTE_UNREADABLE にした
  2. §10.0: 登録の無い種類を 409 TYPE_UNAVAILABLE にし、not_created も断り、一覧から外した（ユーザー決定〔案 C〕）
  3. 非会員・メールの無い顧客への紐づけを断る（§9 要検証 #24）
  4. 拡張点の版を 5 にした

## review-loop（PR 前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント opus） | Critical/High/Medium 0・Low 4・対象外 Low 2 | Low 4（画面・エラーの表示に関わる。cbj-dev-cycle の例外） | 2（`r3-11-push-intent-link-unreadable/R1-X1`・`R1-X2`） |
| R2（独立サブエージェントの検証・変異） | R1 すべて解消・新規 Low 1・対象外 2 | 3（新規 Low 1・同じ関数の数え漏れ・コメントの言い回し） | 0 |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留・対応不要 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex（自動レビューが 5 分で応答せず `@codex review` を自動投稿） | 0 | 0 | 0 | 収束（「Didn't find any major issues」） |
| G1 | Copilot（PR 作成時に自動登録） | 2 | 1 | 対応不要 1 | 未収束 |
| G2 | Copilot | 1（本文の Previously missed。Open の 1 件は G1-2 の再掲） | 1 | 0 | 未収束 |
| G3 | Copilot | 1（判定 🔵 の見出し直下の一文。Open の 1 件は G1-2 の再掲） | 1 | 0 | 上限（4 回目は依頼しない） |

### 修正した指摘

| ID | bot | 重大度（自分の判定） | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Low（bot は High） | 新しい例外の文の値を `esc_html()` に通す | d82080b | [r4265418627](https://github.com/artisanworkshop/cart-bridge-jp/pull/136#discussion_r4265418627)（Resolve 済み） |
| G2-B1 | Copilot | Low（bot は Medium） | example が seed の書込み（外す・`push`）を読み直して確かめる | 2ac8f19 | なし（[review 5511696493](https://github.com/artisanworkshop/cart-bridge-jp/pull/136#pullrequestreview-5511696493)） |
| G3-B1 | Copilot | Low | cleanup の `left` を DB から数える（`delete_option()` は失敗でもキャッシュを消す） | 9678fd9 | なし（[review 5511761205](https://github.com/artisanworkshop/cart-bridge-jp/pull/136#pullrequestreview-5511761205)） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-2 | Copilot | High（bot）→ 誤検知 | `.mo`・`.l10n.php` に新しい訳は入っている（`.l10n.php` は 1 行、`.mo` はバイナリで差分に出ない）。`TranslationsTest` が手元と CI で通り、R2 の独立検証でも `msgunfmt` で一致を確認。G2・G3 で再掲されたが新しい根拠は無い | [r4265418669](https://github.com/artisanworkshop/cart-bridge-jp/pull/136#discussion_r4265418669) |

## 品質ゲート

- CI: 最後の push（1dd1048）の [run 38492149604](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38492149604) green（PHP quality 8.2/8.3・PHPUnit〔wp-env〕・JS/TS・Distribution・Dev tooling）。最後の push の後に bot の新しいレビュー・スレッドは無い
- 品質チェック: green（無料版 PHPUnit 1403 件・Pro 1005 件・Jest 111 件・lint・PHPStan・i18n。G1 の修正の後。G2・G3 は example とテキストだけの変更）
- ミューテーション: 21 件中 20 件を検出（検出されない 1 件は Pro の顧客の変換が例外を投げる枝で、到達させる入力が無い）
- dev サイト: `push-intent-resolution` が ALL PASS（4b・7 を含む）、Export タブの行に断りの文が出て行が残ること・コンソールのエラーなし、撤去後の `inspect` が検証前と同じ

## 次にできること（人間の判断）

- G1-2 のスレッドは誤検知として返信済み・未解決。Resolve してよいか判断する（`/cbj-dev-cycle` は承認なしに対応不要のスレッドを Resolve しない）
- backlog の 2 件（`r3-11-push-intent-link-unreadable/R1-X1`: `remote_id` の `sanitize_text_field()`〔`12%345` → `125` で別の実体に紐づきうる〕、`R1-X2`: 外部の commerce adapter が外れたときの LINK_UNSUPPORTED）を issue にするか
- Copilot は 3 回目でも新規 1 件があったので「上限」。数時間後に `/fix-copilot-review 136` で遅れて届いた指摘が無いか確認できる（Codex は収束済み）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`（今回の学び〔`delete_option()` の失敗とキャッシュ、例外の文のエスケープ〕は `.claude/rules/skill-scripts.md` に追記済み）
