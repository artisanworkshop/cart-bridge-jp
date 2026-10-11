# dev-cycle 最終報告: feat/r3-8a-license-client

## 開発内容
- タスク: R3-8a ライセンスのクライアント（WooCommerce API Manager の有効化・無効化・状態確認）と Pro の設定画面（`docs/10-tasks.md` R3-8、`docs/03` §10.7・D30）
- PR: #123 https://github.com/artisanworkshop/cart-bridge-jp/pull/123
- 承認された計画の要約:
  - 範囲は Pro だけで、無料版は変えない。
  - 手元の API Manager 3.6.0 を dev サイトに一時的に入れて実測し、終わったら撤去した。
  - キーは暗号化し、有効化の状態は平文で保存する。
  - 有効化の状態はローカルの記録だけで決める（D14）。
  - 設定画面は PHP で描く。
  - 計画の途中で決めたこと（ユーザー決定）: 有効化済みでも、同じ instance で別のキーを有効化できる（キーの交換）。
- コミット:

| sha | メッセージ |
|---|---|
| f7ce850 | feat: add the Pro license client and settings page (R3-8a) |
| 66a00e8 | docs: record the R3-8a license client and the API Manager measurements |
| a47a7db | fix: harden the Pro license flows found in review (R3-8a) |
| 77a123d | docs: record review-loop R1 for R3-8a |
| c7171e3 | fix: keep the license page's exit out of the test run (R3-8a) |
| 6fe321c | docs: record review-loop R2 for R3-8a |
| 5889763 | docs: update the dev-cycle state for R3-8a |
| 303ac48 | fix: create the license instance atomically and reject overflowing IDs (R3-8a) |
| 88a0729 | docs: record dev-cycle gate round 1 for R3-8a |
| a885523 | fix: do not send a license instance that was not saved (R3-8a) |
| 3990990 | docs: record dev-cycle gate round 2 for R3-8a |
| 0766775 | fix: verify license writes and report STORAGE_ERROR on failure (R3-8a) |
| 0e759d9 | docs: record dev-cycle gate round 3 for R3-8a |
| c619a93 | fix: tell how to recover when the site cannot record a license change (R3-8a) |

- 設計ドキュメントからの逸脱（docs/03 §10.7「R3-8a の実装」に記録した）:
  1. 接続先の URL と製品 ID は、販売サイトが決まるまで空のまま。版 1.0.0 以上で空のまま・https でないまま公開しようとすると `LicenseConfigTest` が落ちる。
  2. 実測は手元の API Manager 3.6.0 で行った。本番の販売サイトでの再確認は R3-8c の要検証。
  3. 状態確認は表示だけで、有効化の状態は変えない。
  4. 無効化には、サーバーが成功を返すことが要る。
  5. キーの交換（ユーザー決定）。
  6. 要求は POST の本文で送る。
  7. Pro はアンインストールで自分の option を消さない。ただし、無料版の「データを消す」を有効にすると消える。
  8. 販売サイト向けの申し送り: 製品は無制限の有効化にする。猶予期間を 0 にするかを決める。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立 opus） | Medium 2（1 件は Low から格上げ）・Low 6・対象外 2 | 7 | 3 |
| R2（独立 opus の検証。変異 9 種） | R1 の Medium 2 件は解消。新規は Medium 1・Low 4 | 3 | 1（このほか docs に記録 1） |

判定は R2 で APPROVE。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | — | — | 収束（自動レビューが 5 分来ず review コメントを自動投稿 →「Didn't find any major issues」） |
| G1 | Copilot | 3 | 2 | 0（対応不要 1） | 未収束 |
| G2 | Copilot | 1（本文の Previously missed） | 1 | 0 | 未収束 |
| G3 | Copilot | 1（本文の Previously missed） | 1 | 0 | 上限（3 回目でも新規あり。修正して CI green。4 回目は依頼していない） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium（Copilot は High） | instance を同時に初めて作ると、サーバーで有効化した値と保存した値がずれる。`INSERT IGNORE` で作り、DB から読み直す（`add_option()` は上書きするので使えない） | 303ac48 | [r4240773425](https://github.com/artisanworkshop/cart-bridge-jp/pull/123#discussion_r4240773425)（Resolve 済み） |
| G1-2 | Copilot | Low〜Medium | `PHP_INT_MAX` を超える製品 ID が別の値になる | 303ac48 | [r4240773433](https://github.com/artisanworkshop/cart-bridge-jp/pull/123#discussion_r4240773433)（Resolve 済み） |
| G2-B1 | Copilot | Medium | instance を読み直せないとき、保存していない値をサーバーに送る → `STORAGE_ERROR` | a885523 | 本文（[review 5482498319](https://github.com/artisanworkshop/cart-bridge-jp/pull/123#pullrequestreview-5482498319)） |
| G3-B1 | Copilot | Medium | option の書込みの失敗を確かめていない。「有効化済み・キー無し」で行き詰まる → 読み直す・書く順序を決める・失敗は成功扱いにしない・キーの入れ直し | 0766775 | 本文（[review 5482536145](https://github.com/artisanworkshop/cart-bridge-jp/pull/123#pullrequestreview-5482536145)） |

G3 の後の独立確認（opus。bot の再レビューを受けない G2・G3 の修正が対象）で、新規の Critical/High は 0 件だった。Low 3 件を c619a93 で直した。
- F-1: サーバーが受け付けた後に手元で記録できなかった場合を `NOT_RECORDED` とし、やり直し方を操作ごとに案内する。
- F-2: 無効化の確認を「`'no'` と読めたとき」に変えた（原則 9）。
- F-3: テストを足した。

変異はすべて CAUGHT。

### 修正しなかった指摘（PR 上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-3 | Copilot | — | 対応不要（誤検知）。WP のテスト基盤は各テストの `tear_down()` で `_restore_hooks()` を呼び、フックを戻す。ソースと一時テストで実測した | [r4240773435](https://github.com/artisanworkshop/cart-bridge-jp/pull/123#discussion_r4240773435)（返信済み。Resolve はユーザーの承認待ち） |

## 品質ゲート
- CI: G3 の修正（0e759d9）は全ジョブ green（[run 38124948278](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38124948278)）。c619a93 とこの記録の CI は push 後に確認する。
- 品質チェック: `quality.sh` green（無料版 1262・Pro 746〔R3-8a で 157 件〕・Jest 91・PHPCS・PHPStan・i18n）。

## backlog に送ったもの
`docs/review-backlog.md` の `r3-8a-license-client/*`:
- R1-8: `_wpnonce` の id が重複する。
- R1-X1: DB の複製と instance。R3-8c で判断する。
- R1-X2: 販売サイトへ送るものの開示。R3-8c/d で扱う。
- R2-4: タイムアウトが 2 回重なると最大約 30 秒待つ。
- G1-X1: 無料版の `TokenStore::save_settings()` も `add_option()` の前提に立っている。対象外。

## 次にできること（人間の判断）
- G1-3（対応不要）のスレッドを Resolve するか。
- マージは GitHub 上で人間が行う。マージ後は `/post-merge`。
- 次のタスクは R3-8b（キーが無い間は dry-run だけ）。`LicenseStore::is_activated()` を使う。
