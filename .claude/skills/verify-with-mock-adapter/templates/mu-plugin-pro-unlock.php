<?php
/**
 * cbjp-verify-pro-unlock: managed by .claude/skills/verify-with-mock-adapter
 *
 * TEMPORARY. 社内のテスト用: Pro アドオンのライセンスキーの有効化の記録を「有効化済み」と読ませ、キーが無くても顧客・受注・クーポンの
 * 取込み・エクスポートとパスワード設定メールの一斉送信を動かす（D30・R3-8b の制限を外す）。`mock-adapter.sh pro-unlock` で置き、
 * `pro-lock` で外す。置いた写し（wp-env の `mu-plugins/`）はコミットしない。プラグインの配布物（`plugins/` 配下）に入れない。社内のテストサイト以外に置かない。
 *
 * - Pro の判定は `LicenseStore::is_activated()` の 1 点で、option `cbjp_pro_license_state` の `activated` が 'yes' かどうかだけを見る
 *   （R3-8a。サーバーへの問い合わせや署名の検証は無い。`docs/03` §10.7「守ること」4）。ここでは WordPress コアの `pre_option_{option}`
 *   フィルターでその option の読み出しを差し替えるだけなので、DB には何も書かず、このファイルを消せば即座に元に戻る。Pro のコードは変えない。
 * - option の名前は Pro の `LicenseStore::STATE_OPTION` と同じ文字列にする（mu-plugins は Pro より先に読み込まれ autoloader がまだ無いので、
 *   定数を参照しない。Pro 側で名前を変えたらここも直す）。
 * - `wp_get_environment_type()` が `production` のサイトでは何もしない。`WP_ENVIRONMENT_TYPE` を設定していないサイトは production になるので、
 *   明示的に local・development・staging にしたサイトでだけ効く（wp-env は local）。効いている間と、production で効いていないときは
 *   管理画面に通知を出し、置いたままにしたことに気付けるようにする。
 * - キーは保存されないので、Pro の設定画面（WooCommerce > Cart Bridge JP Pro）は「保存したキーを読めない」の表示になり、「Save license key」の欄が出る。
 *   **この間にキーを保存しない**（保存すると `UpdateChecker` がそのキーと instance で販売サイトへ問い合わせる）。更新の確認はキーが無いので
 *   販売サイトへ出ない（期待どおり。テストサイトには Pro の更新は届かない）。
 * - 致命的エラーを出さない（mu-plugin の fatal は開発サイトの全リクエストを落とす）。Pro のクラスを参照しない。
 */

if ( 'production' === wp_get_environment_type() ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( 'cbjp-verify-pro-unlock.php is installed but not applied because the environment type of this site is production. Remove it with mock-adapter.sh pro-lock.' )
			);
		}
	);

	return;
}

// `LicenseStore::is_activated()` は `'yes' === $state['activated']` だけを見る。`activated_at` は 0 だと日時なしの「Activated」になる。
add_filter(
	'pre_option_cbjp_pro_license_state',
	static fn () => [
		'activated'    => 'yes',
		'activated_at' => 0,
		'product_id'   => 0,
	]
);

add_action(
	'admin_notices',
	static function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( 'Cart Bridge JP Pro is unlocked for testing by the mu-plugin cbjp-verify-pro-unlock.php (no license key). Do not save a license key while it is installed. Remove it with mock-adapter.sh pro-lock.' )
		);
	}
);
