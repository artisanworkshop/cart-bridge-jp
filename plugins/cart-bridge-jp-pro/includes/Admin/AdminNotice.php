<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Admin;

/**
 * Pro の設定画面の操作の結果の文を、`admin-post.php` から転送した先の画面へ渡す（ユーザーごとの短い transient。サーバーの文を URL に載せない）。
 * ライセンスのタブ（R3-8a）とパスワード設定メールのタブ（R3-10）が、別々の名前で使う。
 */
final class AdminNotice {

	private const TTL = 300;

	public function __construct( private readonly string $prefix ) {}

	public function set( string $type, string $message ): void {
		set_transient(
			$this->key(),
			[
				'type'    => $type,
				'message' => $message,
			],
			self::TTL
		);
	}

	/**
	 * 記録した文を出して消す。壊れた値は出さない。
	 */
	public function render(): void {
		$key    = $this->key();
		$notice = get_transient( $key );

		if ( false === $notice ) {
			return;
		}

		delete_transient( $key );

		if ( ! is_array( $notice ) || ! in_array( $notice['type'] ?? null, [ 'success', 'error', 'warning' ], true ) || ! is_string( $notice['message'] ?? null ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	private function key(): string {
		return $this->prefix . get_current_user_id();
	}
}
