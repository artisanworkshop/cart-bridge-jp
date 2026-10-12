# dev-cycle 最終報告: feat/r3-8c-pro-updates-and-release

## 開発内容
- タスク: R3-8c（Pro の更新の配信・Pro の zip・リリース）/ PR #125 https://github.com/artisanworkshop/cart-bridge-jp/pull/125
- 承認された計画（2026-10-12。ユーザー決定 4 点）: 実測は手元の API Manager 3.8.1 で更新 API と R3-8a の流れ／`Update URI` は販売サイトの URL と同時に入れる（今は空。1.0.0 でテストと zip の検査が止める）／
  キーを有効化していないサイトは販売サイトに問い合わせない／DB の複製は記録だけ
- 実装:
  - Pro の `License\UpdateChecker`（`Update URI` の host の `update_plugins_{host}`・`plugins_api`・package の無い更新の `disable_autoupdate` と説明）、`LicenseClient::update()`/`information()`（フェイルクローズ。JSON の `information`。英語と ja の節の表示名）、
    `LicenseManager` が有効化・無効化・キーの入れ直しの後に更新の情報を捨てる、設定画面に送るものの説明と複製の注意
  - `bin/build-zip.sh --plugin cart-bridge-jp-pro`・`--version` とヘッダーの照合・Pro の `.distignore`・CI の Distribution ジョブ・`release-pro.yml`（`pro-v*.*.*`・`make_latest: false`）・両方の release でタグの版を環境変数で渡す
  - `bin/check-pro-zip.php`: Pro の zip に無料版の名前空間が無いこと（PHP のトークンで読む）と、版 1.0.0 以上のリリースの設定（接続先・製品 ID・`Update URI`。`define()` もトークンで読む）を zip を作るときに確かめる
- 実測（3.8.1）: package は有効化済み・期限内だけ／期限後の `update` でもサーバーが有効化を消す／節のキーは訳した表示名／3.6.0 との差は「期限内でも同じ instance で別のキーの有効化が通る」だけ（R3-8a の処理は変えない）
- コミット:
  - 5a0dd00 feat(pro): deliver Pro updates from the license server (R3-8c)
  - 8fd17ca build: build and release the Pro add-on zip (R3-8c)
  - 2bec266 docs: record R3-8c (Pro updates, zip, and release)
  - a775c7a fix(pro): read translated section names and tidy the update states (R3-8c R1)
  - c2184e0 fix(build): harden the Pro namespace check and pass tag versions via env (R3-8c R1)
  - 883bc66 / 5e56a1b / 5ce4c9f / 0a05bef docs（review-loop R1・R2 の記録・状態ファイル）
  - f401acd fix(build): check the Pro release settings and namespaces when building the zip（G1）
  - ba6568e docs: describe the Pro zip check (R3-8c G1)
  - f9dcee6 fix(pro): drop stale update info as soon as the server deactivates（G2）
  - 2777359 fix(build): read the Pro release defaults from PHP tokens（G2）
  - 09b1b5d fix(build): refuse define() calls the release check cannot read（G3）
  - 9ec9061 / f3993d2 / 268cddd docs（ゲートラウンドの記録）
- 設計ドキュメントからの逸脱（PR 本文と同じ）: `Update URI` はこの PR で入れない（販売サイトの URL と同時）／本番の販売サイトでの再実測は手元の 3.8.1 で代用（本番での確認は `docs/10` の「販売サイト」に残した）／
  Pro の GitHub Release は `make_latest: false`・自動ノートなし／`plugininformation` ではなく `information`（JSON）／`--version` の照合は無料版のリリースにも効く／DB の複製は記録だけ・マルチサイトは対象外

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己 + 独立サブエージェント） | Medium 1・Low 8（＋自己の変異で Low 1） | 7（R1-1〜R1-5・R1-7・R1-9）＋R1-8 は記録 | R1-L1・R1-L2・R1-X1 |
| R2（検証） | R1 はすべて解消・新規 Low 1 | 1（R2-1 docs） | R2-X1 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（「Didn't find any major issues」。自動レビューが 5 分で来ず review コメントを自動投稿〔依頼 1 回〕） |
| G1 | Copilot | 2（スレッド 1・本文 1） | 2 | 0 | 未収束 |
| G2 | Copilot | 2（本文） | 2 | 0 | 未収束 |
| G3 | Copilot | 1（本文） | 1 | 0 | 上限（3 回目でも新規 1。修正して CI green、再依頼はしない） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | `pro-v*` のタグのリリースが `LicenseConfigTest` のリリースの検査を通らない → `bin/check-pro-zip.php` を zip の作成で呼ぶ | f401acd, ba6568e | [r4243577492](https://github.com/artisanworkshop/cart-bridge-jp/pull/125#discussion_r4243577492)（Resolve 済み） |
| G1-B1 | Copilot | Low | 名前空間の検査が複数行の宣言を見落とす → PHP のトークンで読む | f401acd, ba6568e | なし（本文） |
| G2-B1 | Copilot | Medium | サーバーの無効化の成功後に記録が失敗すると更新の情報が残る → 成功の時点で捨てる | f9dcee6 | なし（本文） |
| G2-B2 | Copilot | Medium | リリースの検査がコメントアウトした `define()` を読みうる → トークンで読む（`LicenseConfigTest` も） | 2777359 | なし（本文） |
| G3-B1 | Copilot | Medium | 式を値に持つ `define()` を読み飛ばし別のリテラルの定義を読みうる → 読めない `define()` は未設定として止める | 09b1b5d | なし（本文） |

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし（未解決のスレッドは 0 件）

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38184687173 green（PHP quality 8.2/8.3・PHPUnit (wp-env)・JS/TS・Distribution (plugin zips)・Dev tooling）
- 品質チェック: `quality.sh` green（PHPUnit 無料版 1277・Pro 829、Jest 99、PHPCS・PHPStan・i18n:check・dev マウントの 403）
- 最後の push（T=2026-10-11T23:32:42Z）以降に、依頼していないレビュー・新しいスレッドは無い

## マージ前に人間が確認すべき点
- 新しい文字列（Pro の設定画面の説明・更新の行の案内・詳細の WP_Error）は英語のまま。訳は R3-8d でまとめて行う
- `Update URI`・`CBJP_PRO_LICENSE_API_URL`・`CBJP_PRO_LICENSE_PRODUCT_ID` は販売サイトが決まったときに同じ host で入れる（入れないまま 1.0.0 にすると、テストと zip の検査が止める）
- タグ（`pro-v*`）の push はしていない。リリースはユーザーの指示で行う

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: R3-8d（Pro の i18n。R3-8a〜c で足した文字列もまとめて訳す）→ R3-9 → R3-5b → R3-4
- backlog に送ったもの: `r3-8c-pro-updates-and-release/R1-L1`（Pro が起動しないと更新も届かない・ヘッダーの読み取りが全要求）、`R1-L2`（記録）、`R1-X1`（無料版の自動リリースノートの起点）、`R2-X1`（既存のテストの順序依存）
