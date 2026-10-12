# dev-cycle 最終報告: chore/128-gate-post-and-codex-wait

## 開発内容
- タスク: issue #128。ゲートの返信・Resolve・サマリの投稿を 1 コマンド（`gate-record.sh post`）にし、Codex の応答をレビューの完了で判定する（`bot-wait.sh`）
- PR: #129 https://github.com/artisanworkshop/cart-bridge-jp/pull/129
- 承認された計画の要約:
  - `post` は、確認ゲート・commit・`check`・push の後の機械的な実行だけを受け持つ。投稿してよいかの判断は人とモデルのまま。
  - 何かを投稿する前に前提をすべて確かめ、やり直しても二重に投稿しない。最後に未解決のスレッドを数え直す。
  - Codex の「Codex Review Summary」は、Completed で、かつ PR の HEAD の commit のときだけ応答と数える。👍 も応答と数える。
- コミット:
  - `60a4548` chore: count a Codex summary only once its review of the PR head completes (#128)
  - `253a23c` chore: add gate-record.sh post to reply, resolve and post the summary (#128)
  - `2b3d4e1` docs: describe gate-record.sh post and the Codex wait in cbj-dev-cycle (#128)
  - `33f7f03` fix: keep the Codex nudge off a running review and tighten post (#128 R1)
  - `d4a5cef` fix: close the remaining gaps in gate-record.sh post (#128 R2)
  - `c9830ee` docs: record review-loop R1 and R2 for the gate post and Codex wait (#128)
  - `35c98c1` fix: tighten the Codex response check and the summary check in post (#129 G1)
  - `8d70776` docs: record gate round G1 for the gate post and Codex wait (#129)
  - `ead6431` fix: render the post summary from the record already checked (#129 G2)
  - `853d006` docs: record gate round G2 for the gate post and Codex wait (#129)
  - `4badfbb` fix: stop post when a thread is not actually resolved (#129 G3)
  - `c1a2ac1` docs: record gate round G3 for the gate post and Codex wait (#129)
- 設計ドキュメントからの逸脱: なし（開発の道具だけ）。`gate-record.sh` の「投稿・Resolve は自動でしない」という方針は変えた（判断は人のまま、実行だけを機械に）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Medium 5・Low 5（+ backlog 1） | 10 | 1（R1-L1 → G1 で対応） |
| R2（独立検証・変異 11 種）→ APPROVE | 新規 Low 3 | 3 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 3（スレッド） | 3 | 0 | 未収束 |
| G1 | Codex | 1（スレッド・P2） | 1 | 0 | 未収束（自動レビューが届かず、手で依頼した 1 回目） |
| G2 | Codex | 0 | — | — | 収束（Didn't find・👍・Completed） |
| G2 | Copilot | 本文 2（Previously missed） | 2 | 0 | 未収束 |
| G3 | Copilot | 本文 1（Previously missed） | 1 | 0 | 上限（3 回目でも新規指摘あり。4 回目は依頼しない） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | `--codex=0` でも Codex のレビューを問い合わせていた → 待たない bot は問い合わせない（進捗の行は `-`） | `35c98c1` | [r4245531241](https://github.com/artisanworkshop/cart-bridge-jp/pull/129#discussion_r4245531241) |
| G1-2 | Copilot | Medium | `if ! summary=$(…); then rc=$?` の `rc` が常に 0（R2 で入れた誤り）→ G2-B1 でこの読み直し自体を無くした | `35c98c1` | [r4245531291](https://github.com/artisanworkshop/cart-bridge-jp/pull/129#discussion_r4245531291) |
| G1-3 | Copilot | Medium | Copilot だけを待つテストが `/reviews` を見ていない → 両方向のテスト | `35c98c1` | [r4245531324](https://github.com/artisanworkshop/cart-bridge-jp/pull/129#discussion_r4245531324) |
| G1-4 | Codex | P2 | サマリの「投稿済み」を見出しの部分一致だけで判定 → 本文全体を比べ、違えば何も投稿せずに止める | `35c98c1` | [r4245595193](https://github.com/artisanworkshop/cart-bridge-jp/pull/129#discussion_r4245595193) |
| G1 補足 | — | — | 環境の作成を促す Codex の定型コメントを応答と数えて DONE になった（この PR で実際に起きた。R1-L1）→ 「Didn't find」だけを数え、ほかは ID を一度だけ表示 | `35c98c1` | — |
| G2-B1 | Copilot | Medium | `post` のサマリが記録を読み直すので、途中の書き換えで返信と食い違う → 同じ時点の記録から作る | `ead6431` | なし（[review 5487852073](https://github.com/artisanworkshop/cart-bridge-jp/pull/129#pullrequestreview-5487852073)） |
| G2-B2 | Copilot | Medium | テストの `seq` が macOS に無い（主張は誤り。`/usr/bin/seq` は標準）→ `printf '%033d' 0` に置き換え | `ead6431` | なし（同上） |
| G3-B1 | Copilot | Medium | `gate-resolve.sh` は Resolve されなくても 0 で終わる → 1 で終わり、`post` も `resolved=true` を確かめる | `4badfbb` | なし（[review 5488126376](https://github.com/artisanworkshop/cart-bridge-jp/pull/129#pullrequestreview-5488126376)） |

### 修正しなかった指摘
- なし

## 品質ゲート
- CI: 最終 push（`c1a2ac1`）の [run 38224451056](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38224451056) が green（PHP quality 8.2／8.3・PHPUnit（wp-env）・JS/TS・Dev tooling・Distribution）。最終 push 以降に新しいレビュー・スレッドは無い
- 品質チェック（`quality.sh`）: green（PHPUnit 無料版 1277・Pro 829、Jest 99、`test-gate-bodies`・`test-gate-turn`・`test-gate-record`・`test-bot-wait`・`test-anon`）。各ゲートの修正の前にも実行した
- 変異（`mutate-check.sh`）: 実装 14・R1 6・R2 1・G1 9・G2 1・G3 2 の計 33 種がすべてテストで止まる
- 実際の PR #129 で使った:
  - `post`: G1 で返信 4・Resolve 4・サマリを 1 回で投稿し、やり直しでは何も投稿しなかった。G2 では push の直後に PR の HEAD が追いつかず止まり（何も投稿せず）、数秒後の再実行で通った
  - `bot-wait.sh`: Codex の Running の間は待ち、Completed・Didn't find・👍 で応答と数えた（G1 は R2 の版、G2 は G1 の版）

## マージ前に確認してほしいこと
- 知らない形の Codex の応答は、15 分の TIMEOUT まで待つようになった（定型コメントで誤って DONE になるのと引き換え）。TIMEOUT の後は `gate-turn.sh` が表示する Codex のコメントを読んで判断する
- push の直後の `post` は、PR の HEAD の反映待ちで止まることがある（文言で再実行を案内する）。毎ラウンド起きるなら、`post` に短い待ちを入れる案がある
- この PR の G1 で、私が書いた PR 本文の `@codex`（バッククォート内）に Codex が反応した。SKILL.md の禁止は「サマリ・返信・G<n>.md」だけで、PR 本文を挙げていない（`/post-merge` で足す候補）

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- Copilot から依頼外のレビューが後で届いた場合は `/fix-copilot-review 129`
