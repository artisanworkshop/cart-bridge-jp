# dev-cycle 最終報告: feat/r3-1a-direction-of-origin

## 開発内容
- タスク: R3-1a — 実体は作られた向きにだけ更新する（D25、issue #98）
- PR: #104 https://github.com/artisanworkshop/cart-bridge-jp/pull/104
- 承認された計画（`~/.claude/plans/piped-purring-cray.md`）の要約:
  - 出自の印は新設しない。既存の `_cbjp_platform` を「取込みで結ばれた」印に使い、「誰が作ったか」ではなく「誰が紐づけたか」で判定する（判定は新設 `Woo\Support\EntityOrigin`）。
    - メールで採用した顧客は取込み側。顧客は `_cbjp_created_by_import` も見る。
  - エクスポート側: 各 Reader が `ReadItem::$linked_by_import` を立て、`Exporter` が `linked_by_import_not_exported` でスキップする（mapping・無料枠・intent に触れない）。
  - インポート側: `WooRepository`/`DryRunRepository`、在庫は `StockWriter` が、エクスポートで結ばれた実体を `linked_by_export_not_imported` でスキップする（mapping に触れない）。
  - どちらも mapping があれば `unchanged` に数える。
  - ユーザー決定（計画時）: エクスポートのサンプル選定から取込みで結ばれた実体を除く／WC の商品複製でリンクのメタを写さない。
- コミット（docs の記録を除く）:

| sha | メッセージ |
|---|---|
| `44a234f` | feat: update entities only in the direction they were linked (D25) |
| `b6cf48f` | chore: let the rehearse-colorme skill check the D25 round trip |
| `21ce968` | fix: resolve order lines to variations linked by export and harden the sample scan（R1） |
| `a61eaf2` | chore: make the rehearsal checks fail closed for D25（R1） |
| `ba55ae5` | test: pin the platform scope of variant links and the id guard of the sample scan（R2） |
| `e144bea` | chore: check seed-woo collisions before creating anything（R2） |
| `4663206` | refactor: pass the customer sort keys of the sample scan as an array（G2-1） |
| `c26a9ed` | chore: catch unmarked import links and checksum rewrites in the rehearsal（G2-B1・B2） |

  - ほかに docs 11 件（D25 の設計・実装の記録、review-loop・ゲートの記録、G1-1 の理由の追記、G2-B3/B4 の修正）。
- 設計ドキュメントからの逸脱（PR 本文と同じ）:
  1. D25 の「印は計画で決める」→ `_cbjp_platform` を使い「紐づけた向き」で判定する（docs/03 の D25 の行を更新）。
  2. D25 の対象に受注・クーポンも加えた。
  3. §10.3（R3-0h）の `unchanged` に D25 のスキップ（mapping あり）を加えた。
  4. §10.2 #8 のサンプル選定を「取込みで結ばれていない受注の直近 10 件」に変えた。
  5. 商品複製時のメタ除外フィルターを追加した。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High 0・Medium 5・Low 2・対象外 1 | Medium 5・Low 2 | 対象外 1（R1-X1。docs の既知の限界にも記録） |
| R2（独立サブエージェントで検証） | R1 の 7 件すべて解消・新規 Critical/High 0・新規 Low 5 | Low 5 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 1 | 0（対応不要 1・理由を追記） | 0 | 未収束 |
| G1 | Copilot | 1 | 0（対応不要 1・誤検知） | 0 | 未収束（待ち切った直後に応答） |
| G2 | Codex | 1 | 1 | 0 | 未収束 |
| G2 | Copilot | 4（本文） | 4 | 0 | 未収束 |
| G3 | Codex | 0 | — | — | **収束** |
| G3 | Copilot | 0（総評のみ） | — | — | **収束** |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G2-1 | Codex | P2 | `get_users()` の `orderby` を向き付きの配列に（主張は誤りだが、2 つの bot が続けて読み違えたため。SQL は同じ） | `4663206` | [r4188551213](https://github.com/artisanworkshop/cart-bridge-jp/pull/104#discussion_r4188551213) |
| G2-B1 | Copilot | Medium | `check-import`: `_cbjp_remote_id` があるのに取込みの印が無い行を MISMATCH に | `c26a9ed` | 本文（[review 5420314000](https://github.com/artisanworkshop/cart-bridge-jp/pull/104#pullrequestreview-5420314000)） |
| G2-B2 | Copilot | Medium | スナップショットの mapping に checksum の先頭 12 桁 | `c26a9ed` | 同上 |
| G2-B3 | Copilot | Low | docs/03 の在庫行の箇所数（3 → 5） | `9489a21` | 同上 |
| G2-B4 | Copilot | Low | backlog の共有行の記述を「通常の場合は対応済み」に | `9489a21` | 同上 |

### 修正しなかった指摘（ユーザー承認で対応不要。根拠を返信して Resolve 済み）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Codex | P2 | サンプル選定の走査を 500 件で打ち切っても結果は変わらない。無料版の取込みは上限で止まり、上限を超えるのはエクスポートの作成枠が 0 のときだけ（理由を docblock・docs/03 に追記 `b3676c9`） | [r4188052102](https://github.com/artisanworkshop/cart-bridge-jp/pull/104#discussion_r4188052102) |
| G1-2 | Copilot | Medium | 誤検知（`WP_User_Query` は空白区切りの `orderby` を分割する。実測 `ORDER BY user_registered DESC, ID DESC`） | [r4188131903](https://github.com/artisanworkshop/cart-bridge-jp/pull/104#discussion_r4188131903) |

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37380697367 green（対象 01e1b6d）
  - G1 の記録の push では、GitHub 側のランナー未割当で 2 ジョブが 2 回取消された。前回 green の HEAD から変わったのはコメントと docs だけだったため、ユーザー判断で CI を待たずに G2 を依頼した。その後の CI は green。
- 品質チェック: `quality.sh` green（PHPUnit 1546〔追加 53〕・Jest 87）
- `mutate-check.sh`: 38 種すべて CAUGHT

## マージ前にユーザーが確認すべき点
- **テストショップでの再リハーサル（`rehearse-colorme` 手順 2・3）は未実施**（2026-10-05 にユーザー判断で後回し。お試し期限 2026-10-22 まで）。Copilot の G3 の総評もこの点を挙げている。
  - 手順の概要: `reset-local mode=yes` → `limits-on`（全 entity null）→ `snapshot s0 side=colorme` → import → `seed-woo prefix=ZZV` → dry_run_export → export → `snapshot s1` → `diff a=s0 b=s1` → `snapshot w1 side=woo` → import → `snapshot w2 side=woo` → `diff a=w1 b=w2 side=woo entity=all` → `check-import label=w2 expect-export-links=yes`。
  - R3-1b〜e の再リハーサルとまとめてもよい。
- 双方向の移行の挙動が変わる（取り込んだ実体はエクスポートされず、エクスポートで作った実体は再取込みで更新されない）。既知の限界は docs/03 §10.2「往復の扱い（D25）」に 5 点ある。

## 次にできること（人間の判断）
- テストショップでの再リハーサル（上記）。
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`。
- backlog `r3-1a-direction-of-origin/R1-X1`（エクスポートで結ばれた実体の mapping を失ったときの復元）の起票を判断する。
