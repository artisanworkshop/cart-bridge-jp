# dev-cycle 最終報告: feat/r3-1-e2e-rehearsal

## 開発内容
- タスク: **R3-1 全件 E2E リハーサル**（`docs/10-tasks.md`）／PR #103 https://github.com/artisanworkshop/cart-bridge-jp/pull/103
- 承認された計画（`~/.claude/plans/velvet-roaming-allen.md`）の要約: カラーミーのテストショップ（非プレミアム・税抜・四捨五入）と wp-env の開発サイトで、インポート全般 → 同じショップへの往復エクスポート → Woo 生まれのデータの作成エクスポートを回し、
  ColorMe 側のスナップショットの差分でデータの欠損を確かめる。D16（サンプル → 上限解除 → 本移行の重複なし）、R3-0a/b（mock）、`option_market_price` の税基準、hidden 安全策（issue #78）も確認する。
  道具はプロジェクトスキル `rehearse-colorme` として残す。**プラグイン本体は変えず、見つかった不具合の修正は所見ごとに別 PR**（ユーザー決定）
- 結果の要点（詳細は `rehearsal.md`）:
  - インポート: サンプル → キャンセル → 上限解除の本移行 → 再取込みで重複なし、値は ColorMe と一致（D16 を満たす）
  - 往復エクスポート: 空の型番に仮 SKU、**会員限定販売が全員に販売可能に**、在庫未設定が 0、説明の埋め込みの消失、継承価格の明示化、商品名の `&amp;` → **D25（実体は作られた向きにだけ更新する）を決定、R3-1a（#98）**
  - 商品名の kses がランナーによって変わる（R3-1b、#99）、海外会員の更新が 422（R3-1c、#100）、説明の script/style の中身が残る（R3-1c、#101）、
    `zero-rate` の商品が次の更新で課税商品として公開（R3-1d、#78 を v1.0 に含める）、日本語でインストールした Woo の軽減税率の税区分（R3-1e、#102。要判断）
  - `option_market_price` は表示されず税基準は判定不能・影響なしとして要検証を閉じた。mock では R3-0a/b とも ALL PASS
- コミット一覧:

| sha | メッセージ |
|---|---|
| b5a2419 | feat(dev-tooling): add rehearse-colorme skill for the full ColorMe E2E rehearsal |
| e03b614 | feat(dev-tooling): add a mock example for pushes interrupted after the create (R3-0a) |
| 435d2a6 | docs: record the R3-1 rehearsal results and follow-up tasks |
| 8b47f3d | fix(dev-tooling): tighten the rehearsal checks and failure handling (R1) |
| e60564e | docs: record review-loop R1 for the R3-1 rehearsal |
| b31218e | fix(dev-tooling): align the rehearsal checks with the import rules (R2) |
| 3800da9 | docs: record review-loop R2 for the R3-1 rehearsal (APPROVE) |
| 4b89d74 | docs: update the dev-cycle state for R3-1 before the first push |
| a55cc7e | fix(dev-tooling): make the rehearsal scripts fail closed (G1) |
| bbfc989 | docs: record dev-cycle gate round 1 |
| bdfad8e | fix(dev-tooling): close the remaining gaps in the rehearsal scripts (G2) |
| 6fa68ea | docs: record dev-cycle gate round 2 |
| 2ac4d3f | fix(dev-tooling): keep rehearsal output off HTTP and close check gaps (G3) |
| b18544e | docs: record dev-cycle gate round 3 |

- 設計ドキュメントからの逸脱:
  - D16 の「開始時に上書きポリシー（更新／スキップ）を選ぶ」は v1.0 では実装しない（ユーザー決定。docs/03 を改訂、backlog `r3-1-e2e-rehearsal/D16-overwrite-policy`）
  - D25（往復の扱い）を新設（ユーザー決定。実装は R3-1a）。出自の判定は `_cbjp_platform`＋`_cbjp_remote_id` だけでは足りない（review-loop R1-4）
  - issue #78 を v1.0 に含める（ユーザー決定）。ただし #102 の方針を先に決める必要がある

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical 0・High 0・Medium 4・Low 12＋自己 2・対象外 2 | Medium 4・Low 12 | Low 2・対象外 2 |
| R2（検証） | R1 の Medium をすべて解消・新規 Low 6 | 6 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 5 | 4 | 0（対応不要 1。ユーザー承認のうえ Resolve） | 未収束 |
| G1 | Codex | 2 | 2（1 件は Copilot と重複） | 0 | 未収束 |
| G2 | Copilot | 2＋本文 3 | 5 | 0 | 未収束 |
| G2 | Codex | 2 | 2 | 0 | 未収束 |
| G3 | Copilot | 1＋本文 3 | 4 | 0 | 依頼上限（3 回）。G3 の修正は再レビューを受けていない |
| G3 | Codex | 0 | — | — | **収束**（「Didn't find any major issues」） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | `check-import` が MISMATCH・MISSING で終了コード 1 | a55cc7e | [r4180616224](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180616224) |
| G1-2 | Copilot | Medium | `run` がジョブの失敗・未完了で終了コード 1 | a55cc7e | [r4180616249](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180616249) |
| G1-3 | Copilot | Medium | 在庫管理の欠損を取込みと同じく管理ありとして照合 | a55cc7e | [r4180616265](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180616265) |
| G1-4・G1-6 | Copilot・Codex | Medium | 上限の保存を読み直して確かめる | a55cc7e | [r4180616284](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180616284)・[r4180637083](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180637083) |
| G1-7 | Codex | Medium | 初期化でオプションが消えたかを確かめる | a55cc7e | [r4180637087](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180637087) |
| G2-1 | Copilot | Medium | 処理中のページがある間は初期化しない | bdfad8e | [r4180877848](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180877848) |
| G2-2 | Copilot | Medium | 在庫管理の照合でフラグと期待数量の両方を見る | bdfad8e | [r4180877874](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180877874) |
| G2-3 | Codex | Medium | キャンセルの失敗・キャンセル後に開いたジョブで失敗にする | bdfad8e | [r4180887979](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180887979) |
| G2-4 | Codex | Medium | 検証レポートの取得失敗で失敗にする | bdfad8e | [r4180887984](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4180887984) |
| G2-B1〜B3 | Copilot（本文） | Medium | 負の上限の拒否（設定・mu-plugin）、会員の id の無い応答を失敗に | bdfad8e | なし（[review 5410184500](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#pullrequestreview-5410184500)） |
| G3-1 | Copilot | High | スナップショットが HTTP で読めた（実測 200）→ Apache の拒否を置き、読めないことを確かめてから動く | 2ac4d3f | [r4181105451](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#discussion_r4181105451) |
| G3-B1〜B3 | Copilot（本文） | Medium | バリエーションの親の照合、差分に追加・削除を数える、example の前提の書込みの確認 | 2ac4d3f | なし（[review 5410452944](https://github.com/artisanworkshop/cart-bridge-jp/pull/103#pullrequestreview-5410452944)） |

### 修正しなかった指摘（PR 上で未解決のまま残してあるもの）
なし。G1-5（`post_status` の配列の `any`）は誤検知（`class-wp-query.php` 2667 行・実測）として、ユーザー承認のうえ根拠を返信して Resolve した。

## 品質ゲート
- CI: 最後の push（b18544e）の CI は green（https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37270638456）。最後の push の後に bot の新しいスレッド・レビュー本文が無いことも確認した／品質チェック一式 green（PHPUnit 1493・Jest 87・開発補助スクリプトのテスト）

## 次にできること（人間の判断）
- **R3-1a〜R3-1e の修正**（issue #98〜#102・#78）。テストショップのお試し期限 **2026-10-22** までに、各 PR で `rehearse-colorme` を使って該当する手順を再実行する
  （開発サイトの `ZZW-*` は逆向きの再取込みで出自のメタを持ったので、`reset-local` → 投入し直しから）
- **R3-1e（#102）の方針の決定**: 軽減税率の税区分の見分け方（設定で選ぶ／税率 8% の税区分を自動判定する／訳のスラッグも候補にする）。R3-1d（#78）はこれを決めてから実装する
- backlog `r3-1-e2e-rehearsal/R1-X1`: F1-8 の記録（`docs/10-tasks.md`）に実店舗のステージングのホスト名がある（CLAUDE.md の禁止事項。既存）。docs だけの修正で除くかを判断する
- テストショップの `ZZR-`／`ZZW-` の商品・会員・カテゴリ・グループ・ゲスト注文は残っている（プラグインからは消せない）。期限後の扱いを決める
- 開発サイトの Woo の税設定（税込入力・標準 10%・軽減 8%・JPY・小数 0 桁・基準 JP13）、配送ゾーン「日本」、マッピング設定は残してある。通貨・小数桁・基準所在地の変更前の値は記録していない
- Copilot の G3 の修正（2ac4d3f）は再レビューを受けていない。依頼上限の後でも Copilot が自発的にレビューを返すことがあるので、数時間後に `gate-threads.sh 103 2026-10-05T06:04:48Z` と `gate-bodies.sh` で新しい指摘が無いか確認するとよい
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
