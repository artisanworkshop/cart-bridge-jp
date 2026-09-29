# dev-cycle 最終報告: fix/55-pro-upsell-breakdown

## 開発内容
- タスク: R3-0h Pro 案内の件数を正確にし、Pro への言及を購入 URL の有無で切り替える（issue #55）
- PR: #87 https://github.com/artisanworkshop/cart-bridge-jp/pull/87
- 承認された計画の要約（`docs/03` §10.3「アップセル表示」の決定どおり）:
  - totals に `unchanged` を追加し、dry-run の `created + updated + unchanged` を「移行できる件数」にする
  - 「未移行」と「どの版でも移行できない」を分けて表示する
  - Pro 版への言及は、新設フィルター `cbjp/limits/pro_url` が有効な URL を返すときだけ出す
  - 計画時の決定: 在庫・レビューは内訳を出さない／未移行 0 件なら通知しない／JS の単体テストを Jest で追加する

### コミット一覧

| sha | メッセージ |
|---|---|
| 65df3a0 | fix: count unchanged items in run totals and expose a validated Pro URL (#55) |
| 902cad3 | fix: separate not-yet-migrated from unmigratable items in the upsell notice (#55) |
| 0d5fdff | docs: record R3-0h upsell breakdown implementation (#55) |
| 7b00356 | fix: hide the stock upsell line once no migratable products remain (#55)（R1-1〜R1-4） |
| 8a1214e | docs: record review-loop R1 for R3-0h (#55) |
| 64c1bea | test: pin the stock line when the product breakdown is unknown (#55)（R2-1・R2-2） |
| 85a631c | fix: drop stale dry-run counts when a new preview starts (#55)（G1-1〜G1-3） |
| 9c8ff42 | docs: record dev-cycle gate round 1 for #55 |
| 381b7a8 | docs: update the R3-0h test counts after gate round 1 (#55)（G2-1） |
| b355f73 | docs: record dev-cycle gate round 2 for #55 |

### 設計ドキュメント（`docs/03` §10.3）からの逸脱
1〜3 はユーザーの確認を得ています。詳細は `docs/03` の「実装（R3-0h）」1〜10。

1. 在庫・レビューは内訳を出さない。行を出すのは、商品に「移行できるが未移行」が残っているとき（R1-1）か、商品の内訳が不明なときだけ
2. 「未移行」0 件なら行を出さない
3. JS 単体テスト基盤を追加した（`@jest/globals`・`npm run test:js`・CI／`quality.sh`）
4. Pro への言及は見出しだけに置く
5. `unchanged` の無い導入前のジョブは、従来の「上限に到達」文言へフォールバックする。更新をまたいだジョブは既知の制限
6. `/limits` の `platform` に `args` スキーマを追加した
7. WP 7.1 コアの `ExternalLink` は `rel` を付けないため、明示した

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 1・Low 3（自己レビューは違反なし。独立サブエージェントが検出） | 4（R1-3 は docs に既知の制限として記録） | 1（R1-4 の残り: コンポーネント描画テスト） |
| R2 | 新規 Low 2（R1 の 4 件はすべて解消 → APPROVE） | 2 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（自動レビューが 5 分で発火せず、自動で 1 回再依頼） |
| G1 | Copilot | 3（Medium 2・Low 1） | 3 | 0 | 未収束 |
| G2 | Copilot | 1（Low） | 1 | 0 | 未収束（判定は Approval recommended） |
| G3 | Copilot | 0 | 0 | 0 | 収束（依頼上限の 3 回目） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | Export: 新しい dry-run の一部のジョブが失敗・キャンセルしたとき、前回の件数が残る → 開始時に対象エンティティの件数を捨てる（`withoutDryRunTotals()`） | 85a631c | [r4132244976](https://github.com/artisanworkshop/cart-bridge-jp/pull/87#discussion_r4132244976) |
| G1-2 | Copilot | Medium | Import: G1-1 と同じ | 85a631c | [r4132245029](https://github.com/artisanworkshop/cart-bridge-jp/pull/87#discussion_r4132245029) |
| G1-3 | Copilot | Low | 「移行できない」の案内先に Logs タブを追加（remote_id 欠損は CSV に行が無い） | 85a631c | [r4132245075](https://github.com/artisanworkshop/cart-bridge-jp/pull/87#discussion_r4132245075) |
| G2-1 | Copilot | Low | docs/10-tasks.md の Jest 件数を 33 件に更新 | 381b7a8 | [r4132494400](https://github.com/artisanworkshop/cart-bridge-jp/pull/87#discussion_r4132494400) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし

## 品質ゲート
- CI: 最終 HEAD（b355f73）で全ジョブ green（PHP quality 8.2/8.3・PHPUnit (wp-env)・JS/TS〔Jest を含む〕・Dev tooling）
- 品質チェック（`quality.sh`）: green（PHPUnit 1324 件・Jest 33 件）
- `mutate-check.sh`: PHP 8 種・JS 11 種がすべて CAUGHT
- 実機（mock アダプタ `mockv`・Export タブ）での確認:
  - `pro_url` 空: Pro 版に触れず、内訳を表示する
  - `pro_url` 設定時: 見出しに Pro 版へのリンク（`target="_blank" rel="noopener noreferrer"`）が付く
  - 検証データはすべて撤去した
  - 実 ColorMe API では未確認

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- マージ前の確認:
  - UI 文言が変わる（英語。日本語訳は R3-2 の `ja.po`）
  - Pro 版の販売開始時に `cbjp/limits/pro_url` で購入ページの URL を返すと、見出しに案内とリンクが出る（既定では出ない）
- backlog: `fix-55-pro-upsell-breakdown/R1-L4`（コンポーネント描画テスト。新しい devDependency が要る）
- ツールの気づき: グローバルの `review-loop` の `mutate-check.sh --only` は、Jest の `FAIL <ファイル名>` 行を失敗の見出しとして数えるため、狙ったテストだけが落ちても「NOT CAUGHT CLEANLY」と出る（今回は `--only` なしで一覧を確認して回避した）
