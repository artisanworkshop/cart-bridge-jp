# dev-cycle 最終報告: fix/15-coupon-unsupported-restrictions

## 開発内容
- タスク: issue #15 — `Woo\Writer\CouponWriter` のクーポン制限フェイルクローズがColorMe固有キーに依存しており、
  他ASPのアダプタに対して機能しない問題の解消
- PR: #37 https://github.com/artisanworkshop/cart-bridge-jp/pull/37

### 承認された計画の要約
- `CanonicalCoupon` に三値の `?bool $has_unsupported_restrictions` を**最終引数**として追加
  （外部アダプタの位置引数契約を守るため既存引数より後ろ。アーキテクチャ原則8）
- `CouponWriter` は正規化フィールドのみで判定し、`true`（写せない制限あり）と `null`（アダプタが未宣言）の
  両方で保存を見送る。楽観的デフォルト（未宣言＝制限なし）は採らない（原則9）
- ColorMe の `CouponTransformer` は従来どおり変換段階で除外する方針を維持 → **v1.0 の挙動は不変**
- 警告コードを `coupon_group_limit_unsupported` → `coupon_restrictions_unsupported` に改名、
  未宣言用に `coupon_restrictions_unknown` を新設

### コミット一覧
| sha | メッセージ |
|---|---|
| ae4d153 | fix: make the coupon restriction fail-close platform-neutral |
| c89d039 | docs: record the coupon restriction normalization for issue #15 |
| aeea52e | fix: report the already-imported coupon left behind by the fail-close |
| 6b22369 | fix: drop the redundant coupon restriction warning detail |
| 38f8d24 | docs: record review-loop rounds for issue #15 |
| 6865478 | fix: narrow the coupon restriction contract to what is implemented |
| 9d2b0ec | docs: record dev-cycle gate round 1 |
| 0c6eedb | docs: refresh the dev-cycle state file and record gate round 2 |
| 0206aaf | docs: record gate round 2 head |

### 設計ドキュメントからの逸脱
なし。`docs/03-design-decisions.md` §2 の `CanonicalCoupon` フィールド列挙が実装と乖離していたため
実装に合わせて更新した（設計変更ではなくドキュメントの追随）。

### 対応時期の判断
台帳では「クーポンAPIを持つ次のアダプタ（M6-3）着手前まで」としていたが、実際の締切は v1.0 公開前と判断して
前倒しした。公開後だと (1) 判定根拠を extras キーから正規化フィールドへ移すこと自体が外部アダプタ向けの
挙動契約の変更になり、(2) `to_array()` のキー追加による checksum 変化が利用者に及ぶため。

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 2 / Low 4（Critical・High ゼロ） | 6 | 0 |
| R2 | 新規 Low 2（Critical・High ゼロ）＋ R1-1 の取り消し | 3 | 0 |

R2 で **APPROVE**。特記: R1 で採用した「警告に既存 local_id を detail として載せる」指摘は、R2 の検証で
前提が誤り（dry-run CSV は行ごとに `existing_local_id` 列を持ち、実移行では per-item 警告をそもそも
永続化しない）と判明したため取り消した。経緯は R2.md に記録。

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 5（inline 3 / suppressed 2） | 4 | 1 | 未収束 |
| G1 | Codex | — | — | — | 自動レビュー未発火（15分TIMEOUT） |
| G2 | Copilot | 1 | 1 | 0 | 未収束 |
| G2 | Codex | 0 | — | — | **収束** |
| G3 | Copilot | 0 | — | — | **収束** |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | `false` の契約に未実装の「Wooの制限軸へ変換済み」を含めていた | 6865478 | [r3996211085](https://github.com/artisanworkshop/cart-bridge-jp/pull/37#discussion_r3996211085) |
| G1-2 | Copilot | Medium | `from_array()` の `(bool)` キャストで `'0'`→`false` に化けフェイルクローズを迂回しうる | 6865478 | [r3996211096](https://github.com/artisanworkshop/cart-bridge-jp/pull/37#discussion_r3996211096) |
| G1-3 | Copilot | Medium | R1 の表現是正が `docs/03-design-decisions.md` に横展開されていなかった | 6865478 | [r3996211111](https://github.com/artisanworkshop/cart-bridge-jp/pull/37#discussion_r3996211111) |
| G1-4 | Copilot | Low | 同じ横展開漏れが `CLAUDE.md` にも残っていた（suppressed・スレッドなし） | 6865478 | — |
| G2-1 | Copilot | Low | 状態ファイル `dev-cycle.md` の陳腐化 | 0c6eedb | [r3996317578](https://github.com/artisanworkshop/cart-bridge-jp/pull/37#discussion_r3996317578) |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-5 | Copilot | Low | 警告コード改名の後方互換維持。wordpress.org 未公開・Pro版未存在・旧コード参照ゼロ（grep 済み）で外部消費者が無く、旧コードを残すと誤った名前が恒久化するため見送り | suppressed comment のためスレッドなし（G1.md と PR サマリに記録） |

## 品質ゲート
- CI: 全ラウンドで green（JS/TS・PHP quality 8.2/8.3・PHPUnit(wp-env) の 4 ジョブ）
  最終: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/34696396601
- 品質チェック（ローカル）: green（PHPCS / PHPStan level 6 / PHPUnit **713 tests, 1904 assertions** / ESLint+tsc / build）

## マージ時の注意
`to_array()` にキーが増えるため既存クーポンの checksum が変わり、アップグレード後の初回インポートで
既取込みクーポンが1度だけ再書き込みされる（ASP側に変更が無くても全件。店舗がWoo側で手直しした内容は
1度だけ巻き戻る。D16 の既定＝上書きの範囲内）。初回 dry-run では差分ゼロでも全件 `updated` と並ぶ。
v0.1.0 を入れている実サイトも対象。

## 次にできること（人間の判断）
- 保留分の修正: `/cbj-dev-cycle fix G1-5` または `/fix-copilot-review 37`
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- マージ後の次タスクは **F1-8: 実データE2E**（`docs/10-tasks.md`）
