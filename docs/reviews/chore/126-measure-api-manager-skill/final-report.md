# dev-cycle 最終報告: chore/126-measure-api-manager-skill

## 開発内容
- タスク: issue #126（API Manager を dev サイトに一時的に入れて実測し、完全に消す手順をスキルにする）/ PR #127 https://github.com/artisanworkshop/cart-bridge-jp/pull/127
- 承認された計画（2026-10-12。ユーザー決定 2 点）: 更新の配信の E2E も含める（`Update URI` が空の間は Pro のヘッダーに 1 行を一時的に足す。守りつき）／本番の販売サイトに要求を送るモードは作らない
- 実装: プロジェクトスキル `.claude/skills/measure-api-manager/`（配布物には入らない）
  - `scripts/am.sh`: `status`・`setup --zip`・`measure`・`compare`・`e2e <step>`・`teardown`
  - setup: API Manager（zip はリポジトリの外）・API の製品・ページ・顧客・完了した注文 2 件・キー 2 つ。Pro を手元へ向ける mu-plugin は、一時ファイルから rename で置き、更新の実行も止める
  - measure: R3-8a・R3-8c の場面を 7 グループの生の要求に。各グループの初めにリソースを戻し、読み直して確かめる。HTTP 200 以外は失敗
  - compare: 今の setup の応答（`run.json`）だけを匿名化してフィクスチャと比べる。フィクスチャは書き換えない。手元の値（キーも）が残れば書かない
  - e2e: 更新の配信の実機確認。Pro のヘッダーは同じディレクトリの一時ファイルから rename で書き換えて戻す
  - teardown: 消し残しを数え直して 0 でなければ exit 1。数えると消すで同じ範囲の関数を使う
  - 守り: preflight（Pro の起動まで）・状態ファイルと DB のトークン・記録の前に止まった分の回収
- `php/test-anon.php`（匿名化・比較の回帰テスト）を `quality.sh` と CI の dev-tooling ジョブ（PHP 8.2）に追加
- ドキュメント: CLAUDE.md の作業の進め方、`.claude/rules/pro-license.md`、`docs/03` §10.7「R3-8c の実装」、`docs/10` R3-8「販売サイト」からスキルを指す
- コミット:
  - d969e14 chore: add the measure-api-manager skill for local API Manager probes (#126)
  - 6f99619 docs: point to the measure-api-manager skill (#126)
  - e200e30 fix: bind the API Manager probe to its database and tighten its checks (#126 R1)
  - e4c1f13 fix: close the remaining false passes in the API Manager probe (#126 R2)
  - c99ea72 fix: harden the API Manager probe against partial failures (#126 G1)
  - e85b6a6 fix: invalidate stale probe state and install the mu-plugin atomically (#126 G2)
  - 6f73418 fix: recover unrecorded probe data and rewrite the Pro header atomically (#126 G3)
  - e3523bc / 8f93e47 / 866cf15 / fdf66a7 docs（review-loop・ゲートラウンドの記録）
- 設計ドキュメントからの逸脱（PR 本文と同じ）: 設計の変更なし。dev サイト専用（本番での再実測は人が行う。SKILL.md に項目の一覧）／E2E は追跡しているファイル（Pro のメインファイル）を一時的に書き換える（守りつき。戻し忘れは `LicenseConfigTest` が止める）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己 + 独立サブエージェント） | Medium 3・Low 9 | 12（e200e30） | 0 |
| R2（検証） | R1 の Medium はすべて解消。新規 Low 3・言い残し・範囲外 2 | すべて（e4c1f13）。APPROVE | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 10（スレッド。本人の評価で High 4・Medium 6） | 10 | 0 | 未収束 |
| G1 | Codex | 2（P2） | 2 | 0 | 未収束（自動レビューが 5 分で来ず review コメントを自動投稿〔依頼 1 回〕） |
| G2 | Copilot | 4（スレッド 2〔High 1〕・本文 2） | 4 | 0 | 未収束 |
| G2 | Codex | 2（P2。1 件は Copilot の本文と同じ箇所） | 2 | 0 | 未収束 |
| G3 | Copilot | 5（スレッド 2〔High 2〕・本文 3） | 5 | 0 | 上限（再依頼しない） |
| G3 | Codex | 2（P2） | 2 | 0 | 上限（再依頼しない） |

指摘の詳細は `G1.md`〜`G3.md`。保留・対応不要は無く、未解決のスレッドは 0 件。

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38201854958 green（最後の push fdf66a7。PHP quality 8.2/8.3・PHPUnit (wp-env)・JS/TS・Distribution (plugin zips)・Dev tooling）
- 最後の push（T=2026-10-12T03:57:59Z）以降に、依頼していないレビュー・新しいスレッドは無い
- 品質チェック: `quality.sh` green（PHPUnit 無料版 1277・Pro 829、Jest 99、PHPCS・PHPStan・i18n:check、`test-anon.php`）
- dev サイトでの通しの確認（各ラウンドの後）: 3.8.1 でフィクスチャ 13 件が一致、3.6.0 でも setup → compare（information の節の名前だけが違う）→ teardown
  - setup の前と teardown の後で、全テーブルの行数・option・transient・cron・予定・ファイルが同じ
  - 守り（2 回目の setup・未コミットの変更がある E2E・目印の無い mu-plugin・状態ファイルが無い/DB と結び付かない残り・wordpress.org に届かない E2E・無料版が無効の preflight・記録の前に止まった分の回収）を実測
- `anon.php` のガードの変異 14 種がすべてテストで止まる（`mutate-check.sh`）

## マージ前に人間が確認すべき点
- E2E は作業ツリーの Pro のメインファイルを一時的に書き換える（`e2e start`〜`stop`）。その間はコミットしない（SKILL.md「安全規則」）
- dev サイトに、R3-8c の手での片付けで残った WooCommerce の予定が 2 件ある（製品 867 の属性の索引の更新。action 2059・2060。害は無い）。このスキルの setup の前後の比較で見つかった。消すかはご判断ください

## 次にできること（人間の判断）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`
- 次のタスク: R3-8d（Pro の i18n）→ R3-9 → R3-5b → R3-4
- 次に API Manager の版が上がったとき・販売サイトに入れる版を決めるときに `/measure-api-manager` で実測する
