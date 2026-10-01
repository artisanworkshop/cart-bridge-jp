# dev-cycle 最終報告: fix/91-import-order-final-status

## 開発内容
- タスク: **R3-0o**（issue #91）— 受注のインポートで新規作成を最終ステータスで保存し、状態変化フックを発火させない
- PR: #92 https://github.com/artisanworkshop/cart-bridge-jp/pull/92（Closes #91）
- 経緯: R3-0n の版で実店舗の受注をインポートしたところ、プレビュー「作成 19」に対し本番は「作成 0・警告 24」、受注一覧は「支払い待ち (19)」なのに一覧が空。読み取り専用の診断（SSH＋WP-CLI）と wp-env の再現で、`wc_create_order( pending )`→`set_status()` の状態変化で他プラグインの完了時処理（請求書の PDF・メール〈メールは未送信〉、決済の売上確定）が全受注で動き、請求書プラグインの `Error` で商品の無い明細の受注が作っては消され、削除で件数キャッシュがずれていたことを確定した
- 承認された計画の要約: 新規は `new WC_Order()` を最終ステータスで 1 回だけ保存（`woocommerce_default_order_status` で状態変化を記録させない）。既存受注の更新は今回扱わない（ユーザー判断）

### 最終的な振る舞い
- 新規作成: 状態変化のフックは発火しない（保存の前後・`woocommerce_new_order`・明細の作成だけ）。件数キャッシュは最終ステータスに加算
- 作成後の後処理: Analytics の取込み予約（`woocommerce_schedule_import`。即時取込みモードの店舗向け）と、completed の `wc_paying_customer()`。失敗しても受注は消さずログへ
- 明細の保存の確認: 追加した明細に ID が付かなければ件数キャッシュを捨ててもう 1 回保存し、それでも欠ければ受注を消して例外（次回に再試行）
- 失敗時: 作られた行を削除し、件数キャッシュを捨てて数え直させる（`OrderCountCache::flush()`）。`save()` が ID 0 を返したら `ORDER_CREATE_FAILED`
- 既存受注の更新で状態が変わる場合: 従来どおり（backlog `fix-91/update-status-hooks`）

### コミット
| sha | メッセージ |
|---|---|
| `5aefd2d` | fix: create imported orders in their final status without transition hooks |
| `adc59c1` | docs: record issue #91 (order status hooks on import) findings and handling |
| `17d3069` | fix: schedule analytics and keep the order when post-create steps fail |
| `6a3351c` | docs: record review-loop R1 for #91 and correct the order-hook notes |
| `3302ff7` | docs: correct leftover notes on hooks fired when creating imported orders |
| `374962b` | docs: record review-loop R2 for #91 |
| `3997583` | docs: update commit references after rebuilding the #91 branch |
| `4430e3a` | fix: flush the order count cache after discarding a failed new order |
| `4dc4c83` | docs: record dev-cycle gate round 1 for #91 |
| `b45be30` | fix: make sure a newly created order actually saved its items |
| `e300a1d` | docs: record dev-cycle gate round 2 for #91 |
| `59edf83` | docs: record dev-cycle gate round 3 for #91 |

### 設計ドキュメントからの逸脱
- D10 #6「副作用は全て抑止」の実装を、新規作成で状態変化フックを発火させない形に広げた（`docs/03` に小節。D10 #6 の「`wc_create_order` 後に直接プロパティ設定」はこれで置き換え）
- 既存受注の更新で状態が変わる場合は対象外（ユーザー判断。backlog）
- 新規作成では本体の on-hold の売上件数の加算・refunded の全額返金レコード・フルフィルメントの自動作成も動かない（`docs/03` に列挙。refunded は backlog）
- 顧客 IP・ユーザーエージェントが空になる（従来はインポート実行環境の値で、元から誤り）
- push 前に、途中のコミットに入っていた実店舗の受注件数の内訳を履歴から取り除いた（最終的なファイルは不変）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Medium 2・Low 5・対象外 2 | Medium 2（Analytics から抜ける・後処理の失敗で重複作成）・Low 5 | 対象外 2 |
| R2（独立サブエージェントで検証） | R1 解消・新規 Low 2 | Low 2（docs） | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1 | 1 | 0 | 未収束 |
| G1 | Codex | 1（Copilot と同じ） | 1 | 0 | 未収束（自動レビューが 5 分で来ず自動で再依頼） |
| G2 | Copilot | 1（Low。判定 🟢） | 1 | 0 | 未収束 |
| G2 | Codex | 1（P1） | 1 | 0 | 未収束 |
| G3 | Copilot | 1（Low。判定 🟢） | 1（PR 本文） | 0 | 依頼上限（3 回） |
| G3 | Codex | 0 | — | — | **収束** |

### 修正した指摘
| ID | bot | 重大度（自分の判定） | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | 件数の加算（優先度 10）より前の `Error` で受注を消すと件数キャッシュが 1 件少なくなる | `4430e3a` | [r4151737581](https://github.com/artisanworkshop/cart-bridge-jp/pull/92#discussion_r4151737581) |
| G1-2 | Codex | Medium | G1-1 と同じ | `4430e3a` | [r4151766503](https://github.com/artisanworkshop/cart-bridge-jp/pull/92#discussion_r4151766503) |
| G2-1 | Copilot | Low | 台帳のテスト件数・ミューテーション数が古い | `b45be30` | [r4152362614](https://github.com/artisanworkshop/cart-bridge-jp/pull/92#discussion_r4152362614) |
| G2-2 | Codex | Medium〜High | `woocommerce_new_order` の `Exception` で明細が保存されないまま CREATED（この PR で入った後退） | `b45be30` | [r4152366986](https://github.com/artisanworkshop/cart-bridge-jp/pull/92#discussion_r4152366986) |
| G3-1 | Copilot | Low | PR 本文の件数が古い | PR 本文を更新 | [r4152452897](https://github.com/artisanworkshop/cart-bridge-jp/pull/92#discussion_r4152452897) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（全スレッド Resolve 済み）。

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36824996870 green（最終 HEAD `59edf83`。PHP quality 8.2/8.3・PHPUnit・JS/TS・Dev tooling）
- 品質チェック: green（PHPUnit 1349 件・Jest 55 件・lint・PHPStan・build）
- ミューテーション: 13 種（実装 5・R1 3・G1 1・G2 4）すべて CAUGHT
- mock（`mockv`）で他プラグイン役のフックを足した本番インポート: 5 項目 PASS（撤去済み）

## 次にできること（人間の判断）
- **Copilot の G3 は PR 本文の更新だけで、G2 の修正（`b45be30`）はその G3 で 🟢 を得ている**。Codex も `e300a1d` で指摘なし
- PR #90（R3-0n）と PR #92 は独立。`OrderWriterTest.php` と docs を両方が触るため、後からマージするほうで衝突を解消する可能性がある
- 実店舗: 両方をマージした版（または PR #92 の版）を入れ、先に `wp cache flush` で件数表示を直してから再インポートし、19 件が作成されることを確認する。WP Mail Logging に残っている前回の「請求書メール」の記録は、本プラグインが送信前に止めたもの（未送信）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
