<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

/**
 * ライセンスの操作（R3-8a）。画面（`Admin\LicensePage`）と、R3-8b の判定（`is_activated()`）が使う。
 *
 * 有効化の状態はローカルの記録だけで決める（D14: 期限後もそのサイトでは使い続けられる）。API Manager は期限後の状態確認で
 * そのサイトの有効化を消し、無効化も受け付けなくなる（実測。`LicenseClient`）ので:
 * - 状態確認は結果を表示用に記録するだけで、有効化の状態を変えない
 * - 無効化はサーバーが成功を返したときだけローカルの状態を消す（ローカルだけ消す手段は作らない。消すと期限後は有効化し直せない）
 * - 有効化は、有効化済みのサイトでも同じ instance で別のキーを試せる（キーの交換。2026-10-11 ユーザー決定）。サーバーにこのサイトの有効化が
 *   残っていれば（期限内）サーバーが「同じ instance で有効化済み」と断るので、先に無効化してもらう。期限後に新しいキーを買った人が、
 *   無効化できない古いキーを替えられるようにするため
 */
final class LicenseManager {

	/** キーの形（API Manager の既定は 40 桁の 16 進数。フィルターで替えられ、列は VARCHAR(190)）。 */
	private const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,190}\z/';

	public function __construct(
		private readonly LicenseConfig $config,
		private readonly LicenseClient $client,
		private readonly LicenseStore $store
	) {}

	public static function create(): self {
		$config = LicenseConfig::from_constants();

		return new self( $config, new LicenseClient( $config ), new LicenseStore() );
	}

	public function is_configured(): bool {
		return $this->config->is_configured();
	}

	public function is_activated(): bool {
		return $this->store->is_activated();
	}

	public function store(): LicenseStore {
		return $this->store;
	}

	/**
	 * キーを有効化する（有効化済みならキーの交換）。サーバーが成功を返したとき、または未有効化のサイトで有効化が失敗した後の状態確認で
	 * サーバーがこの instance を有効化済みと答えたときだけ保存する（`server_lists_instance()`）。
	 */
	public function activate( mixed $raw_key ): LicenseResult {
		$key = self::normalize_key( $raw_key );

		if ( null === $key ) {
			return LicenseResult::failure( LicenseResult::INVALID_KEY );
		}

		$product_id = $this->config->product_id();

		if ( ! $this->config->is_configured() || null === $product_id ) {
			return LicenseResult::failure( LicenseResult::NOT_CONFIGURED );
		}

		$instance = $this->store->instance();

		if ( null === $instance ) {
			return LicenseResult::failure( LicenseResult::STORAGE_ERROR );
		}

		$was_activated  = $this->store->is_activated();
		$result         = $this->client->activate( $key, $instance );
		$server_has_key = $result->is_success() || ( ! $was_activated && $this->server_lists_instance( $key, $instance ) );

		if ( $server_has_key ) {
			// サーバーは有効化した。手元に保存できなければ成功と言わない（次の有効化は突き合わせで保存し直せる。PR #123 G3-B1）。
			return $this->store->save_activation( $key, $product_id ) ? LicenseResult::success() : LicenseResult::failure( LicenseResult::STORAGE_ERROR );
		}

		return $result;
	}

	/**
	 * 有効化を解除する。サーバーが成功を返したときだけローカルの状態を消す。
	 */
	public function deactivate(): LicenseResult {
		$key = $this->store->key();

		if ( ! $this->store->is_activated() || null === $key ) {
			return LicenseResult::failure( LicenseResult::NOT_ALLOWED );
		}

		$instance = $this->store->instance();

		if ( null === $instance ) {
			return LicenseResult::failure( LicenseResult::STORAGE_ERROR );
		}

		$result = $this->client->deactivate( $key, $instance );

		if ( $result->is_success() && ! $this->store->clear_activation() ) {
			return LicenseResult::failure( LicenseResult::STORAGE_ERROR );
		}

		return $result;
	}

	/**
	 * サーバーに状態を問い合わせ、結果を表示用に記録する。有効化の状態は変えない。
	 */
	public function check_status(): LicenseResult {
		$key = $this->store->key();

		if ( null === $key ) {
			return LicenseResult::failure( LicenseResult::NOT_ALLOWED );
		}

		$instance = $this->store->instance();

		if ( null === $instance ) {
			return LicenseResult::failure( LicenseResult::STORAGE_ERROR );
		}

		$result = $this->client->status( $key, $instance );

		if ( $result->is_success() ) {
			$expires = $result->data['expires'] ?? null;

			$this->store->record_check( true === ( $result->data['activated'] ?? null ), is_string( $expires ) ? $expires : null );
		}

		return $result;
	}

	/**
	 * 有効化済みで保存したキーを読めないとき（AUTH_KEY・AUTH_SALT が変わった、保存の途中で失敗した）、キーだけを保存し直す。サーバーには問い合わせない
	 * （このサイトの有効化はサーバーに残っているので、同じ instance での有効化はサーバーが断る）。
	 */
	public function replace_unreadable_key( mixed $raw_key ): LicenseResult {
		if ( ! $this->store->is_activated() || ! $this->store->key_unreadable() ) {
			return LicenseResult::failure( LicenseResult::NOT_ALLOWED );
		}

		$key = self::normalize_key( $raw_key );

		if ( null === $key ) {
			return LicenseResult::failure( LicenseResult::INVALID_KEY );
		}

		return $this->store->save_key( $key ) ? LicenseResult::success() : LicenseResult::failure( LicenseResult::STORAGE_ERROR );
	}

	/**
	 * 有効化が失敗したとき、サーバーがこの instance を有効化済みと見ているかを状態確認で確かめる（review-loop R1-1）。
	 * サーバーが有効化を記録したのに応答を受け取れなかった（タイムアウト・壊れた応答）と、ローカルは未有効化のまま、以後の有効化が
	 * 「同じ instance で有効化済み」で断られ続け、未有効化の画面からは無効化も状態確認もできない。サーバーの状態確認は instance で
	 * 有効化を見るので、入力したキーで期間内なら `active` が返る。確かめられなければ（通信の失敗・`inactive`）有効化として扱わない。
	 */
	private function server_lists_instance( string $key, string $instance ): bool {
		$status = $this->client->status( $key, $instance );

		return $status->is_success() && true === ( $status->data['activated'] ?? null );
	}

	/**
	 * 入力されたキー（前後の空白を除く）。形が違えば null。
	 */
	public static function normalize_key( mixed $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$key = trim( $raw );

		return 1 === preg_match( self::KEY_PATTERN, $key ) ? $key : null;
	}
}
