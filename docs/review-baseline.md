# レビューベースライン（許容済み指摘リスト）
レビュー時、ここに記載された項目は指摘しないこと。

## 形式
- [カテゴリ] 対象範囲 — 許容理由

## 許容項目
<!-- 例:
- [WPCS] プロジェクト全体 — Yoda condition 非適用（チーム規約で不採用）
- [DB] Repository クラス内の $wpdb 直接クエリ — 抽象化済みのため許容
-->
- [設計] `Woo\Writer\*` の `validate()` が保存依存の警告（`*_SAVE_FAILED`/`*_CREATE_FAILED`/`IMAGE_DOWNLOAD_FAILED`/`CUSTOMER_CREATE_FAILED`/`CUSTOMER_EMAIL_CONFLICT`/`VARIATION_*`）を出さないこと — 実装計画で明示的にスコープ外とした仕様（`docs/03-design-decisions.md` §10.4 参照）
- [設計] `VariationWriter` に `validate()` を実装しないこと — 親ID確定後にしか走らないため明示的にスコープ外
