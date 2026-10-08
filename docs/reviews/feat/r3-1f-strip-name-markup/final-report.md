# dev-cycle 最終報告: feat/r3-1f-strip-name-markup

## 開発内容
- タスク: R3-1f — ColorMe の商品名のタグを取込みで除く（backlog `r3-1-rerehearsal/F1`。2026-10-08 ユーザー決定）
- PR: #110 https://github.com/artisanworkshop/cart-bridge-jp/pull/110
- 承認された計画（`~/.claude/plans/enumerated-twirling-cherny.md`）の要約: ColorMe のストアフロントは商品名を見出しにエスケープせずに出すので、取込みで名前を表示どおりの文字にする
  （新設 `HtmlText::visible_text()`〔WP の HTML API〕・`Cast::product_name()`）。Canonical の契約（平文）は変えない。計画時のユーザー回答: 実体参照も戻す、警告は出さない。
- コミット（docs の記録を除く）:

| sha | メッセージ |
|---|---|
| `37a27ff` | feat: import ColorMe product names as the text the storefront displays |
| `2a46acd` | chore: check imported product names against the storefront text in the rehearsal |
| `97147e0` | fix: keep words apart at block element boundaries in product names（R1） |
| `6558557` | fix: leave unrendered element contents out of product names（G1） |
| `9a71b70` | fix: close hidden name contexts only with their own closing tag（G2） |

- 設計ドキュメントからの逸脱（PR 本文と同じ）:
  1. `docs/03` §10.2（R3-1b）・`.claude/rules/adapters-colorme.md` の「ColorMe の `name` は平文」を「ストアフロントで HTML として表示される。アダプタが表示どおりの文字にする」に改めた。
  2. `<` か `&` を含む名前は、空白の連続・前後の空白・セミコロンの無い古い実体参照でも変わり、取込み済みの商品は次の取込みで一度だけ書き直される（changelog に書くことを R3-3 に追記）。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High 0・Medium 1・Low 5・対象外 1 | Medium 1（ブロック要素の境目）・Low 4（文書の誤り・範囲・契約の例外・R3-3 の追跡） | Low 1・対象外 1 |
| R2（独立サブエージェントで検証） | R1 の 5 件すべて解消・新規 Critical/High 0・新規 Low 2 | — | Low 2 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留・対応不要 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2 | 2 | 0 | 未収束 |
| G1 | Codex | 2 | 1 | 対応不要 1（`<pre>` の空白） | 未収束（5 分応答なしで review コメントを自動投稿） |
| G2 | Codex | 1 | 1 | 0 | 未収束 |
| G2 | Copilot | 1（G2-1 と同じ） | 1 | 0 | 未収束 |
| G3 | Codex | 1 | 0 | 保留 1 | 依頼上限 |
| G3 | Copilot | 本文 1（Previously missed） | 0 | 保留 1 | 依頼上限 |

### 修正した指摘
| ID | bot | 内容 | コミット |
|---|---|---|---|
| G1-1 | Copilot | リハーサルの期待値で xmp・iframe・noembed・noframes の中身も除く | `6558557` |
| G1-2 | Copilot | docs/10 の古い件数 | `6558557` |
| G1-3 | Codex P2 | `<template>`・`<noscript>` の中身を名前から除く | `6558557` |
| G2-1・G2-2 | Codex P2・Copilot | 別の要素の閉じタグで template が閉じる → noscript は生のテキスト、template は自分の閉じタグでだけ閉じる | `9a71b70` |

### 修正しなかった指摘（ユーザー確認済み）
| ID | bot | 判定 | 理由 |
|---|---|---|---|
| G1-4 | Codex P2 | 対応不要（スレッドは未解決のまま） | Woo も名前を HTML として表示するので `<pre>` の空白を保っても 1 つに見え、改行を名前に残さない。理由を docblock・docs/03 に書きテストで固定 |
| G3-1 | Codex P2 | 保留（backlog） | noscript の中の属性値の `</noscript>`。元の文字列での位置を自分で探す必要があり、見える文字を落とす側・商品名に現れにくい |
| G3-2 | Copilot | 保留（backlog） | SVG・MathML の中の CDATA。Tag Processor は `#comment` として返す（指摘の前提は当てはまらない）。svg・math の入れ子を自分で追う必要がある |

## 品質ゲート
- CI: G2 修正後（`7c4dd10`）green。最終 push（記録のみ）の CI は下の追記で確認
- 品質チェック: `quality.sh` green（PHPUnit 1757・Jest 104・i18n）
- `mutate-check.sh`: 実装時 9 種・R1 4 種・G1 4 種・G2 5 種がすべて CAUGHT
- テストショップ: P56（装飾タグ・`<br>`・実体参照の名前）を投入し、管理者の条件の差分取込みと `reset-local` 後の WP-Cron の条件の全件取込みで、P12・P56 が表示どおりの名前で保存・他は `unchanged`・`check-import` の食い違い 0・再取込み全件 `unchanged`（R1 以降の修正は ColorMe の名前に該当する形が無く、テストショップでは再確認していない）

## マージ前にユーザーが確認すべき点
- `<` か `&` を含む名前の取込み済み商品は、次の取込みで一度だけ書き直される（Woo での手直しも上書き。v0.1.0 で検証中のサイトに該当する名前があれば影響する）。changelog は R3-3 で書く。
- エクスポート側（Woo の名前の `<…>` は ColorMe で HTML として解釈される）は既知の限界のまま。readme・FAQ への記載は R3-3。
- backlog に送った Low（R1-L1・R1-X1・R2-1・R2-2・G3-1・G3-2）。

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: R3-3（readme・FAQ。R3-1f の商品名の扱いと changelog の注意を含む）→ R3-5 → R3-4
- テストショップ（ZZR・ZZW・ZZU の商品・会員、P56、ゲスト注文）と開発サイトの後片付けはユーザー判断（お試し期限 2026-10-22）
