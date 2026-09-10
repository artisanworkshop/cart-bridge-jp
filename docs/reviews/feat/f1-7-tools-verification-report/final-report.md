# dev-cycle 最終報告: feat/f1-7-tools-verification-report

## 開発内容
- タスク: F1-7 ツール + 検証レポート（D16 サンプルクリーンアップ / リンク再構築、D17 移行後検証レポート）
- PR #34 https://github.com/artisanworkshop/cart-bridge-jp/pull/34
- 承認された計画の要約: `Woo\Tools\SampleCleanup`（mappings 起点・所有メタで実体を限定・予算バッチ）、`Woo\Tools\MappingRebuilder`
  （所有メタ主キーで再構築・cursor バッチ）、`Sync\VerificationReport` + `Support\Money`（`Importer` が累積する `remote_amount` と
  Woo 側実在受注の合計を突合）、REST 4 ルート、Tools タブ + Import 結果の検証レポート、テスト、`docs/03` §10.3/§10.4 実装詳細
- コミット一覧（main..HEAD）:
- a1c4130 docs: record dev-cycle gate round 3
- 8d2700c fix: address Codex gate round G3 on the F1-7 tools
- 39f3160 docs: record dev-cycle gate round 2
- d6ae540 fix: address Copilot/Codex gate round G2 on the F1-7 tools
- 564e599 docs: record dev-cycle gate round 1
- d699107 fix: address Copilot/Codex gate round G1 on the F1-7 tools
- 6c17ef6 docs: record PR #34 in the dev-cycle state file
- 83f16cf docs: record review-loop round R2 (APPROVE) for the F1-7 tools
- 9513c46 fix: address review-loop R2 findings for the sample cleanup tool
- 3cc726e docs: record review-loop round R1 for the F1-7 tools
- e2ce701 fix: address review-loop R1 findings for the F1-7 tools
- 3a0791a docs: record F1-7 tools and verification report design details
- d62fe79 feat: add Tools tab and verification report to the admin app (F1-7)
- 3f10b1d feat: add sample cleanup, mapping rebuild and verification report backend (F1-7)
- 設計ドキュメントからの逸脱（`docs/03` §10.3/§10.4 に記載済み）:
  - リンク再構築は SKU/email 突合ではなく各 Writer が書く所有メタ（`_cbjp_platform` + remote_id）を主キーにする
  - 顧客の削除可否は `_cbjp_created_by_import`（作成プラットフォームID）で判定し、作成済みアカウントがあるのに `delete_users` が無い実行者は 403
  - 受注の `meta_query` は HPOS 時のみ付け、両構成で取得後に所有メタを再検証
  - F1-6 持ち越し 3 件（R1-X1 cancel 競合 / R2-X1 run_id 再発見 / R3-X1 期限切れ dry-run の空 CSV）と G1-6（クリーンアップと import のロック）は
    別 issue 化（要判断として残置）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | High 4 / Medium 2 / Low 9（自己 + 独立サブエージェント） | High 4 / Medium 2 / Low 3 | Low 6 |
| R2 | 新規 Low 5（R1 全解消 → APPROVE） | Low 3 | Low 2 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 3 | 2 | 1（誤検知） | 未収束 |
| G1 | Codex | 10 | 8 | 2（設計変更 / Pro 最適化） | 未収束 |
| G2 | Copilot | 1 | 1 | 0 | 未収束 |
| G2 | Codex | 4 | 4 | 0 | 未収束 |
| G3 | Copilot | 0 | — | — | **収束** |
| G3 | Codex | 3 | 3 | 0 | 上限到達（再依頼なし） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 / G1-5 | Copilot / Codex | Medium | 予算枯渇でページ内ループを打ち切り | d699107 | [r3981243346](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981243346) / [r3981259768](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259768) |
| G1-3 / G1-12 | Copilot / Codex | Medium | プレビューを delete / unlink 別に（run と同じ判定） | d699107 | [r3981243433](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981243433) / [r3981259828](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259828) |
| G1-4 | Codex | High | 作成マーカーにプラットフォームIDを保持 | d699107 | [r3981259756](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259756) |
| G1-7 | Codex | Medium | 残存サンプルセットのクリア | d699107 | [r3981259788](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259788) |
| G1-8 | Codex | Low | Rebuild の cursor 保持 | d699107 | [r3981259794](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259794) |
| G1-9 | Codex | Medium | 旧ジョブの ASP 側合計を「不明」に | d699107 | [r3981259805](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259805) |
| G1-10 | Codex | Medium | 金額の桁あふれを null に | d699107 | [r3981259811](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259811) |
| G1-11 | Codex | High | 削除権限が無い場合は 403（顧客上限の回避防止） | d699107 | [r3981259819](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259819) |
| G2-1 | Codex | Medium | unlink 時に他プラットフォームの作成マーカーを残す | d6ae540 | [r3983653607](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983653607) |
| G2-2 | Codex | Medium | プレビューの variation 件数にカスケード分を含める | d6ae540 | [r3983653612](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983653612) |
| G2-3 | Codex | Medium | 通貨不一致を報告し金額突合を行わない | d6ae540 | [r3983653619](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983653619) |
| G2-4 / G2-5 | Codex / Copilot | Low | 通知文言の是正 | d6ae540 | [r3983653623](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983653623) / [r3983687875](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983687875) |
| G3-1 | Codex | Medium | 削除可否を不変の作成マーカーで判定 | 8d2700c | [r3983840554](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983840554) |
| G3-2 | Codex | Medium | 共有サムネイルは全参照タームが消える場合のみ削除 | 8d2700c | [r3983840557](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983840557) |
| G3-3 | Codex | Medium | 通貨判定を受注に保存された通貨で行う | 8d2700c | [r3983840560](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3983840560) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-2 | Copilot | — | 誤検知: `post_status => 'any'` はゴミ箱を返さない（実測）。CLAUDE.md に蒸留 | [r3981243401](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981243401) |
| G1-6 | Codex | Medium | クリーンアップと import 開始の共有ロックは Sync 層の設計変更を伴う → backlog（cancel 競合 R1-X1 と合わせて別 issue） | [r3981259777](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259777) |
| G1-13 | Codex | Low | `local_ids()` の全 ID 列挙は Pro 規模の最適化として backlog（R1-L2 と同系） | [r3981259837](https://github.com/artisanworkshop/cart-bridge-jp/pull/34#discussion_r3981259837) |

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/34535889829 green（JS/TS・PHP quality 8.2/8.3・PHPUnit wp-env）
- 品質チェック: green（PHPUnit 699 件、PHPCS / PHPStan level 6 / ESLint / tsc / `npm run build`）
- wp-env dev サイト上の REST 通し確認（検証レポート → プレビュー → mappings 削除 → 再構築 → 配列パラメータ拒否 → クリーンアップ）済み。
  ブラウザでの目視確認（Tools タブ / Import 結果の検証レポート）と ColorMe 実ショップでの往復確認は未実施（F1-8 で実施予定）

## 次にできること（人間の判断）
- 保留分の修正: `/dev-cycle fix G1-6`（ロック設計）または `/fix-copilot-review 34`
- 別 issue 化: G1-6（ロック）、F1-6 持ち越し 3 件（R1-X1 / R2-X1 / R3-X1）、backlog の Low 群（`docs/review-backlog.md`）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
