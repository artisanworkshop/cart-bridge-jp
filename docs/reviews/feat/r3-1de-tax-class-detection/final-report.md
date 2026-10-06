# dev-cycle 最終報告: feat/r3-1de-tax-class-detection

## 開発内容
- タスク: R3-1d＋R3-1e — 税区分を JP の税率で見分け（D26、closes #102）、標準・軽減以外の税区分と換算できない価格の商品のエクスポートを止める（closes #78）
- PR: #107 https://github.com/artisanworkshop/cart-bridge-jp/pull/107
- 承認された計画（`~/.claude/plans/snug-strolling-pillow.md`）の要約:
  - `Woo\Support\TaxClass` が Woo の税区分を JP の税率（法定 10%／8%）で分類する。正規化モデルの `'reduced-rate'` は Woo のスラッグではなく記号として扱う。
  - 取込みは JP 8% の税区分へ入れる（無ければ、税率の無い既定名の税区分 → 標準。止めない）。
  - エクスポートは、標準・軽減以外の税区分の商品・バリエーションを止める。
  - ColorMe の hidden 安全策を、作成も更新もしないスキップに置き換える。
  - リハーサル道具に `tax-classes` を足す。
- 計画時のユーザー回答（2026-10-06）:
  - Q1: 判定の税率は法定税率の定数
  - Q2: 8% の税区分が無くても、取込みは止めない
  - Q3: 税率の無い既定名の税区分は軽減とみなす
  - Q4: ColorMe 側の換算不能は本実行のスキップだけ
- review-loop R1 でのユーザー決定: エクスポートは、標準の税区分 `''` も JP の税率で分類する（取込みの標準側は backlog `r3-1de-tax-class-detection/R1-X1`）。

### コミット
| sha | メッセージ |
|---|---|
| 31abebe | feat: detect tax classes by the JP rate and block unsupported ones from export (R3-1d/e) |
| 6020f65 | chore: check tax classes by the JP 8% rate in the rehearsal and add tax-classes |
| 5ef3829 | docs: record the tax class detection (D26) and the export block for unsupported classes (R3-1d/e) |
| 3d6ac19 | fix: classify the standard tax class by its JP rate and keep unsupported classes off the reduced token (R1) |
| 7ec5f24 | chore: stop tax-classes when the moved rates do not arrive in the new class (R1-6) |
| 96e3eff | docs: record review-loop R1 for R3-1d/e |
| 5b450e2 | docs: align the standard tax class comments with the rate-based classification (R2-1) |
| b24ac24 | docs: record review-loop R2 for R3-1d/e |
| 387dc1b | fix: reproduce WooCommerce's rate selection for the rehearsal's expected tax classes (G1) |
| 3c0ff14 | docs: record dev-cycle gate round 1 for PR #107 |
| 908300c | fix: keep tax-inclusive price conversion from passing a non-string tax class to WooCommerce (G2-B2) |
| 2b84ec0 | chore: stop tax-classes when a deleted class is still referenced (G2-B1) |
| 1193000 | docs: record dev-cycle gate round 2 for PR #107 |
| 88626f6 | fix: keep the tax class memo out of persistent object caches (G3-B1) |
| ac62f06 | chore: stop tax-classes while a job action is still processing a page (G3-1) |
| 93e9ebb | docs: record dev-cycle gate round 3 for PR #107 |

### 設計ドキュメントからの逸脱
1. D26 は「`shop.reduce_tax_rate`（8%）／`shop.tax`（10%）」だったが、日本の法定税率の定数で判定する（Q1）。
2. 税率が 1 件も無い既定名の税区分を、軽減とみなすフォールバックを置いた（Q3）。
3. #78 の決定は「止める警告を dry-run に出す」。ただし ColorMe の `shop.json` の税設定が読めない場合だけは、dry-run に出ない（Q4）。
4. 取込みで `reduced_tax_class_not_found` のとき checksum を保存しないのは、商品だけ（受注は保存する）。
5. 計画では「標準の税区分 `''` は常に標準」だったが、エクスポートは `''` も JP の税率で分類する（review-loop R1 のユーザー決定）。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High 0・Medium 3・Low 4・対象外 1 | Medium 3・Low 4 | 対象外 1（R1-X1 取込みの標準側） |
| R2（独立サブエージェントで検証） | R1 の 7 件すべて解消・新規 Critical/High 0・新規 Low 1 | Low 1 | — |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1 | 1 | 0 | 未収束 |
| G1 | Codex | 2（うち 1 件は Copilot と同じ） | 2 | 0 | 未収束 |
| G2 | Copilot | 2（本文の Previously missed） | 2 | 0 | 未収束 |
| G2 | Codex | 0 | — | — | **収束**（👍） |
| G3 | Copilot | 2（スレッド 1・本文 1） | 2 | 0 | 上限（3 回。G3 の修正は再レビューされていない） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | リハーサルの期待値（JP 8% の税区分）が全行を足し、WC の優先度ごとの行の選び方・複合税率と食い違う | 387dc1b | [r4195270102](https://github.com/artisanworkshop/cart-bridge-jp/pull/107#discussion_r4195270102) |
| G1-2 | Codex | P2 | G1-1 と同じ | 387dc1b | [r4195308561](https://github.com/artisanworkshop/cart-bridge-jp/pull/107#discussion_r4195308561) |
| G1-3 | Codex | P2 | `tax-classes` が、税率のある移し先へ税率を重ねる | 387dc1b | [r4195308581](https://github.com/artisanworkshop/cart-bridge-jp/pull/107#discussion_r4195308581) |
| G2-B1 | Copilot | Medium | `tax-classes` が、消えた税区分をまだ指す参照を見逃して成功と報告する | 2b84ec0 | 本文（[review](https://github.com/artisanworkshop/cart-bridge-jp/pull/107#pullrequestreview-5428586421)） |
| G2-B2 | Copilot | Medium | 税抜入力の店舗で、非文字列の税区分が価格の換算で TypeError になる | 908300c | 本文（同上） |
| G3-1 | Copilot | High→Medium | `tax-classes` が、処理中のジョブのアクションで止まらない | ac62f06 | [r4200602562](https://github.com/artisanworkshop/cart-bridge-jp/pull/107#discussion_r4200602562) |
| G3-B1 | Copilot | Medium | `TaxClass` のメモ化が、永続オブジェクトキャッシュに残る | 88626f6 | 本文（[review](https://github.com/artisanworkshop/cart-bridge-jp/pull/107#pullrequestreview-5434758026)） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37534494210 green（最終 push `93e9ebb`。PHPUnit〔wp-env〕・PHP quality 8.2／8.3・JS/TS・Dev tooling）
- 品質チェック: green（`quality.sh`: PHPCS・PHPStan・PHPUnit 1683〔main から +52〕・ESLint・Jest 87・build）
- `mutate-check.sh` で 44 種がすべて CAUGHT
  - 実装時 36 種・review-loop R1 7 種・G2 1 種
  - ほかに、等価な置換 1 種は対象外
- wp-env の dev サイトでの実機確認
  - `tax-classes mode=ja` のうえで、実際の `ProductWriter`（WP-Cron の条件と管理者）と、`ProductReader`＋`Exporter` の dry-run を通した。
  - `tax-classes` の往復と、ABORT する 2 つの状態を確かめ、元の状態に戻した。

## マージ前にユーザーが確認すべき点
- **既知の限界**
  - この変更より前に日本語の Woo へ取り込んだ軽減税率の商品は、checksum 保存済みのため、再取込みでは直らない。v0.1.0 を検証中のサイトが該当しうる。税区分を手で直すか、クリーンアップして取り込み直す。
  - 取込みの標準の商品は、常に `''` に入る（backlog R1-X1。`''` に 10% 以外を入れた店舗では誤る）。
  - 税抜入力の店舗で、可変商品の税区分のフィルターが配列を返すと、プラグインより先に WooCommerce 本体が落ちる。プラグインからは防げない。
- **Copilot の G3 の修正（`88626f6`・`ac62f06`）は再レビューされていない**（依頼の上限）。
- **テストショップでの確認は、R3-1b〜e の実装後の再リハーサルでまとめて行う**（お試し期限 2026-10-22）。日本語インストールの状態は `tax-classes mode=ja` で作る。
  - 取込み: 軽減税率の商品が「軽減税」に入る。
  - 作成エクスポート: `ZZW-2` が軽減税率で送られ、`ZZW-3`（ゼロ税率）が止まる。
  - 終わったら `mode=en` で戻す。

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- R3-1a〜e がそろったら、`rehearse-colorme` の再リハーサル（`reset-local` から）
- backlog `r3-1de-tax-class-detection/R1-X1`（取込みの標準側を税率で選ぶか）の判断
