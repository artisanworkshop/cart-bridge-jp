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
 * - 保存単位は構造化ペイロード `{access_token, refresh_token?, expires_at?, extras?}`（D13）。
 *   無期限トークン（カラーミー/MakeShop）も同構造に格納する
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
	 * @param array{access_token:string,refresh_token?:string,expires_at?:int,extras?:array<string,mixed>} $payload
	 */
	public function save( array $payload ): void {
		if ( '' === $payload['access_token'] ) {
			throw new \InvalidArgumentException( 'access_token is required.' );
		}

		update_option( $this->option_name(), $this->encrypt( (string) wp_json_encode( $payload ) ), false );
		$this->payload_cache = $payload;
	}

	/**
	 * @return array{access_token:string,refresh_token?:string,expires_at?:int,extras?:array<string,mixed>}|null
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
	 * @return array{access_token:string,refresh_token?:string,expires_at?:int,extras?:array<string,mixed>}|null
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

	public function is_connected(): bool {
		$stored = get_option( $this->option_name() );

		return is_string( $stored ) && '' !== $stored;
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
