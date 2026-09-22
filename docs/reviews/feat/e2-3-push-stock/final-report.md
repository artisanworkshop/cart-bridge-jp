# dev-cycle 最終報告: feat/e2-3-push-stock

## 開発内容

- タスク: issue #47 `ColorMeAdapter::push_stock()` 実装（E2-3 PR-D、Phase 2最後のピース）
- PR: #51 https://github.com/artisanworkshop/cart-bridge-jp/pull/51
- 承認された計画の要約: ColorMeには在庫専用の書込みエンドポイントが無いため、商品/バリエーション
  更新APIを叩いてpush_stockを実装する。単純商品は`stock_managed`+`stocks`のPUT、バリエーションは
  `stocks`のみのPUT。バリエーションの管理外（`quantity=null`）はColorMe側に伝える手段が無いため
  APIを呼ばずフェイルクローズしてスキップする（新規`WarningCode::STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE`）。
- コミット一覧:
  | sha | メッセージ |
  |---|---|
  | 786b550 | feat: add ColorMe push_stock() (E2-3 PR-D) |
  | b2b72ea | docs: record E2-3 PR-D (push_stock) design and task completion |
  | 7828f86 | docs: clarify push_stock known limitations and add stock_managed test (R1-1, R1-3, R1-5, R1-6) |
  | 18449af | docs: correct verification item #19 to match swagger's actual claim (R2-1) |
  | 2cf5ba6 | docs: record R2 review round resolved HEAD |
  | 4f65550 | docs: record dev-cycle step 3 completion |
  | 82d349c | docs: record PR #51 creation in dev-cycle state |
  | a0af11b | docs: record CI green in dev-cycle state |
  | f2d6c71 | fix: explicitly enable product-level stock_managed before variant stock push (G1-2) |
  | 6653614 | docs: record dev-cycle gate round G1 |
  | a8e4e5f | docs: record G1 gate round completion in dev-cycle state |
  | a3d5c3c | docs: fix stale single-request wording after G1's two-PUT variant path (G2-1..G2-3) |
  | 69f7d8c | docs: record dev-cycle gate round G2 |
  | fbbaab1 | docs: record G2 gate round completion in dev-cycle state |

## 設計ドキュメントからの逸脱

なし。`docs/03-design-decisions.md` §10.2に新規小節「E2-3 PR-D」を追加した（既存ドキュメントへの
追記のみ、既存方針との矛盾なし）。

要判断として残した設計判断（1件）: G1ゲートでCodex/Copilotが独立に、バリエーション在庫push設計の
同じ弱点（ColorMeの「全バリエーション未設定の状態で1件でも値を送ると他バリエーションが0になる」
副作用を回避する仕組みが無い）を指摘。ユーザーに選択肢を提示し「Codexの分だけ最小修正」を選択:
- Codexの懸念（商品全体の`stock_managed`フラグがfalseのままバリエーションの`stocks`だけ送ると
  反映されない可能性）→ バリエーションpush前に商品側へ`stock_managed:true`を明示PUTするよう修正
- Copilotの懸念（兄弟バリエーションが恒久的に0のまま補正されないリスク）→ 根本対応は
  `Sync\Exporter`の1アイテムずつのpushループを見直す設計変更が必要でPRの差分範囲を大きく超えるため
  対応を見送り、`docs/review-backlog.md`（`e2-3-push-stock/G1-1`）へ記録

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | 6件（Medium 2・Low 4。独立サブエージェント併用） | 3件修正・1件は検証のうえ対応不要と判断・2件backlog送り | R1-L1, R1-L2, R1-L3 |
| R2 | 1件（Low、ドキュメント記述精度） | 1件修正、APPROVE | なし |

詳細: `docs/reviews/feat/e2-3-push-stock/R1.md`・`R2.md`

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1件（Medium） | 0 | 1（ユーザー判断） | 未収束 |
| G1 | Codex | 1件（Medium） | 1 | 0 | 未収束 |
| G2 | Copilot | 3件（Low） | 3 | 0 | 未収束 |
| G2 | Codex | 0件 | - | - | **収束** |
| G3 | Copilot | 0件（コード）・1件（PR本文） | 1（PR本文更新） | 0 | **収束** |
| G3 | Codex | 未依頼（G2で収束済み） | - | - | 収束 |

状態は「収束」（新規指摘が実際に0件だった）で確定。

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-2 | Codex | Medium | 商品側`stock_managed`を明示化せずバリエーション在庫だけ送ると反映されない可能性 | f2d6c71 | [#r4077418923](https://github.com/artisanworkshop/cart-bridge-jp/pull/51#discussion_r4077418923) |
| G2-1 | Copilot | Low | `push_stock()`docblockが単一リクエストのままG1と矛盾 | a3d5c3c | [#r4077577102](https://github.com/artisanworkshop/cart-bridge-jp/pull/51#discussion_r4077577102) |
| G2-2 | Copilot | Low | `docs/03-design-decisions.md`冒頭要約がG1と矛盾 | a3d5c3c | [#r4077577139](https://github.com/artisanworkshop/cart-bridge-jp/pull/51#discussion_r4077577139) |
| G2-3 | Copilot | Low | `docs/10-tasks.md`台帳記述がG1と矛盾 | a3d5c3c | [#r4077577164](https://github.com/artisanworkshop/cart-bridge-jp/pull/51#discussion_r4077577164) |
| G3-1 | Copilot | Low | PR本文がG1/G2に追随していない | `gh pr edit`（コミット無し） | 本文指摘（スレッド無し） |

### 修正しなかった指摘（PR上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Copilot | Medium | 根本対応（同一商品の全バリエーションをまとめて扱う設計変更）はPRの差分範囲を大きく超えるため、ユーザー判断のうえ見送り。`docs/review-backlog.md`（`e2-3-push-stock/G1-1`）に記録 | [#r4077391197](https://github.com/artisanworkshop/cart-bridge-jp/pull/51#discussion_r4077391197)（返信済み。Copilotが再レビュー時に自動でresolve済みだが判断・理由はbacklogに記録） |

## 品質ゲート

- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/35797817310 green（JS/TS・PHP quality 8.2/8.3・PHPUnit wp-env すべてpass。以降の docs-only push でも再確認済み）
- 品質チェック: green（`composer lint && composer analyze && composer test:wpenv`。PHPUnit 1076件）

## 次にできること（人間の判断）

- **マージ判断**: GitHub上で人間が行う → マージ後は `/post-merge`
- **保留分の扱い**: `e2-3-push-stock/G1-1`（Copilot、backlog記録済み）はフォローアップissue化するか
  判断が必要。根本対応（同一商品の全バリエーションをまとめてpushする設計変更）はE2-3の範囲を
  超えるため、対応するなら別issueを起票することを推奨
- **実店舗確認**（保留事項）:
  - 要検証#19（`docs/03-design-decisions.md` §9）: バリエーションの`stock_managed`明示PUT→
    `stocks`PUTの2リクエスト構成が実店舗で意図どおり動くこと自体の確認は、今後の実機確認
    （E2-4/R3-1、ColorMe認証情報待ち）で行う
- 両botとも「収束」（TIMEOUTでの打ち切りではなく実際に新規指摘0件を確認）のため、後日の
  `fix-copilot-review`による再確認は不要
