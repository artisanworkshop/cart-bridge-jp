# dev-cycle 最終報告: feat/r3-1c-customer-update-and-script-strip

## 開発内容
- タスク: R3-1c — 名前・住所がそろわない顧客の更新を警告つきでスキップ（issue #100）＋説明の `<script>`・`<style>` を中身ごと除去（issue #101）
- PR: #106 https://github.com/artisanworkshop/cart-bridge-jp/pull/106
- 計画（`~/.claude/plans/linked-wandering-quiche.md`。計画時のユーザー回答: #100 の理由の見せ方は警告コードだけ）
  - #100: `CustomerTransformer::to_update_payload()` を `?array` にし、名前（空白だけでない・50 文字以内）と住所 3 点を作成と共有の判定で確かめる。`push_customer()` は作成と同じ `customer_required_field_missing` で PUT を送らずにスキップする（既存の mapping は残る）。作成側にも名前が空のスキップを足した
  - #101: `Woo\Support\HtmlText::sanitize_post_html()` を新設し、`<script>`・`<style>` を中身ごと除いてから kses を掛ける。`Cast::sanitize_html()` と `ProductWriter` の説明・短い説明で使う
  - `rehearse-colorme` の `check-import` に script/style の中身の残りの確認、手順 3 に顧客の更新のスキップ・電話番号の確認を足した
- 除去の段は review-loop とゲートで 3 回作り直した。最終形は次のとおり。
  - タグの区切り: ブラウザと同じく、`<` の直後が英字・`/`・`!`・`?` のときだけタグの始まりとする。
  - 文字の `<` は `&lt;` にする。
  - 閉じタグの無い要素は除かない。
  - コメントの中も除く。`<!-->` はその場で閉じたコメントとして扱う。
  - 処理の前に、kses と同じく制御文字を消す。
  - 区切りは `strpos()` で探す（PCRE の上限・二乗の探し直しは無い）。
- 既知の限界（どれも kses だけの結果と同じかそれ以上）: 属性値の中の `>` の後ろの `<script>`、textarea/title の中、スクリプトの二重エスケープ。backlog `r3-1c-customer-update-and-script-strip/R2-L1` に記録した。
- コミット

| sha | メッセージ |
|---|---|
| `4848a3e` | fix: remove script and style elements with their contents from descriptions (R3-1c) |
| `1bc0465` | fix: skip customer updates that lack the name or address ColorMe requires (R3-1c) |
| `1c1dace` | chore: check script/style text and customer update skips in the rehearsal (R3-1c) |
| `caa7d3b` | docs: record R3-1c (customer update skip and script/style removal) |
| `4fa6047` | fix: find script and style openers the way kses splits tags (R3-1c review R1) |
| `e01ac47` | chore: make the rehearsal script/style check fail closed (R3-1c review R1) |
| `c3ea2f2`・`83a54c9` | docs: record R3-1c review R1 / the R1 result in the dev-cycle state |
| `e58222d` | fix: treat a lone < as text when finding script and style openers (R3-1c review R2) |
| `1b7f6ea` | chore: also look for kses-rewritten script contents in the rehearsal check (R3-1c review R2) |
| `33fd3a5` | docs: record R3-1c review R2 |
| `97cf755` | fix: remove control characters before finding script and style openers (R3-1c G1) |
| `c506060` | fix: compare script/style contents by count in the rehearsal check (R3-1c G1) |
| `5c63e2b` | fix: compare the rehearsal description against an HTML API expectation (R3-1c G2) |
| `1769bcb` | fix: unslash the expected description in the rehearsal check (R3-1c G3) |
| `4ddb55b`・`2029885`・`0d3a50a` | docs: record dev-cycle gate round 1〜3 |

- 設計ドキュメントからの逸脱
  1. `docs/03` E2-3 PR-B の「`PUT` は部分更新で必須項目なし」は実測と違ったので訂正した（事実の訂正）
  2. 作成側にも、名前が空ならスキップする判定を足した（同じ警告コード。以前は 422）
  3. issue #101 の対応案は `Cast` だけだったが、浄化を `Woo\Support\HtmlText` に置き、`ProductWriter` でも使う（計画で承認）
  4. 文字の `<` を `&lt;` にするので、`1<2 and 3>2` のように `<` の後ろに `>` がある説明だけは、kses だけの結果（`12`）から変わる（ブラウザの表示に近づく。checksum が変わるのはこの形と script/style を含む説明だけ）
  5. issue #100 の「C05 で確かめる」は D25 で C05 がエクスポートされなくなったため、Woo 生まれの顧客の郵便番号を消す手順に置き換えた

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High 0・Medium 3・Low 4・対象外 1 | Medium 3（属性値・コメント・CDATA の中の `<script>` から後ろの説明を消す／作り直しの二乗／check-import の検出漏れ）・Low 3 | Low 1・対象外 1 |
| R2（独立サブエージェントで検証） | R1 の Medium 3 件とも解消・新規 Medium 1（作り直しが文字の `<` の後ろの `<script>` を見落とす後退）・Low 2・対象外 1 | Medium 1・Low 2・R1-2 の残余 | 既知の限界 1・対象外 1 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1 | 1 | 0 | 未収束 |
| G1 | Codex | 2 | 2 | 0 | 未収束 |
| G2 | Copilot | 3 | 3 | 0 | 未収束 |
| G2 | Codex | 0 | 0 | 0 | **収束** |
| G3 | Copilot | 1 | 1 | 0 | 依頼上限（3 回）。修正を push し、4 回目は依頼しない |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | check-import が要素の外の同じ文字列で正しい取込みを誤検出する | `c506060`（G2 で `5c63e2b` に作り直し） | [r4192618411](https://github.com/artisanworkshop/cart-bridge-jp/pull/106#discussion_r4192618411) |
| G1-2 | Codex P2 | Medium | `<script\0>` のように制御文字を挟むと除去されず、kses がタグだけ外して JS が残る → kses と同じく先に `wp_kses_no_null()` | `97cf755` | [r4192642795](https://github.com/artisanworkshop/cart-bridge-jp/pull/106#discussion_r4192642795) |
| G1-3 | Codex P2 | Medium | G1-1 と同じ | `c506060` | [r4192642802](https://github.com/artisanworkshop/cart-bridge-jp/pull/106#discussion_r4192642802) |
| G2-1 | Copilot | Medium | check-import の判定が制御文字を挟んだ要素を拾えない | `5c63e2b` | [r4192896519](https://github.com/artisanworkshop/cart-bridge-jp/pull/106#discussion_r4192896519) |
| G2-2 | Copilot | Medium | 期待値が文字の `<` の扱いを反映せず、正しい取込みを誤検出する | `5c63e2b` | [r4192896573](https://github.com/artisanworkshop/cart-bridge-jp/pull/106#discussion_r4192896573) |
| G2-3 | Copilot | Medium | 出現回数の比較では前後がつながった漏れを見逃す → HTML API で作った期待値と完全一致で比べる | `5c63e2b` | [r4192896606](https://github.com/artisanworkshop/cart-bridge-jp/pull/106#discussion_r4192896606) |
| G3-1 | Copilot | Medium | 期待値に保存時の `wp_unslash()` が無く、バックスラッシュを含む説明を誤検出する | `1769bcb` | [r4193040808](https://github.com/artisanworkshop/cart-bridge-jp/pull/106#discussion_r4193040808) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（全スレッドを修正して Resolve 済み）

## 品質ゲート
- CI: 最終 push（`0d3a50a`）の [run 37436174533](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37436174533) で green（PHP quality 8.2/8.3・PHPUnit〈wp-env〉・JS/TS・Dev tooling）。最終 push の後に bot の新しいスレッド・本文は無し（`gate-threads.sh`・`gate-bodies.sh` で確認）
- 品質チェック: green（PHPCS・PHPStan・PHPUnit 1631〔追加 53〕・ESLint・Jest 87・build）
- `mutate-check.sh`: 除去 18 種・顧客 8 種がすべて CAUGHT（途中で NOT CAUGHT だった 3 種はテストを足して CAUGHT）
- wp-env の dev サイトでの確認
  - 実際の `Cast`・`ProductWriter`（WP-Cron の条件と管理者）・`push_customer()` を通して確認した。
  - check-import の判定は、G1〜G3 の指摘の例すべてと、実際の Writer で保存した説明で確認した。

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- **テストショップでの確認は R3-1b〜e の実装後の再リハーサルでまとめて行う**（2026-10-06 ユーザー決定。お試し期限 2026-10-22 まで）。
  - `rehearse-colorme` の手順 1 の `check-import`: P11 の説明に JS・CSS の文字が残らないこと。
  - 手順 3: 作成エクスポートした顧客の郵便番号を消して export し、skipped・warned になってエラーログが出ないこと。続けて電話番号だけを消して export し、`updated` か 422 かを記録する（422 なら更新の判定に電話番号を足す）。
- backlog に送った項目
  - `r3-1c-customer-update-and-script-strip/R1-L1`: 全角スペースだけの名前
  - `R1-X1`: `TermWriter` がターム説明を浄化しない
  - `R2-L1`: 除去の既知の限界
  - `R2-X1`: kses 自体が冪等でない入力
- Copilot は依頼上限の後でも依頼していないレビューを返すことがある。マージ前に `scripts/gate-threads.sh 106 2026-10-06T08:26:53Z`・`scripts/gate-bodies.sh 106 2026-10-06T08:26:53Z` で新しい指摘が無いか確認する
