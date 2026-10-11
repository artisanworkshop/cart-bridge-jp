<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

/**
 * ライセンスキーと有効化の状態の保存（R3-8a。2026-10-11 ユーザー決定: キーは暗号化し、有効化の状態は平文）。
 *
 * - キー（`cbjp_pro_license_key`）: `sodium_crypto_secretbox` で暗号化する。鍵は `wp_salt( 'auth' )`（AUTH_KEY・AUTH_SALT）に Pro 固有の文脈を足して導く
 *   （無料版の `TokenStore` と同じ方式。`TokenStore` は Pro が使ってよい無料版の API に無いので、Pro に小さく持つ）。
 * - 有効化の状態（`cbjp_pro_license_state`）: 平文。ソルトが変わってキーを復号できなくなっても、有効化は残る。API Manager は期限後に
 *   そのサイトの有効化を消し、新しい有効化も受け付けなくなるので、ローカルの記録を失うと期限後は有効化し直せない（D14。`LicenseClient`）。
 *   真偽は別のリクエストで `'1'`/`''` に化けないよう `'yes'`/`'no'` で保存し `'yes' ===` で読む（CLAUDE.md）。
 * - instance（`cbjp_pro_license_instance`）: API Manager がサイトの有効化を見分ける ID（サーバー全体で一意）。初めて使うときに 1 回だけ（16 進数 32 文字で）作り、
 *   無効化しても残す（同じサイトでの有効化し直し・キーの交換は同じ instance で行う）。
 * - 最後の状態確認（`cbjp_pro_license_last_check`）: 表示用。
 *
 * どれも autoload しない（管理画面のライセンスの画面と、R3-8b の判定だけが読む）。`update_option()` は値が同じだと autoload を変えないので、
 * 保存のたびに `wp_set_option_autoload()` も呼ぶ（CLAUDE.md。PR #120 G1-1）。
 */
final class LicenseStore {

	public const KEY_OPTION        = 'cbjp_pro_license_key';
	public const STATE_OPTION      = 'cbjp_pro_license_state';
	public const INSTANCE_OPTION   = 'cbjp_pro_license_instance';
	public const LAST_CHECK_OPTION = 'cbjp_pro_license_last_check';

	/** 鍵の導出に足す文脈（無料版の `TokenStore` の鍵と別にする）。 */
	private const KEY_CONTEXT = 'cbjp_pro_license_key|';

	/** instance の形（API Manager の列は VARCHAR(190)・UNIQUE）。 */
	private const INSTANCE_PATTERN = '/^[A-Za-z0-9]{16,190}\z/';

	/** instance の乱数のバイト数（16 進数で 32 文字）。 */
	private const INSTANCE_BYTES = 16;

	public function is_activated(): bool {
		return 'yes' === ( $this->state()['activated'] ?? null );
	}

	/**
	 * 有効化した日時（Unix 時刻）。有効化していない・読めないなら null。
	 */
	public function activated_at(): ?int {
		if ( ! $this->is_activated() ) {
			return null;
		}

		$time = $this->state()['activated_at'] ?? null;

		return is_int( $time ) && $time > 0 ? $time : null;
	}

	/**
	 * 復号したキー。保存していない・復号できないなら null。
	 */
	public function key(): ?string {
		$stored = get_option( self::KEY_OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		return $this->decrypt( $stored );
	}

	/**
	 * キーは保存してあるが復号できない（AUTH_KEY・AUTH_SALT が変わった、値が壊れた）。
	 */
	public function key_unreadable(): bool {
		$stored = get_option( self::KEY_OPTION, null );

		if ( null === $stored || '' === $stored ) {
			return false;
		}

		return null === $this->key();
	}

	/**
	 * 画面に出すキーの末尾 4 文字（キー全体は出さない）。読めなければ ''。
	 */
	public function key_suffix(): string {
		$key = $this->key();

		return null === $key ? '' : substr( $key, -4 );
	}

	/**
	 * このサイトの instance。無い・壊れていれば作って保存する。
	 */
	public function instance(): string {
		$stored = get_option( self::INSTANCE_OPTION, null );

		if ( self::is_instance( $stored ) ) {
			return $stored;
		}

		// `wp_generate_password()` は pluggable で `random_password` フィルターも掛かる（パスワードの規則を足すプラグインが記号を混ぜる）。
		// 形が崩れると呼ぶたびに作り直し、有効化と無効化・状態確認で違う instance を送るので、差し替えられない乱数で作る（review-loop R1-3）。
		$candidate = bin2hex( random_bytes( self::INSTANCE_BYTES ) );

		if ( null !== $stored ) {
			// 壊れた値の置き換え（同時に起きる見込みは無いので、そのまま上書きする）。
			$this->save_option( self::INSTANCE_OPTION, $candidate );

			return $candidate;
		}

		// 初めての作成は、同時に届いた 2 つの有効化が別々の値を作って片方が上書きされ、サーバーで有効化した instance と保存した instance が
		// ずれないよう、`INSERT IGNORE` で原子的に作り、勝った値を DB から読み直す（PR #123 G1-1）。`add_option()` は
		// `ON DUPLICATE KEY UPDATE` で既存の行を上書きするので、この用途には使えない。
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 作成の競合を DB の一意制約で決める。直後にキャッシュを捨てる。
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				self::INSTANCE_OPTION,
				$candidate
			)
		);
		self::forget_cached_option( self::INSTANCE_OPTION );

		$winner = get_option( self::INSTANCE_OPTION, null );

		return self::is_instance( $winner ) ? $winner : $candidate;
	}

	/**
	 * @phpstan-assert-if-true string $value
	 */
	private static function is_instance( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::INSTANCE_PATTERN, $value );
	}

	/**
	 * 直接書いた option を次の `get_option()` が DB から読むよう、このリクエストのキャッシュ（値と「存在しない」の記録）を捨てる。
	 */
	private static function forget_cached_option( string $name ): void {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * 有効化に成功したキーと状態を保存する（キーの交換でも使う。前の状態確認の結果は消す）。
	 */
	public function save_activation( string $key, int $product_id ): void {
		$this->save_key( $key );
		$this->save_option(
			self::STATE_OPTION,
			[
				'activated'    => 'yes',
				'activated_at' => time(),
				'product_id'   => $product_id,
			]
		);
		delete_option( self::LAST_CHECK_OPTION );
	}

	public function save_key( string $key ): void {
		$this->save_option( self::KEY_OPTION, $this->encrypt( $key ) );
	}

	/**
	 * 無効化に成功したら、キー・状態・状態確認の結果を消す（instance は残す）。
	 */
	public function clear_activation(): void {
		delete_option( self::KEY_OPTION );
		delete_option( self::LAST_CHECK_OPTION );
		$this->save_option( self::STATE_OPTION, [ 'activated' => 'no' ] );
	}

	/**
	 * 状態確認の結果を表示用に記録する（有効化の状態は変えない）。
	 */
	public function record_check( bool $server_activated, ?string $expires ): void {
		$this->save_option(
			self::LAST_CHECK_OPTION,
			[
				'checked_at' => time(),
				'activated'  => $server_activated ? 'yes' : 'no',
				'expires'    => $expires ?? '',
			]
		);
	}

	/**
	 * 最後の状態確認。無い・壊れていれば null。
	 *
	 * @return array{checked_at:int,activated:bool,expires:?string}|null
	 */
	public function last_check(): ?array {
		$check = get_option( self::LAST_CHECK_OPTION, null );

		if ( ! is_array( $check ) || ! is_int( $check['checked_at'] ?? null ) || ! in_array( $check['activated'] ?? null, [ 'yes', 'no' ], true ) ) {
			return null;
		}

		$expires = $check['expires'] ?? '';

		return [
			'checked_at' => $check['checked_at'],
			'activated'  => 'yes' === $check['activated'],
			'expires'    => is_string( $expires ) && '' !== $expires ? $expires : null,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	private function save_option( string $name, mixed $value ): void {
		update_option( $name, $value, false );
		wp_set_option_autoload( $name, false );
	}

	private function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::encryption_key() );

		return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- バイナリの保存用エンコードであり難読化目的ではない。
	}

	private function decrypt( string $stored ): ?string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypt() の対。

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::encryption_key() );
		} catch ( \SodiumException ) {
			return null;
		}

		return is_string( $plaintext ) && '' !== $plaintext ? $plaintext : null;
	}

	private static function encryption_key(): string {
		return sodium_crypto_generichash( self::KEY_CONTEXT . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
