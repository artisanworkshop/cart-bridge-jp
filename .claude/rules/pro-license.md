---
paths:
  - "plugins/cart-bridge-jp-pro/includes/License/**"
  - "plugins/cart-bridge-jp-pro/includes/Admin/LicensePage.php"
  - "plugins/cart-bridge-jp-pro/tests/unit/License/**"
  - "plugins/cart-bridge-jp-pro/tests/fixtures/api-manager/**"
---

# Pro のライセンス・更新の配信（WooCommerce API Manager）の落とし穴

> Pro の `includes/License/**`・設定画面（`Admin\LicensePage`）を触るときに効く。実測の記録と設計の全体は `docs/03` §10.7「R3-8a の実装」「R3-8b の実装」「R3-8c の実装」。
> 応答のフィクスチャは Pro の `tests/fixtures/api-manager/`（3.6.0・3.8.1 で実測）。販売サイトの API Manager の版が変わったら、ここに書いた癖を実測し直す。

- **エラーでも HTTP 200** で、本文が `success: false`。成功は HTTP 200・JSON のオブジェクト・`success === true`（実 bool）・操作ごとの印（`activated`・`deactivated` など）がそろったときだけにする（`LicenseClient::request()`）
- **`information` の `sections` のキーは、販売サイトの言語に訳した表示名**（en は `Description`・`Other notes`、ja は「説明」「その他の注意点」など）で、WP の詳細の画面が引く小文字の名前（`description`・`other_notes`）と違う。
  `LicenseClient::SECTIONS` の対応表に英語と ja の両方を持つ（英語だけだと ja の販売サイトで節がすべて捨てられた。PR #125 R1-1）。販売サイトの言語を変える・節を足すときは表も直す
- 公式のクライアントが使う `plugininformation` は PHP のシリアライズで返り `maybe_unserialize()` が要るので使わない。JSON の `information` を使う（リモートの値を unserialize しない）
- **package（ダウンロードの URL）は、有効化済みの instance・キーで期限内のときだけ付く**。期限後の `update` では、サーバーがその有効化を消して package を空で返す。API Manager は応答を DB に最大 60 分キャッシュするので、
  期限の直後もしばらく package が付きうる。3.8.x は期限内でも同じ instance で別のキーの有効化を通す（3.6.0 は断った）
- **`plugins_api` で自分のスラッグ（`cart-bridge-jp-pro`）に false を返さない**。false だと WP が wordpress.org に同じスラッグを問い合わせ、別のプラグインの詳細が出うる。答えられないときは `WP_Error`（`UpdateChecker::filter_plugin_information()`）
- package の無い更新には `disable_autoupdate => true` を付ける（`WP_Automatic_Updater::should_update()`）。付けないと、自動更新を有効にしたサイトで WP が新しい版ごとに取得を試みて失敗する（PR #125 R1-4）
- **有効化の状態を変えたら `update_plugins` の site transient を捨てる**（`LicenseManager::forget_update_info()`。前の確認の package は約 7 日有効で、transient は最大 12 時間残る）。
  **サーバーの操作が成功した時点で捨て、手元の記録の成否に結び付けない**（無効化がサーバーで成功した後に記録の書き込みに失敗して早く戻ると、無効化したサイトに更新が出続けた。PR #125 G2-B1）
- `wp_update_plugins()` は wordpress.org への要求が失敗する（WP_Error・200 以外）と、`update_plugins_{host}` を呼ぶ前に戻る（`wp-includes/update.php`）。wordpress.org に届かないサイトには Pro の更新も出ない（コアの仕様）
- `wp_update_plugins()` を通すテストは、`wp_cache_set( 'plugins', [ '' => $plugins ], 'plugins' )` で `get_plugins()` の結果に `UpdateURI` を持つ Pro を入れ、wordpress.org への要求（`pre_http_request`）には `plugins`・`translations`・`no_update` が空の 200 応答を返す
  （上の早期の return を避ける。`UpdateCheckerTest`）。更新の実行（`Plugin_Upgrader`）は dev・tests サイトでも行わない（CLAUDE.md のコマンド欄）
