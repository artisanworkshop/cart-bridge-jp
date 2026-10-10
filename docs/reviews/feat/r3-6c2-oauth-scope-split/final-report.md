# dev-cycle 最終報告: feat/r3-6c2-oauth-scope-split

## 開発内容
- タスク: R3-6c2 OAuth スコープの分割（`docs/03` §10.0 決め残し 9。R3-4 の前に必須）
- PR: #118 https://github.com/artisanworkshop/cart-bridge-jp/pull/118
- 計画の要約: 無料版の ColorMe の認可は `read_products write_products` だけを要求し、Pro アドオンが有効なときに `read_sales write_sales read_shop_coupons` を足す。
  付与されたスコープをトークンと同じ CAS で記録し、足りない接続には再接続を促す（接続カードの警告、Import／Export／Mappings タブの案内）。
  記録の無い既存のトークンは旧版の 5 つを持つとみなす。Pro は 3 つがそろわない ColorMe では顧客・受注・クーポンを組み立てない
- 最終的な形（ゲートで変わった点）: 拡張がスコープを足す口は、フィルター `cbjp/oauth/scopes` ではなく宣言用のレジストリ `Adapters\OAuthScopes::add()`（G3-B1）。
  応答に `scope` が無いときの「要求したもの」は認可ごとの控え（state／OOB はユーザー。OOB を取り直したら両方が要求したものだけ。G1-1・G2-1）。
  スコープ不足で組み立てなかったときだけ `get_required()` は `not_connected`（G2-B1）

### コミット
| sha | メッセージ |
|---|---|
| 7e26b6f | feat: request only product OAuth scopes and let Pro add the rest (R3-6c2) |
| 3e5f2ce | feat: prompt reconnecting when the connection lacks requested scopes (R3-6c2) |
| 687c791 | docs: record the R3-6c2 OAuth scope split |
| e0e6e81 | fix: ask to reconnect instead of reporting commerce as unsupported (R3-6c2 R1) |
| 56b6da3 | docs: record review-loop R1 for R3-6c2 |
| fa72d36 | fix: pin the no-log case and widen the reconnect message (R3-6c2 R2) |
| dd7bd92 | docs: record review-loop R2 for R3-6c2 |
| 32678ba | fix: record the scopes requested per authorization and skip non-OAuth adapters (R3-6c2 G1) |
| ce60c2d | fix: add the Pro OAuth scopes last so later filters cannot drop them (R3-6c2 G1) |
| 11ea6d1 | docs: record dev-cycle gate round 1 for R3-6c2 |
| a6bde5c | fix: keep only the scopes every pending out-of-band authorization requested (R3-6c2 G2) |
| 077ca80 | fix: ask to reconnect only when the bundled factory lacked scopes (R3-6c2 G2) |
| ca32bd6 | docs: record dev-cycle gate round 2 for R3-6c2 |
| a6c949d | refactor: declare extension OAuth scopes instead of filtering them (R3-6c2 G3) |
| 279485b | refactor: declare the Pro OAuth scopes at boot (R3-6c2 G3) |
| 40ae7df | docs: record dev-cycle gate round 3 for R3-6c2 |

### 設計ドキュメントからの逸脱
1. 決め残し 9 の「要求したスコープを記録」は、付与されたスコープ（トークン応答の `scope`。無ければ認可で要求したもの）を記録する形にした
2. 記録の無い既存のトークンは旧版の 5 つを持つとみなす（計画で承認）。無料版だけの既存サイトのトークンは受注の権限を持ったまま（プラグインは古いトークンを失効させない。R3-6d の changelog で案内）
3. 宣言の未知の値は、結果ごとではなくその値だけ捨てる
4. 「Pro が使ってよい無料版の API」に `Adapters\OAuthScopes::add()`・`ColorMeAdapter::granted_scopes()` を足し、`CBJP_EXTENSION_API_VERSION` を 2 にした。拡張がスコープを足す口はフィルターでなく宣言（G3-B1）
5. push intent の 409（`cbjp_not_connected`）の文言を「接続が無い・期限切れ・権限が足りない」を含む形にした

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立 opus） | Critical/High/Medium 0・Low 6・対象外 1 | Low 5・対象外 1（push intent の案内・Mappings タブの案内・ログ・docs・docblock・rehearse の事前チェック） | 1（R1-3。のちに G1-3・G3-B1 で解消） |
| R2（独立 opus の検証） | R1 全件解消・新規 Low 2 | 2（ログのガードのテスト・409 の文言） | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 2 | 2 | 0 | 未収束 |
| G1 | Codex | 1 | 1 | 0 | 未収束 |
| G2 | Copilot | 2（スレッド 1・本文 1） | 2 | 0 | 未収束 |
| G2 | Codex | 0 | 0 | 0 | **収束**（Didn't find any major issues・👍） |
| G3 | Copilot | 1（本文） | 1 | 0 | **上限**（3 回目でも新規指摘あり。修正は push 済み・CI green、再依頼はしない） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | 応答に `scope` が無いときの要求スコープを交換時に求め直していた → 認可ごとに控える | 32678ba | [r4237783873](https://github.com/artisanworkshop/cart-bridge-jp/pull/118#discussion_r4237783873) |
| G1-2 | Copilot | Medium | `colorme` を OAuth でない mock で置き換えても `missing_scopes` を出していた | 32678ba | [r4237783899](https://github.com/artisanworkshop/cart-bridge-jp/pull/118#discussion_r4237783899) |
| G1-3 | Codex | P2 | 後から登録された拡張が Pro のスコープを消せた（R1-3 の再指摘）→ Pro を最後の優先度に（G3 で宣言に置き換え） | ce60c2d | [r4237794355](https://github.com/artisanworkshop/cart-bridge-jp/pull/118#discussion_r4237794355) |
| G2-1 | Copilot | High（判定は Low〜Medium） | OOB の控えがユーザー単位で上書きされる → 両方が要求したスコープだけを残す | a6bde5c | [r4237836058](https://github.com/artisanworkshop/cart-bridge-jp/pull/118#discussion_r4237836058) |
| G2-B1 | Copilot | Medium | `get_required()` が組み立てなかった理由を区別していなかった | 077ca80 | 本文（[review 5479175076](https://github.com/artisanworkshop/cart-bridge-jp/pull/118#pullrequestreview-5479175076)） |
| G3-B1 | Copilot | Medium | 先の拡張の例外でフィルターの鎖が止まり Pro のスコープが消える → フィルターをやめて `OAuthScopes` の宣言に | a6c949d・279485b | 本文（[review 5479231258](https://github.com/artisanworkshop/cart-bridge-jp/pull/118#pullrequestreview-5479231258)） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38059005658 全ジョブ green（HEAD 40ae7df）
- 品質チェック: `quality.sh` green（PHPUnit 無料版 1238 件・Pro 589 件・Jest 91 件・PHPCS・PHPStan・i18n:check・dev-mount）、`bin/build-zip.sh` OK
- ミューテーション: 実装時 14 種・R1/R2 2 種・G1 7 種・G2 3 種・G3 3 種、すべて CAUGHT
- dev サイト: Pro 有効／無効 × トークンの記録 4 通りの REST と画面（接続カード・Import／Export の案内）、G3 の後に Pro 有効で 5 つを要求すること

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- Copilot は 3 回目（上限）で新規指摘があり、その修正（G3-B1。フィルターを宣言に置き換えた）は bot の再レビューを受けていない。気になれば数時間後に `/fix-copilot-review 118` で遅れて届く指摘が無いか確認する
- 実 API（ColorMe のテストショップ）での認可のやり直し（Pro 無効で接続 → 警告 → Pro を有効にして再接続 → 顧客・受注・クーポンが出る）は未実施。R3-4 前の確認か `rehearse-colorme` の次の実行で確かめられる（`run` はスコープ不足なら始める前に止まる）
- 既存の無料版だけのサイトのトークンは受注の権限を持ったまま（再接続しても古いトークンは失効しない）。R3-6d の changelog で「許可済みアプリ一覧で取り消してから接続し直す」案内を書く
