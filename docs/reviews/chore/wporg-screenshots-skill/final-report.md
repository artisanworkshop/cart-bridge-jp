# dev-cycle 最終報告: chore/wporg-screenshots-skill

## 開発内容
- タスク: wordpress.org 用スクリーンショットを撮り直すプロジェクトスキル `/wporg-screenshots` の追加（R3-3 の撮影手順のスキル化。R3-6・R3-4 で使う）
- PR: #112 https://github.com/artisanworkshop/cart-bridge-jp/pull/112
- 承認された計画の要約:
  - `.claude/skills/wporg-screenshots/` を追加した（`capture.sh`・mu-plugin テンプレート・`setup.php`・`shoot.cjs`・`shots.json`・SKILL.md）。CLAUDE.md と `docs/10` の R3-6 から参照する。
  - 撮影は tests サイトだけで行い、匿名化済みのフィクスチャで画面を作る。
  - 自動テストは足さない（wp-env・Docker・Chrome が要るため）。`.wordpress-org/` の画像は変えない。
- コミット:
  - `1ceff76` chore: add the wporg-screenshots skill for the wordpress.org screenshots
  - `61b3057` docs: point R3-6 at the screenshot skill and start the dev-cycle record
  - `d5dbc65` fix: make the screenshot skill clean up and process jobs safely (R1)
  - `d4e7894` docs: record review-loop R1 for the screenshot skill
  - `bbf0e29` fix: report each entity's status when the screenshot dry run stops (R2-1)
  - `1b505b3` docs: record review-loop R2 for the screenshot skill (APPROVE)
  - `bd00a64` docs: update the screenshot skill dev-cycle state before opening the PR
  - `2972d1b` fix: pin the screenshot shipping zone and tear down before removing the mock (G1)
  - `0f82d1a` docs: record dev-cycle gate round 1 for the screenshot skill
  - `07f994c` fix: refuse a foreign Color Me token and pin the admin locale for screenshots (G2)
  - `00b4fb8` docs: record dev-cycle gate round 2 for the screenshot skill
  - `8b8a7e3` fix: keep the screenshot mock when the plugin is inactive but state remains (G3)
  - `4b193d8` docs: record dev-cycle gate round 3 for the screenshot skill
- 設計ドキュメントからの逸脱: なし（配布物とプラグインのコードに触れない）。ブランチ名は開発補助の前例（PR #50）に合わせて `chore/`

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | High 1・Medium 3・Low 5 | 9 | 0 |
| R2（独立検証・bash 3.2 で実測）→ APPROVE | 新規 Low 1 | 1 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1（スレッド） | 1 | 0 | 未収束 |
| G1 | Codex | 1（スレッド・P1） | 1 | 0 | 未収束 |
| G2 | Copilot | 2（スレッド）＋本文 1 | 3 | 0 | 未収束 |
| G2 | Codex | 1（スレッド・P2） | 1 | 0 | 未収束 |
| G3 | Copilot | 1（スレッド） | 1 | 0 | 上限（3 回目でも新規指摘あり。4 回目は依頼しない） |
| G3 | Codex | 1（スレッド・P2） | 1 | 0 | 上限（同上） |

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-1 | Copilot | Medium | 撮影用の配送ゾーン `Japan` を名前で探して無ければ作り、その定額配送を対応させる（ゾーンが残るとサイトの状態で画面が変わった） | `2972d1b` | [r4227590675](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#discussion_r4227590675) |
| G1-2 | Codex | P1 | `php/teardown.php` を追加。モックを消す前に開いた run をキャンセルし、偽のトークンを消す。失敗したら mu-plugin を残す | `2972d1b` | [r4227614808](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#discussion_r4227614808) |
| G2-1 | Codex | P2 | setup は何かを変える前に、トークンが無いか撮影用の偽物かを確かめる（別のトークンを上書きしていた） | `07f994c` | [r4227724079](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#discussion_r4227724079) |
| G2-2 | Copilot | High | 同上 | `07f994c` | [r4227732248](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#discussion_r4227732248) |
| G2-3 | Copilot | High | teardown もトークンを確かめてから run をキャンセルする | `07f994c` | [r4227732303](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#discussion_r4227732303) |
| G2-B1 | Copilot | Medium | ユーザー 1 の言語を英語に固定する（日本語だと `wait_for` がタイムアウト。修正を外して失敗を実測） | `07f994c` | なし（[review 5467123253](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#pullrequestreview-5467123253)） |
| G3-1 | Codex | P2 | プラグインが無効なときは、トークンか開いたジョブが残っていれば teardown が失敗し、mu-plugin を残す | `8b8a7e3` | [r4227873345](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#discussion_r4227873345) |
| G3-2 | Copilot | High | 同上 | `8b8a7e3` | [r4227881959](https://github.com/artisanworkshop/cart-bridge-jp/pull/112#discussion_r4227881959) |

### 修正しなかった指摘
- なし

## 品質ゲート
- CI: 最終 push（`4b193d8`）の [run 37906434331](https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37906434331) が green。対象は PHP quality 8.2／8.3、PHPUnit（wp-env）、JS/TS、Dev tooling。最終 push 以降に新しいレビュー・スレッドは無い
- 品質チェック（`quality.sh`）: green（PHPUnit 1767・Jest 104・`i18n:check`）。各ゲートの修正の前にも実行した
- 実機（スクリプトの自動テストの代わり）:
  - 撮影の通しを 8 回行い、毎回 5 枚中 4 枚がコミット済みの画像とバイト単位で一致した。残る Import の 1 枚は、WooCommerce メニューの通知バッジの差だけ。
  - 安全装置が働く: dev サイトでは止まる。mu-plugin が無いときも、撮影用でないトークンがあるときも止まる。
  - 後片付けを、次の場面ごとに実測した。正常終了・失敗・Ctrl-C・mu-plugin を消せない場合・強制終了の後の `cleanup`・プラグインが無効な場合。
  - 開いた run のキャンセル、無関係な配送ゾーンがある場合、日本語のユーザー（修正を外すと失敗することも確認）も実測した。

## マージ前に確認してほしいこと
- `SKILL.md` の手順が読みやすいか。特に、本番を撮り直した後に、変えていない画面のバッジの揺れを `git checkout` で戻す運用。
- 撮影のたびに tests サイトへ dry-run のジョブ・明細が完了した状態で残る。PHPUnit では消えない。これは SKILL.md に記載済み。気になる場合は、別途片付けの手段を足す。

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- Copilot から依頼外のレビューが後で届いた場合は `/fix-copilot-review 112`
