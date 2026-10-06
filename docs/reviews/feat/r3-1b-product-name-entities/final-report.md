# dev-cycle 最終報告: feat/r3-1b-product-name-entities

## 開発内容
- タスク: R3-1b — 商品名を実体参照にして保存し、エクスポートで戻す（issue #99）
- PR: #105 https://github.com/artisanworkshop/cart-bridge-jp/pull/105
- 承認された計画の要約: Canonical の名前は平文、Woo の名前（post_title）は HTML と決め、変換を新設 `Woo\Support\HtmlText` に集約。
  取込み（`ProductWriter`）は名前の `&` `<` `>` を実体参照（二重符号化・引用符はそのまま）、`\` を `&#092;`、制御文字を除去して保存し、説明・短い説明は Writer 自身が `wp_kses_post()`。
  エクスポート（`ProductReader`）は名前とターム名（グローバル属性の値。`VariationAxisResolver`・`ProductReader::options()`）を `html_entity_decode` で平文へ戻す。push intent の一覧の名前も戻す。
  計画時のユーザー回答で issue の対応案（`esc_html()`）から方式を変え、ターム名・説明・push intent の表示を範囲に含めた。
- コミット:
  - `88572c8` fix: save product names as HTML entities so the runner does not change them (#99)
  - `a0be010` chore: check the stored form of product names in the ColorMe rehearsal
  - `ca79039` docs: record the product name storage format (R3-1b, #99)
  - `e1a65f8` fix: drop control characters from product names and pin the update path（R1-1・R1-2・R1-L3・R1-L4）
  - `b78dee9` docs: record review-loop R1 for R3-1b
  - `6974e86` docs: correct review notes and rules found in review-loop R2（R2-1〜R2-3）
  - `5161cdb` docs: record review-loop R2 for R3-1b
  - `120d0f8` docs: record dev-cycle gate round 1
  - （この記録）docs: record dev-cycle final report
- 設計ドキュメントからの逸脱: 設計の変更は無し（`docs/03` §10.2 に「商品名の保存形式（R3-1b、issue #99）」を追加）。issue #99 の対応案からの差分（`esc_html()` → `& < >`＋`\` の二重エンコード、`wp_specialchars_decode` → `html_entity_decode`、範囲の追加）は計画時にユーザー承認。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High 0・Medium 2・Low 6・対象外 3 | Medium 2・Low 4 | Low 2・対象外 3 |
| R2（独立サブエージェントで検証） | R1 の Medium 2 件とも解消・新規 Critical/High 0・新規 Low 3 | Low 3 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（状況コメント Completed・👍） |
| G1 | Copilot | 1 | 0 | 0（対応不要 1） | 未収束 → G2 |
| G2 | Copilot | 0 | 0 | 0 | 収束（🟢 Approval recommended・Findings: None） |

### 修正した指摘
なし（ゲートでのコード変更なし）

### 修正しなかった指摘
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Copilot | Medium（bot 判定） | 対応不要（ユーザー承認・Resolve 済み）。実体参照のタイトルが Store API・Analytics の語句検索で `Tom & Jerry` に一致しない件。通常検索（`WP_Query`）・管理画面の商品検索は語に分けて照合するので見つかり（wp-env で実測）、WP-Cron の取込みは本 PR 以前から同じ形のため退行ではない。backlog `r3-1b-product-name-entities/G1-1` | [r4191266712](https://github.com/artisanworkshop/cart-bridge-jp/pull/105#discussion_r4191266712) |

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37410442277 green（HEAD 120d0f8）
- 品質チェック: green（PHPCS・PHPStan・PHPUnit 1578〔追加 32〕・ESLint・Jest 87・build）。`mutate-check.sh` で 15 種がすべて CAUGHT
- wp-env の dev サイトで、実際の Writer/Reader により WP-Cron の条件と管理者の保存結果が一致し、読み戻した名前が元どおりになることを確認

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- テストショップでの確認（`rehearse-colorme` の `run context=cron` と `context=admin` で取り込み → `check-import`、Woo 生まれの `ZZW-6` の作成エクスポートで ColorMe に `Fish & Chips <set>` の文字で届くこと）は、R3-1b〜e の実装後の再リハーサルでまとめて行う（2026-10-06 ユーザー決定。お試し期限 2026-10-22 まで）。R3-1b のタスクはそれまで未チェックのまま
- backlog に送った項目: `r3-1b-product-name-entities/plan-X1`〜`X3`（受注の明細名・クーポンの説明・説明の `\`）、`R1-L1`（バリエーションの属性の要約）、`R1-L2`（check-import の正規表現）、`R1-X1`（既存 `TermWriter` の衝突判定。Medium）、`R1-X2`・`R1-X3`、`G1-1`（検索）
