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
	 * キーを有効化する（有効化済みならキーの交換）。サーバーが成功を返したときだけ保存する。
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

		$result = $this->client->activate( $key, $this->store->instance() );

		if ( $result->is_success() ) {
			$this->store->save_activation( $key, $product_id );
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

		$result = $this->client->deactivate( $key, $this->store->instance() );

		if ( $result->is_success() ) {
			$this->store->clear_activation();
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

		$result = $this->client->status( $key, $this->store->instance() );

		if ( $result->is_success() ) {
			$expires = $result->data['expires'] ?? null;

			$this->store->record_check( true === ( $result->data['activated'] ?? null ), is_string( $expires ) ? $expires : null );
		}

		return $result;
	}

	/**
	 * 有効化済みで保存したキーを復号できないとき（AUTH_KEY・AUTH_SALT が変わった）、キーだけを保存し直す。サーバーには問い合わせない
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

		$this->store->save_key( $key );

		return LicenseResult::success();
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
