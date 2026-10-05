# dev-cycle 最終報告: feat/r3-0g-order-sample-id-fetch

## 開発内容

- タスク: R3-0g「受注のサンプルインポートを ID 指定取得にする」（`docs/10-tasks.md`。issue #38）
- PR: [#86](https://github.com/artisanworkshop/cart-bridge-jp/pull/86)
- 承認された計画の要約: 無料版サンプル移行で `order` エンティティだけがカーソル全量走査（`fetch_orders`）を通っていた
  バグ（実店舗で `processed` が受注の全件・`created: 10`・残りが `skipped`）を、`Importer::run_sample_page()` と
  `JobManager::SAMPLE_ID_FETCH_ENTITIES` に `order` を追加してID指定取得（`fetch_order_by_remote_id()`。
  issue #46 で追加済み・日付窓の影響なし）へ切り替える。
- コミット一覧（実装12件＋記録用docsコミットを含む）:
  | sha | メッセージ |
  |---|---|
  | fa3b443 | fix: use ID fetch for order sample import instead of cursor walk |
  | 14d6dcd | docs: mark R3-0g complete and document order ID fetch in sample selection |
  | 6284fdf | fix: cap and deduplicate the order sample against a misbehaving adapter（review-loop R1） |
  | ed04456 | docs: record review-loop R1 for R3-0g and fix a factual error in its summary |
  | 43f211e | docs: record review-loop R2 APPROVE and dev-cycle state for R3-0g |
  | 701e912 | docs: record PR #86 in dev-cycle state |
  | d0c8ba7 | fix: fall back to cursor walk when an adapter can't fetch a single order（G1） |
  | 758afe7 | fix: normalize order sample before deriving products, customers, or fallback（G1） |
  | 14baa47 | docs: record gate round G1 for PR #86 |
  | b9a50d5 | docs: update dev-cycle state after G1 |
  | dcff667 | fix: normalize legacy persisted order samples when loading them（G2） |
  | 2400497 | docs: document the order-only cursor-walk fallback in the sample design（G2） |
  | 05bd8d2 | docs: record gate round G2 for PR #86 |
  | cb5139b | docs: update dev-cycle state after G2 |
  | fae12dd | fix: filter empty order IDs and validate adapter-returned order elements（G3） |
  | 85bd4c8 | docs: record gate round G3 for PR #86 |
  | ca6020a | docs: update dev-cycle state after G3 (gate rounds complete) |

## 設計ドキュメントからの逸脱

なし。`docs/10-tasks.md` R3-0g の対応方針（`ids` 一括取得ではなく `fetch_order_by_remote_id()` のループ）どおり。
ゲートラウンドで判明した「単一ID取得が未対応なASPへのカーソル走査フォールバック」は、既存の
`PlatformAdapter::fetch_order_by_remote_id()` 契約（docblockが元々許容していた `UnsupportedOperationException`）
を実装で初めて正しく扱った形であり、`docs/03-design-decisions.md` §10.2 4. に例外条件として明記した
（設計変更ではなく、既存契約と実装の整合を取ったもの）。

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 1（`SampleSet::$order_remote_ids` の上限・重複排除漏れ） | 1 | Low 2（フィクスチャの命名・テスト追加候補） |
| R2 | 新規指摘 0（検証: R1-1解消をmutate-check実測で確認） | 0 | 0 |

判定: R2でAPPROVE。詳細: `docs/reviews/feat/r3-0g-order-sample-id-fetch/R1.md`, `R2.md`

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex（1回目） | 1（P2） | 1 | 0 | 未収束（G2へ） |
| G1 | Copilot（1回目） | 2（Medium×2、うち1件Codexと同一） | 2 | 0 | 未収束（G2へ） |
| G2 | Codex（2回目） | 0 | 0 | 0 | **収束**（新規指摘0件） |
| G2 | Copilot（2回目） | 2（Medium 1・Low 1） | 2 | 0 | 未収束（G3へ） |
| G3 | Copilot（3回目・依頼上限） | 2（Medium×2、うち1件は本文指摘） | 2 | 0 | 依頼上限到達で終了 |

Codexは依頼2回で収束（G2で新規指摘なしを確認）。Copilotは3回全てで新規指摘があり、依頼上限（3回）に
達したため終了した。**「上限到達」は「指摘を出し尽くした」ことを意味しない可能性がある**ため、
次にできることに後日の `fix-copilot-review` 再確認を案内する。

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| R1-1 | 自己+独立サブエージェント | Medium | `order_remote_ids` の上限・重複排除漏れ | 6284fdf | - |
| G1-1 | Codex | P2 | `fetch_order_by_remote_id()` 未対応アダプタでのフォールバック欠如 | d0c8ba7 | [r4129334253](https://github.com/artisanworkshop/cart-bridge-jp/pull/86#discussion_r4129334253) |
| G1-2 | Copilot | Medium | 同上（G1-1と同一指摘） | d0c8ba7 | [r4129337564](https://github.com/artisanworkshop/cart-bridge-jp/pull/86#discussion_r4129337564) |
| G1-3 | Copilot | Medium | `SampleSelector` の正規化がproduct/customer抽出・`$used_fallback`判定より後 | 758afe7 | [r4129337652](https://github.com/artisanworkshop/cart-bridge-jp/pull/86#discussion_r4129337652) |
| G2-1 | Copilot | Medium | `load()` が既存永続サンプルの正規化をバイパス | dcff667 | [r4130115458](https://github.com/artisanworkshop/cart-bridge-jp/pull/86#discussion_r4130115458) |
| G2-2 | Copilot | Low | 設計文書がフォールバック例外を記載していない不整合 | 2400497 | [r4130115517](https://github.com/artisanworkshop/cart-bridge-jp/pull/86#discussion_r4130115517) |
| G3-1 | Copilot | Medium | `load()` の正規化が空の受注IDを除去しない | fae12dd | [r4130259364](https://github.com/artisanworkshop/cart-bridge-jp/pull/86#discussion_r4130259364) |
| G3-2 | Copilot（本文指摘） | Medium | `unique_orders()` が非`CanonicalOrder`要素・空番号を検証しない | fae12dd | なし（[review 5348469724](https://github.com/artisanworkshop/cart-bridge-jp/pull/86#pullrequestreview-5348469724)） |

### 修正しなかった指摘（PR上で未解決のまま残してある）

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| R1-L1 | 自己+独立サブエージェント | Low | `MockPlatformAdapter::$fetch_calls` と新設 `$fetch_orders_calls` の名前が紛らわしい | `docs/review-backlog.md` r3-0g-order-sample-id-fetch/R1-L1 |
| R1-L2 | 自己+独立サブエージェント | Low | 受注0件・404ケースの直接テスト欠如（order固有ロジックは無い） | `docs/review-backlog.md` r3-0g-order-sample-id-fetch/R1-L2 |

## 品質ゲート

- CI: [最終run](https://github.com/artisanworkshop/cart-bridge-jp/actions) green（PHP 8.2/8.3 quality, PHPUnit (wp-env), Dev tooling, JS/TS すべてpass）
- 品質チェック: `composer lint` / `composer analyze` / `composer test:wpenv`（1297件）/ `npm run lint` / `npm run build` すべてgreen（`quality.sh`）
- 全修正について `mutate-check.sh` でガードを戻すと対応するテストがCAUGHTになることを実測確認済み

## 次にできること（人間の判断）

- 保留分（Low 2件）の修正: `/dev-cycle fix R1-L1 R1-L2` または `/fix-copilot-review 86`（ただし backlog は
  `docs/review-backlog.md` 管理のためissue化が必要なら別途起票）
- **Copilotは依頼上限（3回）で打ち切った**（収束を確認したわけではない）ため、数時間後（別セッションでも
  よい）に `/fix-copilot-review 86` を実行し、遅れて届いた指摘が無いか確認することを推奨。Codexは収束済み
  （G2で新規指摘0件）のため再確認不要
- **運用上の注意点**（review-loop R1 で判明。マージ前にご確認ください）: この修正の前に無料版で受注の
  サンプル移行を実行済みの店舗では、（旧経路のカーソル走査で）サンプル外の受注も含めて `LimitPolicy` の
  累積枠（10件）を使い切っている可能性があります。修正後に再実行すると、真のサンプル受注が上限超過で
  全てスキップされることがあります。クリーンアップ→再選定（§10.2 #7）で回復できるため安全側に倒れて
  いますが、該当する店舗があればご案内が必要かもしれません
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
