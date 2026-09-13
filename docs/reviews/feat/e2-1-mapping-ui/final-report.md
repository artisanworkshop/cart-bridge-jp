# dev-cycle 最終報告: feat/e2-1-mapping-ui

## 開発内容

- **タスク**: Phase 2「Woo → カラーミー エクスポート」の最初のタスク E2-1「マッピングUI」
  （カテゴリ・決済方法・配送方法・注文ステータスの対応表UI）。
- **PR**: [#39](https://github.com/artisanworkshop/cart-bridge-jp/pull/39)
- **承認された計画の要約**: Phase 2 は E2-1〜E2-4 の4タスクから成り、合計するとPhase 1の
  `F1-5`〜`F1-8`級の分量になることが着手時の調査で判明したため、本セッションでは **E2-1のみ** を
  実装した（E2-2 Exporterパイプライン・E2-3 ColorMe push*・E2-4 エクスポートUI+往復E2Eは次PR以降）。
  `PlatformAdapter`（03 §2「確定版」）に `mapping_candidates()` を追加（D19）し、`ColorMeAdapter` が
  既存の`fetch_categories()`/`payments.json`/`deliveries.json`取得ロジックを再利用して実装。
  Woo側候補は新設の`Woo\Support\MappingCandidates`（プラットフォーム非依存）が担う。設定ストアに
  `category_map`（Woo側カテゴリID→ASP側カテゴリID。他3キーとは逆向き）を追加。

### コミット一覧（16件）

| sha | メッセージ |
|---|---|
| 0e54fec | feat: add mapping candidates backend for the export settings UI (E2-1) |
| 55ce412 | feat: build the export mapping settings UI (E2-1) |
| 15e89cd | docs: record E2-1 mapping-candidates design and mark the task done |
| 38488b0 | fix: prevent order-status data loss and validate adapter candidate shape (R1-2, R1-3, R1-5, R1-6) |
| 3970106 | fix: guard against stale platform saves and orphaned mapping values (R1-1, R1-4, R1-5, R1-7) |
| eea2e66 | docs: record E2-1 review-loop R1 results |
| 1f6c816 | docs: record review-loop R2 APPROVE for E2-1 |
| 4794735 | docs: record PR #39 creation in dev-cycle state |
| 73cd5f5 | fix: enforce checkout-draft rejection at the write boundary and reject empty candidate ids (G1-1, G1-2) |
| 17285b2 | fix: disable mapping controls while saving and stop losing the error state (G1-3, G1-6) |
| 8b0d7bc | docs: record gate round G1 and fix broken/incorrect review-doc references |
| 9e08a07 | fix: normalize candidate ids/names with the same rule the PUT validator uses (G2-2) |
| 1642b6c | fix: guard the mapping fetch effect by request generation, not platform name (G2-3) |
| 8ef05a7 | docs: record gate round G2 |
| cbb66c8 | fix: decode HTML entities in WooCommerce category candidate names (G3-1) |
| 103cfbe | fix: extend the request-generation guard to save() (G3-2) |
| 08c7e62 | docs: record gate round G3 (final) and correct stale backlog entry |

### 設計ドキュメントからの逸脱

- **D19**（`docs/03-design-decisions.md` §1/§2/§6に記録）: `PlatformAdapter`（03 §2「確定版」）に
  `mapping_candidates()` を追加。`connection_fields()` と同じ「自己記述スキーマをUIが消費する」設計。
  外部アドオンによるカスタムアダプタ実装は現時点で存在しないため後方互換リスクは低いと判断（計画時に
  提示し承認済み）。
- `category_map` の向きが既存3キー（ASP側→Woo側）と逆（Woo側→ASP側）。カラーミーがカテゴリ作成
  不可という制約に起因する必然的な非対称性としてD19に記録。
- `docs/03-design-decisions.md` D19に、**E2-2/E2-3への申し送り事項**として、`payment_map`/
  `shipping_map`がASP→Wooの単射とは限らない（複数のASP値が同じWoo値へ寄りうる）ため、エクスポート
  時にWoo側の値からASP側の値へ機械的に逆引きできない旨を追記した（G1-7指摘・Codexレビューを機に
  明文化）。

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | High 3・Medium 4（自己レビュー1件＋独立サブエージェント6件） | 7件すべて修正（うちcheckout-draftへのマッピングで受注が24時間後にcron削除される重大な指摘を含む） | Low 10件・対象外3件 |
| R2（検証） | 新規Critical/High/Mediumゼロ | — | 新規Low 4件 |

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2スレッド+本文6件 | 6件 | 4件（既存backlog記録と重複） | 未収束 |
| G1 | Codex | 4スレッド | 3件（1件は空id/nameでCopilotと重複） | 2件（既存backlog記録と重複） | 未収束 |
| G2 | Copilot | 本文1件 | 1件（G2-2と重複） | 0件 | 未収束 |
| G2 | Codex | 4スレッド | 3件 | 1件（i18n。対象外・別タスク計画済み） | 未収束 |
| G3（最終） | Copilot | 本文1件 | 1件（backlog記載の古さ） | 0件 | 依頼上限（3回）到達 |
| G3（最終） | Codex | 3スレッド | 2件 | 1件（backlogへLow記録） | 依頼上限（3回）到達 |

Codexの自動レビューはPR作成時に発火しなかったため、G1のみ`@codex review`コメントで明示依頼した
（依頼回数の1回目として計上。`docs/reviews/feat/e2-1-mapping-ui/dev-cycle.md`参照）。

### 修正した指摘（抜粋・詳細は G1.md〜G3.md 参照）

| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | High相当 | `checkout-draft`除外がUI経由の候補一覧のみで直接PUTからの書込みを防げていなかった（受注が24時間後にcron削除されうる） | 73cd5f5 | [r3999421215](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999421215) |
| G1-2 | Copilot+Codex | Medium | マッピング候補のid/nameが空文字列化する値を拒否していなかった | 73cd5f5 | [r3999421235](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999421235) |
| G1-3 | Codex+Copilot | High | 保存中に別の行を変更すると応答到達時に無言で上書きされ変更が失われる | 17285b2 | [r3999445983](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999445983) |
| G1-6 | Copilot（本文） | Medium | マッピング取得エラー通知を破棄するとSpinnerのまま復帰不能になる | 17285b2 | 本文指摘 |
| G2-2 | Codex+Copilot | Medium | id/name正規化がPUT側の検証ロジックと不整合 | 9e08a07 | [r3999487332](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999487332) |
| G2-3 | Codex+Copilot | Medium | GETのレースガードがプラットフォーム名のみでA→B→Aを区別できない | 1642b6c | [r3999487334](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999487334) |
| G3-1 | Codex | Low | Wooカテゴリ名のHTMLエンティティ二重エスケープ表示 | cbb66c8 | [r3999534848](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999534848) |
| G3-2 | Codex+Copilot | High | `save()`のレースガードが世代カウンタ未適用でA→B→Aに未対応 | 103cfbe | [r3999534854](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999534854) |

### 修正しなかった指摘（PR上で未解決のまま残してある。Low・対象外のみ）

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-4 (=R1-L2) | Codex | Low | カテゴリー候補が親子階層を持たずフラット | [r3999445991](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999445991) |
| G1-5 (=R1-L8) | Codex | Low | 非公開ColorMeカテゴリーがエクスポート候補から除外される | [r3999445994](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999445994) |
| G2-1 | Codex | 対象外 | i18n未整備。Phase 3 R3-2として既に計画済みの別タスク | [r3999487329](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999487329) |
| G3-3 | Codex | Low | マッピングのプレーンオブジェクトが`__proto__`等のキーに脆弱。実害は限定的で修正範囲がやや広いため次タスクへ | [r3999534858](https://github.com/artisanworkshop/cart-bridge-jp/pull/39#discussion_r3999534858) |

`docs/review-backlog.md`に`e2-1-mapping-ui/R1-L1`〜`R1-L10`・`R1-X1`〜`R1-X3`・`R2-L1`〜`R2-L4`・
`G3-3`として記録済み（実質的にG1/G2で保留とした項目もここに集約されている）。

## 品質ゲート

- CI: [run 34755719173](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/34755719173) green（PHP quality 8.2/8.3・JS/TS・PHPUnit(wp-env) 全通過）
- 品質チェック: green（`composer lint && composer analyze && composer test:wpenv` — PHPUnit 728件、
  `npm run lint && npm run build`）

### E2E実機確認について

実店舗ColorMeとのOAuth接続は本セッションでは確立できなかった（developer.shop-pro.jpへのブラウザ
セッションが無く、wp-envのポート変更（8895）に伴うリダイレクトURI再登録とログインが必要）。
代わりにwp-env上に一時的なモックアダプタ（mu-plugin。コミットせず作業完了後に削除済み）を用意し、
以下をブラウザで確認した:
- Exportタブを開くと4つのマッピングテーブルがASP/Woo双方の実データ（カテゴリ/決済/配送/注文
  ステータス）で描画される
- 選択して保存 → REST応答・DBオプション（`cbjp_settings_mockexport`）に正しく永続化される
- リロード後も選択内容が保持される

実際のColorMe候補データでの確認は、実接続が必要になるE2-3（`push_order`実装、要検証#5でテスト
ショップ接続が必要）まで持ち越し。

## 次にできること（人間の判断）

- 保留分の修正: `/dev-cycle fix G1-4 G1-5 G3-3`（i18n=G2-1は対象外のため対象から除外推奨）
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
- マージ後、Phase 2の次タスク（E2-2: Exporterパイプライン）に着手する場合は改めて
  `/cbj-dev-cycle` を起動
