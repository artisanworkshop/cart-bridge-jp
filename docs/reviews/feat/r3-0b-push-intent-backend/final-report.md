# dev-cycle 最終報告: feat/r3-0b-push-intent-backend

## 開発内容
- タスク: R3-0b（issue #73、D21-B）「作成結果が不明な実体を自動で再送しない」のバックエンド + REST 部分（PR 1/2。UIは後続の別PR）
- PR: [#79](https://github.com/artisanworkshop/cart-bridge-jp/pull/79)
- 承認された計画の要約: 新テーブル`cbjp_push_intents`で作成経路（product/customer/order/coupon）の送信直前に「送信中の印」を書き、結果に応じて消す（未送信/拒否確定）か残す（結果不明）かを`Sync\Exporter`が判定。`LimitPolicy`の無料枠に印を含め、`SampleCleanup`の全量完了時に対応する反映を行う。REST（`GET/POST /push-intents/{platform}[/{id}/resolve]`）で店舗が解除する
- コミット一覧:
  | sha | メッセージ |
  |---|---|
  | 99c3cbe | feat: track ambiguous export outcomes with push intents (D21-B) |
  | b44fb6d | feat: add push-intents REST routes to resolve unconfirmed exports (D21-B) |
  | ac4de55 | fix: keep ambiguous 2xx-no-id and not_connected push intents, fix quota double-count (R1-1..4) |
  | a402738 | docs: record R1 review round |
  | 52fcb6d | fix: also guard the PushResult branch's quota release when a push intent stays ambiguous (R2-1) |
  | 8bb781c | docs: record R2 APPROVE |
  | bb346a4 | fix: validate remote_id match, reconcile stale intents, reorder intent block (G1-2..7) |
  | e1d3048 | docs: record gate round G1 |
  | f16840a | fix: verify mapping persistence on the main export path, restore quota on reconciliation, scope SampleCleanup's intent purge to gone entities (G2-1..5) |
  | d5acf61 | docs: record gate round G2 |
  | 79df04b | fix: recompute quota on multi-intent reconciliation, migrate DB on boot, clear stale mapping on link (G3-1..3) |
  | 04fd8bc | docs: record gate round G3 (final) |

### 設計ドキュメントからの逸脱（実装時の解釈。ユーザー承認済み）
1. `SampleCleanup`の印の削除は、個別エンティティ単位ではなく「プラットフォームのmappingsを全て処理し終えた（全量完了）」タイミングで、`Woo\Tools\LocalEntityLookup`によるローカル実体の実在確認に基づいて行う（G2レビューで、実在するローカル実体への印まで一律削除する初期実装の欠陥が見つかり修正）。
2. `LimitPolicy::used()`（＝`/limits`の`used`）が未解決の印を含むようになったため、既存の値の意味が変わる（`limit - used === remaining`の整合性を保つための変更）。
3. 契約違反（空remote_idの`PartialPushException`、または`PushResult`がcreated/updatedを主張しつつremote_id空）は、原因の例外型によらず必ず「印を残す」側に倒す。
4. G3で`Core\Plugin::boot()`のDBマイグレーション発火を`admin_init`限定から`boot()`内の直接呼び出しへ変更（push_intentsに限らず全テーブルの既存マイグレーション経路も同時に改善する基盤的な修正。Copilotレビュー指摘）。
- `docs/03-design-decisions.md` §10.2 D21-Bの「実装（R3-0b）」小節への反映はPR 2/2（UI）でまとめて行う（計画承認済み）。

## review-loop（PR前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | High 1・Medium 3・Low 2 | High 1・Medium 3 | Low 2（`r3-0b-push-intent-backend/R1-X1`・`R1-L1`） |
| R2（検証） | 新規混入 Medium 1・Low 1（記録不整合） | 2件とも修正 | — |

判定: **APPROVE**（R2で収束）。詳細: `docs/reviews/feat/r3-0b-push-intent-backend/R1.md`・`R2.md`

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 4 | 3（1件はG2で追加修正が必要と判明） | 0 | 収束 |
| G1 | Codex | 3（うち2件Copilotと同根） | 3 | 0 | 収束 |
| G2 | Copilot | 3（実質2種、G1修正の横展開漏れ + 新規High） | 3 | 0 | 収束 |
| G2 | Codex | 2（Copilotと同根） | 2 | 0 | 収束 |
| G3 | Copilot | 2 | 2 | 0 | 収束（依頼上限3到達） |
| G3 | Codex | 1 | 1 | 0 | 収束（依頼上限3到達） |

両bot・全ラウンドで応答あり（TIMEOUT無し）。詳細: `G1.md`・`G2.md`・`G3.md`

### 修正した指摘（すべてのラウンド合計、抜粋）
| ID | bot | 重大度 | 内容 | コミット |
|---|---|---|---|---|
| R1-1 | 自己+サブエージェント | High | `PushResult`が`created`主張＋remote_id空（契約違反）でも印を無条件削除していた | ac4de55 |
| R1-2/R1-3 | 自己+サブエージェント | Medium | 印を残した場合の無料枠二重解放／`ApiException`のstatus 0（未接続）誤判定 | ac4de55 |
| R1-4 | 自己+サブエージェント | Medium | `resolve_link()`がAPIエラー系例外を捕捉せずREST層の外へ伝播しうる | ac4de55 |
| G1-2/G1-7 | Copilot/Codex | High | `resolve_link()`が返却実体の`remote_id()`一致を確認せず、契約違反アダプタで誤リンクしうる | bb346a4 |
| G1-4/G1-6 | Copilot/Codex | Medium | mapping書込み後・印削除前の中断で孤立した印が恒久的に残る | bb346a4 |
| G1-5 | Codex(P1) | High | `resolve_link()`がmapping永続化を確認せず印を削除していた | bb346a4 |
| G2-1/G2-3 | Codex(P1)/Copilot | High | 上と同根の欠落がexport本経路（`Exporter`本体）にも残っていた | f16840a |
| G2-2/G2-5 | Codex(P2)/Copilot | Medium | 自己修復（孤立intent削除）がクォータ枠を戻し忘れていた | f16840a |
| G2-4 | Copilot | High | `SampleCleanup`がローカル実体が残る印まで一律削除していた | f16840a |
| G3-1 | Codex(P2) | Medium | クォータ復元が複数stale intent時にクランプを無視して過剰復元しうる | 79df04b |
| G3-2 | Copilot | High | DBマイグレーションが`admin_init`限定でAction Scheduler/REST経由だと未適用のまま実行されうる | 79df04b |
| G3-3 | Copilot | Medium | `resolve_link()`が旧remote_idのmapping行を削除せず孤児化させていた | 79df04b |

### 修正しなかった指摘（PR上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Copilot | High（保留・ユーザー確認済み） | `resolve_push_intent()`の進行中run確認が非原子的なcheck-then-act。`sample-cleanup`等が共有する既知・追跡中の制限（issue #57）と同型で、プラットフォーム単位ロックという本PRのスコープを超える | [r4114900526](https://github.com/artisanworkshop/cart-bridge-jp/pull/79#discussion_r4114900526) |

review-loop側のLow 2件（対象外の既存コード指摘）: `docs/review-backlog.md`の`r3-0b-push-intent-backend/R1-X1`（`ColorMeAdapter`のfetch-by-id系メソッドが「404」と「変換失敗」を区別せずnullを返す既存の設計）・`R1-L1`（`PushIntentRepository::begin()`のINSERT IGNOREが重複キー以外のSQLエラーも無言で無視する）。

## 品質ゲート
- CI: [run](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/36317537504) green（5/5。途中1回、`wp-env start`の一時的な502 Bad Gatewayでインフラ起因の失敗があり`gh run rerun --failed`で再実行）
- 品質チェック: `composer lint && composer analyze && composer test:wpenv` green（PHPUnit 1200件、開始時1168件から+32件）
- フェイルクローズ分岐（作成結果不明時に印を残すか消すかの各条件・無料枠の二重消費/過剰復元防止・自己修復・REST層の例外変換）は、すべて分岐を一時的に壊して対応テストが落ちることを確認済み（詳細は各ラウンドの`R*.md`/`G*.md`）。既知の限界: 「`MappingRepository::upsert()`が黙って失敗する」状況自体（G1-5・G2-1）は、`MappingRepository`がfinalクラスのためテストダブルを作れず、単体テストでは再現していない（正常系は関連する全pushintentテストが継続してカバー）

## 次にできること（人間の判断）
- 保留分（G1-1、check-then-actの非原子性）の修正: `/dev-cycle fix G1-1` または `/fix-copilot-review 79`。ただし対応にはissue #57で検討中のプラットフォーム単位ロック設計が前提になる見込み
- マージ（GitHub上で人間が行う）→ マージ後は`/post-merge`
- マージ後、`docs/10-tasks.md`のR3-0bチェック・`docs/03-design-decisions.md` §10.2 D21-Bの実装サマリ追記・`docs/review-backlog.md`の`e2-3-push-*/G1-duplicate-on-retry`3行の解消は、UI（PR 2/2）とあわせて行う
