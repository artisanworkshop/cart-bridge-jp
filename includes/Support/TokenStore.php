<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

/**
 * プラットフォームのAPI資格情報を暗号化して保存する。
 *
 * - 暗号化: `sodium_crypto_secretbox`（鍵は AUTH_KEY/AUTH_SALT から導出）
 * - 保存単位は構造化ペイロード `{access_token, refresh_token?, expires_at?, extras?, settings?}`（D13）。
 *   無期限トークン（カラーミー/MakeShop）も同構造に格納する。`settings`はOAuth接続前のclient_id/secret等、
 *   `extras`はOAuth完了後にアダプタが保存する付随情報（例: shop.jsonのcontract_plan）を想定した区分
 * - `access_token`が空文字のpayload（`save_settings()`経由）は「設定済みだが未接続」を表す
 * - AUTH_KEY変更等による復号失敗、リフレッシュトークン失効時は例外にせず「再接続が必要」を意味する null を返す
 */
final class TokenStore {

	private const LOCK_TTL_SECONDS = 30;

	/**
	 * 復号済みペイロードのインスタンス内キャッシュ。false = 未読込。
	 * get()/needs_reconnect()/masked_access_token() の同一リクエスト内での
	 * 復号（sodium + json_decode）重複を避ける。
	 *
	 * @var array<string,mixed>|null|false
	 */
	private array|null|false $payload_cache = false;

	public function __construct( private readonly string $platform ) {}

	/**
	 * @param array{access_token:string,refresh_token?:string,expires_at?:int,extras?:array<string,mixed>,settings?:array<string,mixed>} $payload
	 */
	public function save( array $payload ): void {
		if ( '' === $payload['access_token'] ) {
			throw new \InvalidArgumentException( 'access_token is required.' );
		}

		$this->persist( $payload );
	}

	/**
	 * OAuth前段階の接続設定（例: client_id/client_secret）を保存する。まだアクセストークンが
	 * 存在しない接続でも呼べるよう、既存payloadの`settings`キーにのみマージする
	 * （`access_token`が空のpayloadを許容する点が {@see self::save()} と異なる）。
	 *
	 * @param array<string,mixed> $settings
	 */
	public function save_settings( array $settings ): void {
		$payload             = $this->get() ?? [ 'access_token' => '' ];
		$payload['settings'] = array_merge( $payload['settings'] ?? [], $settings );

		$this->persist( $payload );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		$payload = $this->get();

		return $payload['settings'] ?? [];
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function persist( array $payload ): void {
		update_option( $this->option_name(), $this->encrypt( (string) wp_json_encode( $payload ) ), false );
		$this->payload_cache = $payload;
	}

	/**
	 * @return array{access_token:string,refresh_token?:string,expires_at?:int,extras?:array<string,mixed>,settings?:array<string,mixed>}|null
	 *         復号失敗・未接続の場合は null（呼び出し側は「再接続が必要」として扱う）。
	 */
	public function get(): ?array {
		if ( false !== $this->payload_cache ) {
			return $this->payload_cache;
		}

		$this->payload_cache = $this->load_payload();

		return $this->payload_cache;
	}

	/**
	 * 保存中のaccess_tokenが$expected_tokenと一致する場合のみextrasを更新する（CAS）。
	 *
	 * 「読み取り→比較→書き込み」の系列を、別リクエストの保存（OAuth再認可等）と
	 * 競合しても安全にするため、{@see self::compare_and_swap()} で原子的に書き込む。
	 *
	 * @param callable(array<string,mixed>):array<string,mixed> $update 現在のextrasを受け取り新しいextrasを返す。
	 * @return bool 書き込んだ場合true。トークン不一致・並行更新・未保存・復号失敗はfalse。
	 */
	public function update_extras_if_token_matches( string $expected_token, callable $update ): bool {
		return $this->compare_and_swap(
			static function ( array $payload ) use ( $expected_token, $update ): ?array {
				if ( ( $payload['access_token'] ?? null ) !== $expected_token ) {
					return null;
				}

				$extras            = is_array( $payload['extras'] ?? null ) ? $payload['extras'] : [];
				$payload['extras'] = $update( $extras );

				if ( [] === $payload['extras'] ) {
					unset( $payload['extras'] );
				}

				return $payload;
			}
		);
	}

	/**
	 * 保存中のsettings（client_id/client_secret）がトークン取得に使った資格情報と
	 * 一致する場合のみ、access_tokenを保存する（CAS）。
	 *
	 * OAuthのトークン交換はHTTP往復を挟むため、その間に管理者が資格情報を削除・
	 * 変更している可能性がある。交換開始時のスナップショットを書き戻すと削除済み
	 * 資格情報の復活や新しい設定の上書きが起きるため、原子的CASで書き込み、
	 * 発行済みトークンの紐づく資格情報が現存しない場合は保存しない。
	 * 保存時はextras（前のショップの契約プラン等）を破棄する。
	 *
	 * @return bool 書き込んだ場合true。資格情報の不一致・削除済み・並行更新はfalse。
	 */
	public function save_token_if_credentials_match( string $client_id, string $client_secret, string $access_token ): bool {
		return $this->compare_and_swap(
			static function ( array $payload ) use ( $client_id, $client_secret, $access_token ): ?array {
				$settings = is_array( $payload['settings'] ?? null ) ? $payload['settings'] : [];

				if ( ( $settings['client_id'] ?? null ) !== $client_id || ( $settings['client_secret'] ?? null ) !== $client_secret ) {
					return null;
				}

				$payload['access_token'] = $access_token;
				unset( $payload['extras'] );

				return $payload;
			}
		);
	}

	/**
	 * 現在のpayloadを読み、$mutateの返すpayloadへ原子的に置き換える（CAS）。
	 *
	 * キャッシュを介さずDBから直接読み、読み取った暗号化blob自体を比較値とした
	 * UPDATEで書き込む。blobは保存ごとにnonceが変わり一意なため、読み取り後に
	 * 他の保存が割り込めば必ず不一致になり、0行更新（＝何もしない）で終わる。
	 *
	 * @param callable(array<string,mixed>):(array<string,mixed>|null) $mutate 現在のpayloadを受け取り
	 *        新しいpayloadを返す。nullを返すと保存せず中止する。
	 * @return bool 書き込んだ場合true。未保存・復号失敗・$mutateの中止・並行更新はfalse。
	 */
	private function compare_and_swap( callable $mutate ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CASの比較値となる現在のblobをキャッシュを介さず読む。
		$stored = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->option_name() )
		);

		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		$plaintext = $this->decrypt( $stored );
		$payload   = null === $plaintext ? null : json_decode( $plaintext, true );

		if ( ! is_array( $payload ) ) {
			return false;
		}

		$new_payload = $mutate( $payload );

		if ( null === $new_payload ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 読み取ったblobとの一致を条件とする原子的なCAS書き込み。
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$this->encrypt( (string) wp_json_encode( $new_payload ) ),
				$this->option_name(),
				$stored
			)
		);

		if ( ! is_int( $updated ) || $updated < 1 ) {
			return false;
		}

		// オプションAPIを迂回して直接書き込んだため、オプションキャッシュを破棄して整合させる。
		wp_cache_delete( $this->option_name(), 'options' );
		$this->payload_cache = $new_payload;

		return true;
	}

	/**
	 * @return array{access_token:string,refresh_token?:string,expires_at?:int,extras?:array<string,mixed>,settings?:array<string,mixed>}|null
	 */
	private function load_payload(): ?array {
		$stored = get_option( $this->option_name() );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$plaintext = $this->decrypt( $stored );

		if ( null === $plaintext ) {
			return null;
		}

		$decoded = json_decode( $plaintext, true );

		return ( is_array( $decoded ) && isset( $decoded['access_token'] ) ) ? $decoded : null;
	}

	/**
	 * トークンは保存済みだが復号できない（AUTH_KEY変更等）場合に true。
	 */
	public function needs_reconnect(): bool {
		$stored = get_option( $this->option_name() );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return null === $this->get();
	}

	/**
	 * アクセストークンを保有していれば true。`save_settings()` のみが呼ばれた
	 * （client_id/secretは保存済みだがOAuth未完了の）状態は接続済みとみなさない。
	 */
	public function is_connected(): bool {
		$payload = $this->get();

		return null !== $payload && '' !== $payload['access_token'];
	}

	public function is_expired(): bool {
		$payload = $this->get();

		if ( null === $payload || ! isset( $payload['expires_at'] ) ) {
			return false;
		}

		return time() >= (int) $payload['expires_at'];
	}

	public function delete(): void {
		delete_option( $this->option_name() );
		delete_option( $this->lock_option_name() );
		$this->payload_cache = null;
	}

	/**
	 * 画面表示用に末尾4文字のみを返す（例: `****abcd`）。
	 */
	public function masked_access_token(): ?string {
		$payload = $this->get();
		$token   = $payload['access_token'] ?? '';

		if ( '' === $token ) {
			return null;
		}

		return str_repeat( '*', 4 ) . substr( $token, -4 );
	}

	/**
	 * リフレッシュの排他ロックを取得する。ローテーション式refresh_token（BASE等）の
	 * 二重更新による失効を防ぐ。取得できた場合は必ず release_refresh_lock() を呼ぶこと。
	 */
	public function acquire_refresh_lock( int $ttl_seconds = self::LOCK_TTL_SECONDS ): bool {
		global $wpdb;

		$lock_option = $this->lock_option_name();
		$now         = time();
		$expires_at  = (string) ( $now + $ttl_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 排他ロックのため直接クエリで原子的に作成する。
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$lock_option,
				$expires_at
			)
		);

		if ( $inserted > 0 ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 期限切れロックの奪取判定のため直接読む。
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock_option )
		);

		if ( null === $current || (int) $current >= $now ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CASによる期限切れロックの原子的奪取。
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$expires_at,
				$lock_option,
				$current
			)
		);

		return $updated > 0;
	}

	public function release_refresh_lock(): void {
		delete_option( $this->lock_option_name() );
	}

	private function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->encryption_key() );

		return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- バイナリの保存用エンコードであり難読化目的ではない。
	}

	private function decrypt( string $stored ): ?string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 上記encrypt()の対。

		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->encryption_key() );

		return false === $plaintext ? null : $plaintext;
	}

	private function encryption_key(): string {
		return sodium_crypto_generichash( AUTH_KEY . AUTH_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private function option_name(): string {
		return 'cbjp_token_' . $this->platform;
	}

	private function lock_option_name(): string {
		return 'cbjp_token_lock_' . $this->platform;
	}
}
