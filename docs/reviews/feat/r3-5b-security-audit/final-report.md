# dev-cycle 最終報告: feat/r3-5b-security-audit

## 開発内容
- タスク: R3-5b セキュリティ最終監査（無料版 + Pro。R3-4〔wordpress.org 申請〕の前）
- PR: #132 https://github.com/artisanworkshop/cart-bridge-jp/pull/132
- 計画（`~/.claude/plans/shiny-wishing-galaxy.md`。2026-10-13 承認）: 計画時に読み取りだけで監査し（独立エージェント 3 体・Plugin Check・composer/npm audit。WC 11.2.0・WP の実ソースで裏取り）、見つかった問題を直して `docs/03` §10.9 に記録する。
  ユーザー決定: 例外の `ExceptionNotEscaped` をこの PR で直す・OAuth コールバックのエラーをコード化・Pro のメールで採用したアカウントは紐づけだけ・ライセンス操作の権限は今のまま
- 主なコミット:
  - b40df86 fix: harden imported product data, outbound API calls, and the OAuth callback
  - 8cefd12 fix: escape or annotate exception messages for Plugin Check
  - fa478f1 fix: link existing WordPress users without overwriting them
  - d9c8245 feat: show OAuth callback results from fixed codes
  - f80fe80 refactor: drop the edit link scheme check that esc_url_raw() repeats
  - b43287e・c68a9a3（review-loop R1）・ed1cd87（PO の末尾の改行）・da83123（review-loop R2）
  - 96663c1（G1-1）・b796c93（G2-2）・0439c23（G3-1）・f467f1b（G4 のテストの補強）
  - docs: 592abd6・5c498ab・1ec7d9d と各ゲートラウンドの記録
- 設計ドキュメントからの逸脱（PR 本文と同じ）: Pro の顧客の採用は紐づけだけ（従来は上書き）・保護の判定を許可リストへ・OAuth コールバックのエラーをコードで渡す・`phpcs.xml.dist` で `ExceptionNotEscaped` を有効化（いずれもユーザー決定か計画で合意）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント opus） | Critical/High 0・Medium 2・Low 11・対象外 1 | Medium 2・Low 7 | Low 4（docs に記録）・対象外 1 |
| R2（独立サブエージェントの検証。変異 6 件） | R1 はすべて解消・新規 Low 3・既存の候補 4 | Low 3・extras のキーが印を書き換えうる点 | 3 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex（1 回目） | 1 | 1 | 0 | 未収束 |
| G2 | Copilot（1 回目。UI から依頼） | 2 | 1 | 0（対応不要 1） | 未収束 |
| G3 | Codex（2 回目） | 1 | 1 | 0 | 未収束 |
| G4 | Codex（3 回目）・Copilot（2 回目。UI から依頼） | Codex 1・Copilot 0 | テストの補強 1 | 1 | Codex: 上限 / Copilot: **収束** |

Copilot の依頼は `gh pr edit --add-reviewer @copilot` と REST の `requested_reviewers` の両方で登録されず（timeline・GraphQL に依頼のイベントが出ない）、2 回ともユーザーが PR 画面から依頼した。

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Codex | P2 | サイトが `image_sideload_extensions` に SVG を足していると最初の `media_sideload_image()` が SVG を保存しうる | 96663c1（G3-1 で置き換え） | [r4254220146](https://github.com/artisanworkshop/cart-bridge-jp/pull/132#discussion_r4254220146) |
| G2-2 | Copilot | Medium | エクスポートで後から外した顧客が件数に残る | b796c93（止める理由つきで返す） | [r4254398743](https://github.com/artisanworkshop/cart-bridge-jp/pull/132#discussion_r4254398743) |
| G3-1 | Codex | P2 | 画像の拡張子で中身が画像でないファイルを最初の `media_sideload_image()` が受け付けうる | 0439c23（取得を中身を確かめる 1 つの経路に） | [r4254478233](https://github.com/artisanworkshop/cart-bridge-jp/pull/132#discussion_r4254478233) |
| G4-B1 | Copilot | （G2-1 の再掲） | 「テストで競合を検出できない」→ 2 つのコールバックを重ねるテスト | f467f1b | 本文のみ |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G2-1 | Copilot | High（Copilot） | 誤検知: `delete_option()` は DELETE が 0 行なら false を返すので、並行した 2 要求のうち通るのは片方だけ（WP の実ソースで確認。G4 で実際に重ねるテストを足した） | [r4254398714](https://github.com/artisanworkshop/cart-bridge-jp/pull/132#discussion_r4254398714) |
| G4-1 | Codex | P2 | 保留（backlog `r3-5b-security-audit/stale-axis-key`。R3-5b より前の商品・`<` を含む軸名・価格不正のバリエーションが重なるときだけ） | [r4254817432](https://github.com/artisanworkshop/cart-bridge-jp/pull/132#discussion_r4254817432) |

## 品質ゲート
- 品質チェック（`quality.sh`）: green（無料版 PHPUnit 1367 件・Pro 867 件・Jest 111 件・lint〔`ExceptionNotEscaped` を有効にした状態〕・PHPStan・`i18n:check`・dev マウント 403）。G4 のテストの補強の後は `ColorMeOAuthTest` 39 件と lint で確認
- CI: 最終 HEAD 7653d00 で green（https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38348194541）。その後に届いたレビュー・スレッドは無い
- ガードの変異: 実装時 38 か所のうち 37 か所が検出（残る 1 か所は重複した判定だったので外した）、review-loop・ゲートの修正の変異もすべて検出（等価な変異 1 件は別の変異で確認）
- dev サイトのストアフロント（Twenty Twenty-Five）: 修正前と同じ値の商品は SKU のブロックが `<script>` をそのまま出し、修正後の商品は出さないことを確認（G3-1 の画像の取得の変更より前に実施。画像の取得の変更は単体テストで確認）
- 配布 zip を展開して Plugin Check: 無料版 ERROR 0、Pro は `no_plugin_readme` だけ（G3-1 より前の zip。G3-1 は例外・出力に関わらない）

## 次にできること（人間の判断）
- 保留の G4-1: `/cbj-dev-cycle fix G4-1`（backlog の `stale-axis-key`。小さくない）
- G2-1（誤検知として未解決のまま）: 納得できれば PR 画面で Resolve
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- R3-4 への申し送り（`docs/10` R3-4）: Plugin Check の警告 `load_plugin_textdomain()`・`missing_composer_json_file` をどうするか
