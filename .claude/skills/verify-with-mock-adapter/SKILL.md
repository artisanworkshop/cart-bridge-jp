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
   （composer の autoload-dev。`composer install` 済みなら dev サイトから使える）で、オプション `cbjp_verify_seed`（JSON）から
   顧客・受注を組み立てる。**クラス定義を mu-plugin のトップレベルに書かない**（mu-plugins は通常プラグインより先に読み込まれ、
   autoloader がまだ無い）。`plugins_loaded` のコールバック内で `new` する。
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

関連: `docs/03-design-decisions.md` §10.3（ツール）、`.claude/skills/cbj-dev-cycle/SKILL.md`（開発サイクル Step 2 の実機確認）。
