# dev-cycle 最終報告: feat/r3-6d-readme-free-scope

## 開発内容
- タスク: R3-6d readme・スクリーンショット・i18n を無料版の範囲に書き直す（`docs/10-tasks.md`。D27・`docs/03` §10.0 決め残し 5・7）。これで R3-6（a〜d）が完了
- PR: #119 https://github.com/artisanworkshop/cart-bridge-jp/pull/119
- 承認された計画の要約: readme（短い説明・Description・Import/Export・Before and after・External services・Installation・FAQ・キャプション・changelog）を無料版の実装（カテゴリ・グループ・商品・在庫。エクスポートは商品・在庫・商品画像の Beta）に合わせ、`== Upgrade Notice ==` を新設。
  ヘッダー・`composer.json` の Description と POT・ja を更新。`ReadmeTest` に無料版の範囲・Pro の FAQ・Upgrade Notice の検査を追加。スクリーンショットを Pro 無効で撮り直し（2〜4 を差し替え）、`wporg-screenshots` を無料版だけで撮る形にした
- ユーザー決定（計画時）: Pro への案内は readme の Description 1 文と FAQ 1 件（URL は Pro の公開準備で足す。`ReadmeTest` が 1.0.0 以上で止める。管理画面には出さない）。0.1.0 のサイト向けは changelog と Upgrade Notice
- コミット:
  - `73a2b43` feat: describe the free plugin's product-only scope in the readme (R3-6d)
  - `e48d046` chore: retake the wordpress.org screenshots without the Pro add-on (R3-6d)
  - `e5a4a0c` docs: record R3-6d (Pro guidance and the 0.1.0 upgrade notes)
  - `f7e4a47` fix: correct the readme's 0.1.0 notes and connection triggers (R3-6d R1)
  - `d8ede73` docs: record review-loop R1 for R3-6d
  - `5a4da38` fix: cover the round-trip and uninstall FAQs in the free-scope check (R3-6d R2)
  - `83a57a3` docs: record review-loop R2 for R3-6d
  - （この記録のコミット）
- 設計ドキュメントからの逸脱: `docs/03` §7 の「購入 URL が無い間は Pro に触れない」を改め、URL が無いまま Pro の名前に触れる（計画時のユーザー決定。§7・§10.0 を更新済み）。0.1.0 の県・税区分の誤りは「手で直す」案内だけ（修復ツールは R3-6a で廃止済み）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立 opus） | Medium 1・Low 6・対象外 2 | 8（Medium 1・Low 6・対象外 1） | 1（`r3-6d-readme-free-scope/R1-X1` 翻訳者向けコメントの例） |
| R2（独立 opus の検証） | R1 全件解消・新規 Low 4 | 4 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 0（🟢 Approval recommended・0 open findings） | 0 | 0 | 収束 |
| G1 | Codex | 0（「Didn't find any major issues」・👍） | 0 | 0 | 収束 |

### 修正した指摘
なし（G1 で両 bot とも指摘 0 件）

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38065215932 全ジョブ green（PHP quality 8.2/8.3・PHPUnit・JS/TS・Distribution・Dev tooling）
- 品質チェック: `quality.sh` green（PHPUnit 無料版 1241・Pro 589、Jest 91、`i18n:check`、スクリプトテスト）
- Plugin Check（`plugin_readme`・`plugin_header_fields`・`trademarks`）エラー・警告 0、`bin/build-zip.sh` OK
- `ReadmeTest` の新しいガードは `mutate-check.sh` で、旧文言を各箇所に戻す変異がすべて CAUGHT。撮影の Pro ガードは Pro を有効にして止まることを実測

## 次にできること（人間の判断）
- マージ前の確認: readme の英文（特に changelog の 0.1.0 向けの項目と Upgrade Notice）と、差し替えたスクリーンショット 2〜4 の見た目
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: Pro の公開準備（readme の Pro の FAQ に販売サイトの URL を足すことを含む）→ R3-4・R3-5
