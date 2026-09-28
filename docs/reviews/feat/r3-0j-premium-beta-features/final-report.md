# dev-cycle 最終報告: feat/r3-0j-premium-beta-features

## 開発内容
- タスク: R3-0j（`docs/10-tasks.md`）／設計判断 D24（`docs/03-design-decisions.md` §1・§10.2）。issue #75
- PR: [#84](https://github.com/artisanworkshop/cart-bridge-jp/pull/84)
- 承認された計画の要約（`~/.claude/plans/swirling-questing-pelican.md`）: `Capabilities::$beta_features` → `Support\ExportOptions`（`cbjp_export_options_{platform}`）→ REST `settings/export-options` → `Sync\Exporter` のチェックサムの印 → Export タブの Beta 表示、の順に実装。逸脱 2 点（`can_push_images` の意味の分離・チェックサムの印）は計画承認時にユーザー確認済み。
- コミット一覧（sha・メッセージ）:
  | sha | メッセージ |
  |---|---|
  | `271df23` | feat: make premium-only order export and image upload opt-in beta features (D24) |
  | `c25800d` | feat: show Beta labels and default premium-only export options to off in the Export tab (D24) |
  | `61dd919` | style: add spacing below the export options block (D24) |
  | `cd9b958` | chore: let the mock adapter template override capabilities; document seed keys and pitfalls |
  | `db9e7f7` | docs: record R3-0j (D24) implementation, deviations and lessons |
  | `1e2b2a7` | fix: warn about image overwrite, document the can_push_images contract, disable run buttons while saving (R1-1, R1-2, R1-L4, R1-L5) |
  | `ba15229` | docs: record the adapter contract and image overwrite, fix stale wording (R1-1, R1-2, R1-L2, R1-L3) |
  | `dd8c11b` | docs: align references and the push_product docblock with the reference implementation (R2-1, R2-2, R2-3) |
  | `d53c512` | docs: record review-loop R1/R2 and the dev-cycle state for R3-0j |
  | `e08f38e` | fix: accept a non-array beta_features from a misbehaving adapter (G1-1) |
  | `d4b727f` | fix: treat a null capabilities value in the mock adapter seed as off (G1-2) |
  | `22436c9` | fix: block Run export until the image-upload option finishes loading (G1-3) |
  | `6ced23a` | docs: record dev-cycle gate round G1 |
  | `81a2752` | docs: keep the dev-cycle state file in sync with the PR and gate rounds (G2-1) |
  | `c24ce5c` | docs: record dev-cycle gate round G2 |
  | `810ccba` | docs: fix a stale status column for an already-resolved backlog item (G3-3) |
  | `417b023` | docs: record dev-cycle gate round G3 |

## 設計ドキュメントからの逸脱（計画承認済み。要判断として残したものはなし）
1. **`can_push_images` の意味を分けた**: D24 本文は「`ColorMeAdapter::can_push_images()` を『プレミアム かつ 設定オン』にする」だったが、`capabilities()` が同メソッドを呼ぶため、設定オフの間は能力自体が false になり UI がオンにする手段を失う。能力（`capabilities()->can_push_images` ＝プランのみ）と実際に送るか（`should_push_images()` ＝プラン＋設定）を分けた。`docs/03` D24・`docs/10-tasks.md` を実装に合わせて更新済み。
2. **チェックサムの印**: D24 が「実装時に検討」と許容していた範囲だが、`Sync\Exporter` のコアに手を入れた（画像アップロードがオンの間だけ商品の checksum に印を混ぜ、既存の「一度オフで export すると二度と再送されない」制限を解消）。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Medium 2・Low 5・対象外 1 | Medium 2（外部アダプタ向け契約の明文化・画像上書き警告）＋ Low 4（ボタン無効化・翻訳文連結・コメント・docs 訂正） | Low 1・対象外 1 |
| R2（独立サブエージェントで検証） | 新規 Low 3（文書・コメントの参照ずれ） | Low 3 | 0 |

R2 判定: **APPROVE**（R1 の Critical/High/Medium は全解消、新規 Critical/High はゼロ）。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2（High 1・Medium 1） | 2 | 0 | 未収束 |
| G1 | Codex | 1（P1） | 1 | 0 | 未収束 |
| G2 | Copilot | 1（Low） | 1 | 0 | 未収束 |
| G2 | Codex | 0 | — | — | **収束** |
| G3 | Copilot | 3（本文指摘。Medium 2・Low 1） | 1（Low） | 0 | 依頼上限到達で終了（Medium 2 件は対応不要＝誤検知） |

Codex は「収束」（G2 で新規指摘が実際に 0 件）。Copilot は依頼 3 回目（上限）に到達し、そこで打ち切り。G3 の Medium 2 件は「未確認」ではなく実測して誤検知と確定させたため、後日の再確認は不要。

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | High（自己再判定 Medium） | `beta_features` が `array` 型のため非配列を返す外部アダプタで `/connections` が落ちる | `e08f38e` | [r4124197370](https://github.com/artisanworkshop/cart-bridge-jp/pull/84#discussion_r4124197370) |
| G1-2 | Copilot | Medium（自己再判定 Low） | mock テンプレートの `?? true` が `null` をキー欠損と同じ true に扱う | `d4b727f` | [r4124197469](https://github.com/artisanworkshop/cart-bridge-jp/pull/84#discussion_r4124197469) |
| G1-3 | Codex | P1（自己再判定 Medium） | 画像設定の読込前・失敗時も Run export が押せ、意図せず画像を送りうる | `22436c9` | [r4124225108](https://github.com/artisanworkshop/cart-bridge-jp/pull/84#discussion_r4124225108) |
| G2-1 | Copilot | Low | dev-cycle 状態ファイルが PR 作成前のまま古い | `81a2752` | [r4126794312](https://github.com/artisanworkshop/cart-bridge-jp/pull/84#discussion_r4126794312) |
| G3-B3 | Copilot | Low | backlog の対応済み項目のステータス欄が未起票のまま | `810ccba` | なし（本文指摘） |

### 修正しなかった指摘（対応不要と確定。PR 上に判断根拠を記録済み）
| ID | bot | 重大度 | 理由 | 記録 |
|---|---|---|---|---|
| G3-B1 | Copilot | Medium | 新設 REST テストが `cbjp_export_options_mock` をリセットしないとの指摘。WP コアの per-test ROLLBACK で option は毎回戻る。該当テストを問題の順序に一時的に並べ替えて実測し PASS を確認（誤検知） | `docs/reviews/.../G3.md` |
| G3-B2 | Copilot | Medium | 同上を `ExporterTest` に対して。同じ方法で実測し誤検知と確定 | 同上 |

## 品質ゲート
- CI: [run 36483504235](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36483504235) green（PHP 8.2/8.3・PHPUnit・JS/TS・Dev tooling）
- 品質チェック: green（`.claude/skills/cbj-dev-cycle/scripts/quality.sh`。PHPUnit 1288 件・PHPCS・PHPStan・JS lint/tsc・ビルド・dev-tooling テスト）
- ミューテーション: 実装時 15 件・G1 の追加ガード 2 件、すべて CAUGHT
- 実機確認: `/verify-with-mock-adapter`（mock アダプタ・モック HTTP・ブラウザ）で REST・JobManager 経由の checksum 挙動・実 `ColorMeAdapter::push_product()`・管理画面の表示と保存を確認。実 ColorMe API は未確認（D24 の方針どおり。ベータ版）

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- マージ前に確認してほしい点（PR 本文にも記載済み）:
  - 画像アップロードをオンにすると、ColorMe 側で同じ position の既存画像が Woo の画像で上書きされる（swagger 仕様。UI に説明文あり）
  - 受注・画像とも実 API では未検証（プレミアムのテストショップが用意でき次第、実テストを行う）
- backlog に送った項目（後日の判断・低優先度）:
  - `r3-0j-premium-beta-features/R1-L1`: checksum の印の絞り込み（画像の無い商品を除外、オフのときの再送を減らす）改善案
  - `r3-0j-premium-beta-features/R1-X1`: `get_connections()`・`JobManager` が `capabilities()` の例外を捕捉しない（既存のパターン）
