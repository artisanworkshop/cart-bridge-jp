---
name: verify-with-mock-adapter
description: >
  ライブの OAuth 接続なしで、wp-env の開発サイト上で Cart Bridge JP の REST・管理画面（React）を検証する手順。
  mock アダプタを mu-plugin で任意のプラットフォームキー（例: `colorme`）に登録し、修正前のデータ（旧バグの出力・
  一部だけ壊れた状態）を実 Writer で再現して投入 → `rest_do_request()` と管理画面で Scan/Repair/Import/Export を通し →
  投入したものを完全に撤去する。「mock アダプタで確認して」「OAuth なしで実機確認したい」「旧データを再現して是正ツールを
  試したい」「Tools/Export/Import を実際に動かして見たい」「検証用データを片付けて」などと言われたら使う。
  データ是正系のツール（県コード修復のような）、Export/Import の配線、Tools タブの追加を実装した後の実機確認が主な用途。
---

# /verify-with-mock-adapter — OAuth なしの実機確認

実店舗の認証情報が無い（副管理者では ColorMe の再認可が通らない等）状況でも、REST と React UI を実際に動かして確認できるようにする。
**単体テストでは見えない層間のズレ**（Transformer → Canonical → Writer → ツールの入力の食い違い、UI の状態遷移、ブラウザ固有の問題）
を見つけるのが目的で、単体テストの代わりではない（`PrefStateRepairTest` のような結合テストも併せて書く）。

## いつ使うか / 使わないか

- 使う: 新しい `/tools/*`・Export/Import の REST・Tools タブの UI を実装した直後。既にインポート済みの誤ったデータを直すツールの検証（旧出力の再現が要る）。
- 使わない: 実 API の応答形の確認（mock は実 API を返さない。認証情報が揃ってから実店舗で確認する。PR 本文に「実 API 未確認」と明記する）。
  実アダプタ（`ColorMeAdapter` 等）を偽のキーで登録する検証（下記「してはいけないこと」）。

## 手順

`S=.claude/skills/verify-with-mock-adapter`（リポジトリルートから実行）。wp-env は起動済みであること（`npx wp-env start`。ポートは
`.wp-env.override.json` で固定）。

1. **開発サイトの既存データを確認する**（実データを汚さないため。最初に必ず）:
   `$S/scripts/mock-adapter.sh inspect` — `cbjp_mappings` の platform 別件数・`cbjp_*` オプション・ユーザー数・受注数を出す。
   実 platform（例: `colorme`）の行が既にある場合、その platform の mock 登録は「追加のみ・撤去は自分の投入分だけ」で行う。
2. **mock アダプタを登録する**: `$S/scripts/mock-adapter.sh install <platform-key>`
   （例: `colorme`。県コード修復のように `AddressMapper` が `colorme` だけを解釈するツールは、そのキーで登録する必要がある）。
   `templates/mu-plugin-mock-adapter.php` を wp-env の `mu-plugins/` へ置く。mock は `tests/unit/Fixtures/MockPlatformAdapter`
   （composer の autoload-dev。`composer install` 済みなら dev サイトから使える）で、オプション `cbjp_verify_seed` から
   顧客・受注を組み立てる。このオプションは **PHP 配列を `update_option()` で保存する**（JSON 文字列ではない。mu-plugin は
   `get_option()` の戻り値を配列として読む。配列でない値・配列でない行は読み飛ばす）。
   **クラス定義を mu-plugin のトップレベルに書かない**（mu-plugins は通常プラグインより先に読み込まれ、
   autoloader がまだ無い）。`plugins_loaded` のコールバック内で `new` する。
   mock の `id()` は登録キーと同じ値を返す（`platform_id`）。`Importer`/`Exporter`/`JobManager` は mapping・上限・サンプルのキーを
   登録キーではなく `$adapter->id()` から決めるため。したがって `colorme` で登録して Import/Export を `JobManager` 経由で回すと、
   実 `colorme` の mapping と無料版の上限（`LimitPolicy`）を共有する。Import/Export の配線だけを見たいなら、実 platform と
   衝突しないキー（例: `mockv`）で登録する。`colorme` で回すなら `ZZV-` の remote_id と手順 6 の撤去を必ず守る。
3. **修正前のデータを再現して投入する**（実 Writer を使う）: `$S/scripts/mock-adapter.sh run <seed.php>`。
   seed は `WooRepositoryFactory::for_platform()->write()` / `OrderWriter` で実際に取り込み → 旧コードの出力へ書き戻す（例:
   県コードは `JP{pref_id}` の恒等変換）。投入した ID を `cbjp_verify_ids` オプションに記録し、`cbjp_verify_seed` に mock へ渡す
   データを保存する。`examples/prefecture-repair/seed.php` が実例。投入する実体の `remote_id` は `ZZV-` 接頭辞にする（撤去時の目印）。
4. **REST で検証する**: `$S/scripts/mock-adapter.sh run <verify.php>`。`wp_set_current_user(1)` のうえ `rest_do_request()`。
   **クエリ文字列をルートに含めず `set_query_params()` を使う**（含めると `rest_no_route`）。
   Scan（読取専用）が書かないこと・Repair 後に期待値になること・再実行で変更 0（冪等）を確認する。実例: `examples/prefecture-repair/verify-rest.php`。
5. **管理画面で確認する**（React の変更があるとき）: `npm run build` → Chrome で `http://localhost:<port>/wp-admin/admin.php?page=cart-bridge-jp#/tools`。
   - **ログインはユーザーに依頼する**（アシスタントはパスワードを入力できない）。
   - **ビルドし直したら cmd+r でリロードする**。ハッシュだけが違う URL への `navigate` は SPA を再読込せず、古いバンドルが残る
     （文言が古いまま、と気付いたらこれ）。
   - ネイティブの `window.confirm()` を出すボタン（Sample data cleanup 等）をブラウザ自動操作で押さない（タブがフリーズする）。
   - 確認後にコンソールエラーを見る（`read_console_messages` の `onlyErrors`）。
6. **完全に撤去する**（必須）: `$S/scripts/mock-adapter.sh run <cleanup.php>`（投入したユーザー・受注・mapping・オプションを削除。
   `examples/prefecture-repair/cleanup.php`）→ `$S/scripts/mock-adapter.sh uninstall`（mu-plugin を削除）→ `inspect` で
   手順 1 の状態に戻ったことを確認 → リポジトリ内の一時ファイルを削除。**一時ファイルはコミットしない**（`git status` で確認）。

## 落とし穴（PR #80 の Export タブ UI 検証で実際に踏んだもの）

- **`mock-adapter.sh run` に渡す PHP はリポジトリ内に置く**（`wp eval-file` はコンテナ内で実行されるため、`/tmp` やスクラッチパッドはコンテナから見えない）。コミット前に削除する。
- **`AdapterRegistry::all()` は静的キャッシュ**。同一 PHP プロセス内で `cbjp_verify_seed` を書き換えて再検証するなら、書き換えの直後に `CartBridgeJP\Adapters\AdapterRegistry::reset_cache()` を呼ぶ（呼ばないと最初に組み立てた mock がそのまま使われ、seed の変更が効かない）。
- **`cbjp_process_job`（Action Scheduler）は CLI では自走しない**。`start_run` の後、検証スクリプト内で pending を処理する（`as_get_scheduled_actions( [ 'hook' => 'cbjp_process_job', 'status' => 'pending' ] )` → `do_action_ref_array( $action->get_hook(), $action->get_args() )` → `ActionScheduler_Store::instance()->mark_complete()`）。
- **mock のキー（例 `mockv`）は `connected` ではないので、Export/Import タブの platform 選択に出ない**（`ExportTab` は `c.connected` の platform だけを扱う）。管理画面で目視したいときは、接続済みの実 platform（`colorme`）に対象の行を手で挿入し、UI の操作で消す。`push` 系の挙動は `cbjp_verify_seed.push`（`enabled`/`create_failure`）で切り替える。
- **DB を直接変えた後の目視は必ず `cmd+r`**。同じ URL への `navigate` は再読込にならず（performance entries も引き継がれる）、マウント時に1回だけ取得するコンポーネントは古い応答のまま残る。今回は「データが消えた」と誤診して長時間の調査になった。
- **`npx wp-env run cli wp eval '<複数行の PHP>' | tail -N` は結果行が切れる**（wp-env が実行コマンドの全文を前後に出すため、`tail` が結果ではなくコマンドの echo だけを拾う）。`tail` を付けないか、`echo "RESULT: …"` の目印行を出して `grep` する。

## してはいけないこと

- **実アダプタ（`ColorMeAdapter` 等）を偽のキーで登録して `JobManager`/`Exporter` を通さない**: mapping・サンプルキャッシュのキーは登録キーではなく
  `$adapter->id()` から決まる（`ColorMeAdapter::id()` は固定で `final`）ため、`colorme` の実 mapping を書き換えうる。実アダプタを試すなら
  `Woo\Reader\*` を直接呼び、`push_*()` を `pre_http_request` のモックで直接呼ぶ（`Sync\Exporter` と mapping 永続化を通さない）。
- 実 platform の既存行を消す/上書きする。撤去は自分が投入した ID・`ZZV-` の remote_id だけを対象にする。
- WooCommerce の HPOS 設定を切り替えない（同期外れの受注があると WC が拒否する。開発サイトの共有設定を変えない）。
- 他のセッション由来の mu-plugin（例: `cbjp-e2-3-customer`）を消さない。

## ファイル

- `scripts/mock-adapter.sh` — `inspect` / `install <key>` / `run <php>` / `uninstall`（wp-env のパスは `npx wp-env install-path`）
- `templates/mu-plugin-mock-adapter.php` — mock アダプタを登録する mu-plugin（キーと seed オプション名を置換して使う）
- `examples/prefecture-repair/` — 県コード修復ツール（issue #46）の検証一式（`seed.php` / `verify-rest.php` / `cleanup.php`）
- `examples/push-intent-resolution/` — export の作成結果が不明（5xx）→ 印（push intent）が残る → 再 export はブロック → `not_created` 解除 → 再 export で作成、
  と `link` の失敗系（404）を `rest_do_request()` で通す検証（issue #73 / D21-B。R3-1 の「R3-0a/b の確認をモックで」にも使う）。`verify-rest.php`
  は各ステップを PASS/FAIL で出し、失敗があれば非ゼロで終了する（seed は不要で、`cbjp_verify_seed.push` は自分で切り替える）。**前提**: `install mockv` 済み・
  Woo に export できる商品が 1 件以上・前回の残りが無い（あれば先頭で中止するので `cleanup.php` を流す）。どの商品がサンプルに選ばれるかは
  `ExportSampleSelector` 次第。`cleanup.php` は `mockv` の intent・mapping・job・ログとオプションだけを消し、**OAuth トークンを持つ platform は拒否する**。
  `link` の成功系統（実在確認・別実体で使用中の remote_id の 409）は、mock が商品を保持しないため対象外（単体テストが担当）

関連: `docs/03-design-decisions.md` §10.3（ツール）、`.claude/skills/cbj-dev-cycle/SKILL.md`（開発サイクル Step 2 の実機確認）。
