<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * 移行時に発生してはならないWooCommerceの副作用（メール送信・在庫増減）を一時的に抑止する
 * （`docs/03-design-decisions.md` §5 D10 の「受注メール・在庫減算・ポイント付与等の副作用は全て抑止」）。
 *
 * `remove_action('woocommerce_order_status_*', ...)` のようにWC_Emailsのアクション一覧を
 * ハードコードする方式は取らない: WCのバージョンアップで壊れやすく、他プラグインが登録した
 * 正当なフックまで巻き添えで無効化してしまう。代わりに `pre_wp_mail` （WP 5.7+）で
 * `wp_mail()` 自体をショートサーキットする方式を使う。これは `WC()->mailer()` の
 * 初期化タイミングに依存せず、WooCommerceのメールもWPコアの新規ユーザー通知も一撃で止まる。
 */
final class SideEffectGuard {

	/**
	 * WooCommerceの「Deferred emails」機能（`deferred_transactional_emails`、既定無効）が
	 * 有効な場合に`WC_Emails::init_transactional_emails()`が各種メールトリガーへ登録する
	 * コールバック。`WC_Emails::queue_transactional_email()`は`wp_mail()`を直接呼ばず
	 * `DeferredEmailQueue`へ積み、実送信は`shutdown`でスケジュールされる別のAction
	 * Scheduler実行（別リクエスト）で行われるため、`pre_wp_mail`による抑止が一切効かない
	 * （`on()`/`off()`参照）。
	 */
	private const DEFERRED_EMAIL_QUEUE_CALLBACK = [ 'WC_Emails', 'queue_transactional_email' ];

	/**
	 * `deferred_email_hooks()`のキャッシュ（`[フック名 => 優先度]`）。`WC_Emails::init_transactional_emails()`は
	 * WordPressの`init`で1度だけ実行されフック構成はリクエスト中変化しないため、`run()`が
	 * アイテム毎（`WooRepository::write()`経由）に呼ばれても`$wp_filter`全体の走査は初回のみでよい。
	 *
	 * @var array<string,int>|null
	 */
	private ?array $deferred_email_hooks = null;

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function run( callable $callback ): mixed {
		$this->on();

		try {
			return $callback();
		} finally {
			$this->off();
		}
	}

	private function on(): void {
		add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		// `WC_Email::send()` は `apply_filters( 'woocommerce_mail_callback', 'wp_mail', $this )` の
		// 結果を `call_user_func_array()` するため、pre_wp_mail の二重防御として併用する。
		add_filter( 'woocommerce_mail_callback', [ self::class, 'no_op_mail_callback' ], PHP_INT_MAX );
		add_filter( 'send_password_change_email', '__return_false' );
		add_filter( 'send_email_change_email', '__return_false' );

		// ASP側で既に確定済みの受注に対してWoo標準の在庫増減ロジックを再度走らせない
		// （`Writer\OrderWriter` が `set_order_stock_reduced()` でステータスに応じた在庫状態を明示するため）。
		// 減算側は`wc_maybe_reduce_stock_levels()`が参照する`woocommerce_payment_complete_reduce_order_stock`、
		// 復元側は`wc_increase_stock_levels()`が参照する`woocommerce_can_restore_order_stock`と、
		// ゲートのフィルター名がそれぞれ異なる（WooCommerce本体コードで確認済み。
		// `woocommerce_payment_complete_restore_order_stock`という名前のフィルターはWC本体に
		// 存在せず、以前はここが死んだフィルターになっていた）。
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', PHP_INT_MAX );
		add_filter( 'woocommerce_can_restore_order_stock', '__return_false', PHP_INT_MAX );

		// Deferred emails機能が有効な間だけキューイング自体を止める（`pre_wp_mail`は別リクエストの
		// 実送信には効かないため、キューへ積む前段階でブロックする）。
		foreach ( $this->deferred_email_hooks() as $hook => $priority ) {
			remove_filter( $hook, self::DEFERRED_EMAIL_QUEUE_CALLBACK, $priority );
		}
	}

	private function off(): void {
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_filter( 'woocommerce_mail_callback', [ self::class, 'no_op_mail_callback' ], PHP_INT_MAX );
		remove_filter( 'send_password_change_email', '__return_false' );
		remove_filter( 'send_email_change_email', '__return_false' );
		remove_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', PHP_INT_MAX );
		remove_filter( 'woocommerce_can_restore_order_stock', '__return_false', PHP_INT_MAX );

		// `on()`で退避したキューイングを復元する（このガードは移行の書込1件を包む間だけ
		// 副作用を止める設計のため、店舗がこの機能を有効にしている理由・通常運用への
		// 影響を残さないよう、書込が終わったら必ず元に戻す）。`WC_Emails`自身は
		// `add_action()`（値を返さないコールバックとして）でこれを登録しているため、
		// `add_filter()`ではなくこちらを使う（`add_filter()`/`add_action()`はWP内部では
		// 同一実装だが、PHPStanの静的解析は`add_filter()`で登録されたコールバックに
		// 戻り値を要求するため区別する）。
		foreach ( $this->deferred_email_hooks() as $hook => $priority ) {
			add_action( $hook, self::DEFERRED_EMAIL_QUEUE_CALLBACK, $priority, 10 );
		}
	}

	/**
	 * `WC_Emails::queue_transactional_email()`が実際に登録されているフック名と優先度を
	 * `$wp_filter`から動的に検出する。フック名の一覧（`WC_Emails::init_transactional_emails()`が
	 * 参照する`woocommerce_email_actions`）をこちらでハードコードすると、WCのバージョン
	 * アップで新しいアクションが追加された際に追随できず抜け漏れが生じるため、コールバック
	 * 自体（安定したpublic API）を手がかりに検索する。
	 *
	 * @return array<string,int>
	 */
	private function deferred_email_hooks(): array {
		if ( null !== $this->deferred_email_hooks ) {
			return $this->deferred_email_hooks;
		}

		global $wp_filter;

		$hooks = [];

		foreach ( array_keys( $wp_filter ) as $hook ) {
			$priority = has_filter( $hook, self::DEFERRED_EMAIL_QUEUE_CALLBACK );

			if ( false !== $priority ) {
				$hooks[ $hook ] = $priority;
			}
		}

		$this->deferred_email_hooks = $hooks;

		return $hooks;
	}

	public static function no_op_mail_callback(): string {
		return '__return_false';
	}
}
