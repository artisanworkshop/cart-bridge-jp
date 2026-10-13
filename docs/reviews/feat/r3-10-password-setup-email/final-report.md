# dev-cycle 最終報告: feat/r3-10-password-setup-email

## 開発内容
- タスク: R3-10 パスワード設定メール（Pro。D28・`docs/03` §10.5）。2026-10-13 に Pro の v1.0 に含めると決めた
- PR: #135 https://github.com/artisanworkshop/cart-bridge-jp/pull/135
- 計画（`~/.claude/plans/twinkly-kindling-cocoa.md`）の要約: 取込みで作った顧客へ、Pro の設定画面のタブからパスワードを設定するリンクを一斉に送る。送る相手は取込みが作り今もその顧客に結ばれたアカウントで、パスワードを設定し直した顧客は外す（作成時の控えと比較。控えが無ければ `default_password_nag`、それも無ければ判定できないので送る）。0.1.0 の顧客はジョブの時間帯から推定し、管理者が含めたときだけ送る。WooCommerce のメールとして登録し、Action Scheduler で少しずつ送る。一斉送信の開始・再開だけキーが要る
- 設計ドキュメントからの逸脱: ① 0.1.0 のアカウントの推定（ユーザー決定）② 判定できない顧客は送る（ユーザー決定）③ 一斉送信だけキーが要る（D30 の延長。ユーザー決定）④ 無料版の拡張点 `Sync\JobHistory`（拡張点の版 4）⑤ Pro の zip に `templates/` ⑥ Pro の停止時に予約を消す処理は置かない（画面で中断を検出して再開）。いずれも `docs/03` §10.5「R3-10 の実装」に記録

### コミット
| sha | メッセージ |
|---|---|
| `157b8d3` | feat: add a read-only job history extension point for add-ons (API v4) |
| `3b584c6` | feat(pro): send password setup emails to imported customers (R3-10) |
| `a57e4a2` | chore(i18n): translate the Pro password setup email strings into Japanese |
| `90b2f9a` | fix(pro): use plural forms for counted sentences on the email tab |
| `eb1fc49` | test(pro): pin the guards the mutation check found uncovered |
| `6415b13` | docs: record the R3-10 password setup email implementation |
| `cb81553` | fix(pro): address review-loop R1 for the password setup email |
| `f6db893` | docs: record the R3-10 review-loop R1 fixes and known limits |
| `d32c552` | docs: record review-loop R1 for R3-10 |
| `8e2a2d3` | fix(pro): address review-loop R2 for the password setup email headers |
| `78a070c` | docs: record review-loop R2 for R3-10 (approve) |
| `e94e874` | docs: update the R3-10 dev-cycle state after review-loop |
| `45f7ee6` | fix(pro): harden the password email batch against overlap and lost records |
| `753bb02` | docs: record dev-cycle gate round 1 for R3-10 |
| `c3fe0c1` | fix(pro): guard send record transitions and scope the live action check |
| `546c0e6` | docs: record dev-cycle gate round 2 for R3-10 |
| `48b9d2d` | docs: drop the bot mention from the R3-10 gate round 2 record |
| `dd80e93` | fix(pro): keep per-run state rows and stop when candidates cannot be read |
| `ebfda7e` | docs: record dev-cycle gate round 3 for R3-10 |

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Critical/High 0・Medium 4・Low 9・対象外 1 | Medium 4・Low 8 | Low 1（R1-L1）・対象外 1（R1-X1） |
| R2 | R1 すべて解消・新規 Low 5（**APPROVE**） | Low 5 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留・対応不要 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex（自動レビュー） | 1 | 1 | 0 | 未収束 |
| G1 | Copilot | 5 | 3 | 対応不要 2（未解決のまま） | 未収束 |
| G2 | Codex | 1 | 1 | 0 | 未収束 |
| G2 | Copilot | 2 | 2 | 0 | 未収束 |
| G3 | Codex | 1 | 1 | 0 | 上限（3 回目でも新規 1 件。修正済み） |
| G3 | Copilot | 1 | 1 | 0 | 上限（3 回目でも新規 1 件。修正済み） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | High | 取消が別の run の予約まで外す → 取り消した run の予約だけを外す | `45f7ee6` | [r4263889478](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4263889478) |
| G1-2 | Copilot | High | 未送信に戻すの無条件の削除 → 読んだ値のときだけ消す | `45f7ee6` | [r4263889556](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4263889556) |
| G1-4 | Copilot | High | 排他の期限切れの後も書きうる → 借りを延ばし、延ばせなければ何も書かない | `45f7ee6` | [r4263889657](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4263889657) |
| G1-6 | Codex | P2 | 送る前の記録の書込みの失敗を確かめない → 読み直して確かめ、書けなければ送らない | `45f7ee6` | [r4264000192](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4264000192) |
| G2-1 | Codex | P2 | `sent:` で始まる壊れた記録が戻せず行き詰まる → 送信済みとして読む | `c3fe0c1` | [r4264182570](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4264182570) |
| G2-2 | Copilot | High | 送れた・失敗の記録の書換えが無条件 → 自分の書いた値のときだけ | `c3fe0c1` | [r4264190819](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4264190819) |
| G2-3 | Copilot | High | 生きた予約の判定が run に絞られず、照会の失敗を「なし」と読む → run とトークンに絞り、失敗は「確かめられない」 | `c3fe0c1` | [r4264190883](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4264190883) |
| G3-1 | Codex | P1 | 候補を DB から読めないと完了にして残りを飛ばす → 止めて理由を出す | `dd80e93` | [r4264350476](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4264350476) |
| G3-2 | Copilot | High | 遅れた開始が新しい run の行を上書きしうる → run ごとの行を CAS の前に作る | `dd80e93` | [r4264366260](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4264366260) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-3 | Copilot | High | 誤検知: Action Scheduler 4.1.0 の `as_has_scheduled_action()` は in-progress も数える（その後 G2-3 で判定自体を run に絞った） | [r4263889616](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4263889616) |
| G1-5 | Copilot | Medium | 誤検知: WooCommerce の設定画面の section は `get_emails()` の鍵を小文字にしたもの（dev サイトで開けることを確認） | [r4263889714](https://github.com/artisanworkshop/cart-bridge-jp/pull/135#discussion_r4263889714) |

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38473991268 green（HEAD `ebfda7e`）
- 品質チェック: green（無料版 PHPUnit 1391 件・Pro 997 件・Jest 111 件・i18n・lint・PHPStan）
- ガードの変異: 実装・review-loop・各ゲートで直したガードを `mutate-check.sh` で確認（検出されなかったものはテストを足した。等価なものは docs/03 に記録）
- dev サイト: キーなしの開始の拒否・テスト送信・推定を含めた送信（Action Scheduler で 3 通・完了）・取消・送ったリンクでのパスワードの設定（curl）と判定・画面と WooCommerce のメールの設定。3,000 人で件数の集計 0.3 秒・メモリの増加 +0.7 MB。作ったものはすべて消した
- 注意: G1 以降の修正（借りの更新・記録の CAS・run ごとの行など）はテストと変異で確かめたが、dev サイトでの通しの実機確認は実装時（G1 の前）のもの

## 次にできること（人間の判断）
- G1-3・G1-5 は誤検知として根拠を返信し、未解決のまま残してある。納得できれば Resolve してよい
- backlog: `r3-10-password-setup-email/R1-L1`（0.1.0 のジョブを Retry して例外になると推定の窓が広がる）・`R1-X1`（無料版のアンインストールが Pro の予約を残す）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
