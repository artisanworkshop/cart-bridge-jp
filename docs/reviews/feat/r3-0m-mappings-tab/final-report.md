# dev-cycle 最終報告: feat/r3-0m-mappings-tab

## 開発内容
- タスク: R3-0m — 受注インポートの決済/配送マッピングを Import 側で設定・確認できるようにする（プレビュー警告の解消）
- PR: #89 https://github.com/artisanworkshop/cart-bridge-jp/pull/89
- 承認された計画の要約（`~/.claude/plans/reflective-wobbling-dragon.md`）:
  - 共有コンポーネント `MappingSettings` を切り出し、専用の Mappings タブ（`#/mappings`）に置く。Export タブには案内だけを残す
  - Import タブで受注を選ぶと、未マッピングの決済/配送方法の数を案内し、Mappings タブへリンクする（案内のみ）
  - dry-run CSV の `note` を、決済/配送の未マッピングで `mapping_required` にする
  - 文言・ドキュメント・mock テンプレートを実態に合わせる
- コミット一覧:

| sha | メッセージ |
|---|---|
| `129f47c` | feat: flag unmapped payment/shipping warnings as mapping_required |
| `802acec` | feat: add a Mappings tab and an unmapped-method notice on the Import tab |
| `21aeaed` | test: pin that inherited map values are not read as mappings |
| `c4f7582` | chore: let the mock adapter seed mapping candidates and order methods |
| `c2adf45` | docs: record R3-0m (Mappings tab and import pre-flight notice) |
| `0bbcc57` | fix: do not cache the checksum of orders imported with unmapped methods |
| `2cbd4bd` | fix: tell shop owners to map methods before importing orders |
| `2157dd0` | docs: record review-loop R1 for R3-0m |
| `cad2eb7` | fix: clarify the Mappings tab intro and a sync rule reference |
| `7c23cfa` | docs: record review-loop R2 for R3-0m (APPROVE) |
| `55065ac` | fix: keep the selected platform when a notice links to the Mappings tab |
| `eddb149` | docs: correct dev-cycle log times and record the platform handoff |
| `233c379` | docs: record dev-cycle gate round 1 |

- 設計ドキュメントからの逸脱:
  - **checksum の扱い（ユーザー承認済み）**: 計画の「両コードは checksum キャッシュも不変」から変更し、未マッピングで取り込んだ受注は checksum を保存しない（R1-1）。代償は、未マッピングのまま運用すると該当受注が毎回再処理されること。この変更より前に取り込んで checksum が保存済みの受注は直らない
  - 台帳に無い小さな追加: マッピング表の読み上げラベル・`<thead>`・行見出し、対応先 0 件時の案内、要再接続の区別、タブ間の platform の引き継ぎ（`#/mappings?platform=`）
  - 見送り（ユーザー決定）: ColorMe が削除済みの決済/配送方法を候補 API に含めるかは未実測のまま `docs/03` §6 に記録

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Medium 1・Low 6・対象外 1 | Medium 1・Low 5 | Low 1・対象外 1 |
| R2（独立サブエージェントで検証） | R1-1 解消・新規 Low 2 | Low 2 | 0 |

判定: R2 で APPROVE（`R1.md`・`R2.md`）。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 1（P2） | 1 | 0 | 未収束 → 再依頼 |
| G1 | Copilot | 本文 1（インライン 0） | 1 | 0 | 未収束 → 再依頼 |
| G2 | Codex | 0 | 0 | 0 | 収束 |
| G2 | Copilot | 0（🟢 Approval recommended） | 0 | 0 | 収束 |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Codex | P2（Low 相当） | 案内のリンクで Mappings タブへ移ると、選択中の platform が引き継がれない | `55065ac`, `eddb149` | [r4141659379](https://github.com/artisanworkshop/cart-bridge-jp/pull/89#discussion_r4141659379)（Resolve 済み） |
| G1-B1 | Copilot | 本文 | 状態ファイルのログにレビュー時刻より後の時刻が書かれていた | `eddb149` | なし（[review 5362451674](https://github.com/artisanworkshop/cart-bridge-jp/pull/89#pullrequestreview-5362451674)） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし。

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36681997256 green（HEAD `233c379`）
- 品質チェック: `quality.sh` green（PHPUnit 1,333 件・Jest 55 件・build）
- ミューテーション: PHP 4 種・JS 6 種がすべて CAUGHT
- 実機: mock（`mockv`）で dry-run の 2 警告と `mapping_required`、設定後の 0 件、管理画面の Mappings タブ・Import の案内・Export タブ・platform の引き継ぎを確認し、撤去済み（`inspect` が検証前と一致）

## マージ前に人間が確認すべき点
- **実 API での警告の解消は未確認**: テストショップ（非プレミアム）は受注 0 件で、API で受注も作れない。実 API で確認できたのは候補の取得・描画と Import の案内の件数まで。実店舗（1,237 件）の再 dry-run で 2 警告が消えることの確認は店舗側で行う
- checksum の扱いの変更は、この変更より前に本取込みした受注には効かない（Tools のサンプル削除→再取込み）

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 実店舗で Mappings タブから決済 5 種・配送 2 種を設定し、受注の再 dry-run で `payment_method_unmapped`/`shipping_method_unmapped` が 0 件になることを確認する
- 続くタスク: R3-0n（受注 dry-run の残りの警告）
