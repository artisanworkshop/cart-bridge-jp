<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\License;

use CartBridgeJP\Pro\License\LicenseStore;
use WP_UnitTestCase;

/**
 * ライセンスキーと有効化の状態の保存（R3-8a）。キーは暗号化し、有効化の状態は平文で、キーを復号できなくても有効化は残る（D14）。
 */
final class LicenseStoreTest extends WP_UnitTestCase {

	private const KEY = '0123456789abcdef0123456789abcdef01234567';

	private LicenseStore $store;

	public function set_up(): void {
		parent::set_up();
		$this->store = new LicenseStore();
	}

	public function test_starts_without_an_activation(): void {
		$this->assertFalse( $this->store->is_activated() );
		$this->assertNull( $this->store->activated_at() );
		$this->assertNull( $this->store->key() );
		$this->assertFalse( $this->store->key_unreadable() );
		$this->assertSame( '', $this->store->key_suffix() );
		$this->assertNull( $this->store->last_check() );
	}

	public function test_saves_the_key_encrypted_and_the_state_in_plain_text(): void {
		$this->store->save_activation( self::KEY, 1000 );

		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
		$this->assertSame( '4567', $this->store->key_suffix() );
		$this->assertIsInt( $this->store->activated_at() );

		$stored = get_option( LicenseStore::KEY_OPTION );
		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( self::KEY, $stored );
		$this->assertStringNotContainsString( self::KEY, (string) base64_decode( $stored, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 暗号文に平文が無いことの確認。

		$this->assertSame( 'yes', get_option( LicenseStore::STATE_OPTION )['activated'] );
		$this->assertSame( 1000, get_option( LicenseStore::STATE_OPTION )['product_id'] );
	}

	public function test_does_not_autoload_its_options(): void {
		global $wpdb;

		$this->store->save_activation( self::KEY, 1000 );
		$this->store->record_check( true, null );
		$this->store->instance();

		foreach ( [ LicenseStore::KEY_OPTION, LicenseStore::STATE_OPTION, LicenseStore::INSTANCE_OPTION, LicenseStore::LAST_CHECK_OPTION ] as $name ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$this->assertIsString( $autoload, $name );
			$this->assertNotContains( $autoload, wp_autoload_values_to_autoload(), $name );
		}
	}

	/**
	 * 同じ値のまま autoload ありで作られた行（WP-CLI など）も、保存で autoload を外す（`update_option()` は値が同じだと autoload を変えない。
	 * CLAUDE.md・PR #120 G1-1）。無効化の後の状態は常に同じ値になる。
	 */
	public function test_turns_off_autoload_for_a_row_with_the_same_value(): void {
		global $wpdb;

		delete_option( LicenseStore::STATE_OPTION );
		add_option( LicenseStore::STATE_OPTION, [ 'activated' => 'no' ], '', true );

		$this->store->clear_activation();

		foreach ( [ LicenseStore::STATE_OPTION ] as $name ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$this->assertIsString( $autoload, $name );
			$this->assertNotContains( $autoload, wp_autoload_values_to_autoload(), $name );
		}
	}

	/**
	 * ソルトが変わる（サイトの移転など）とキーを復号できなくなる。有効化の状態は平文なので残る。
	 */
	public function test_keeps_the_activation_when_the_key_cannot_be_decrypted(): void {
		$this->store->save_activation( self::KEY, 1000 );

		add_filter( 'salt', static fn () => 'a-different-salt' );

		$this->assertNull( $this->store->key() );
		$this->assertTrue( $this->store->key_unreadable() );
		$this->assertSame( '', $this->store->key_suffix() );
		$this->assertTrue( $this->store->is_activated() );
	}

	/**
	 * @dataProvider broken_keys
	 */
	public function test_reads_a_broken_key_as_unreadable( mixed $stored ): void {
		update_option( LicenseStore::KEY_OPTION, $stored );

		$this->assertNull( $this->store->key() );
		$this->assertTrue( $this->store->key_unreadable() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function broken_keys(): array {
		return [
			'not base64'       => [ 'not base64!!' ],
			'too short'        => [ base64_encode( 'short' ) ], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 壊れた保存値の再現。
			'not a ciphertext' => [ base64_encode( str_repeat( 'a', 64 ) ) ], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 壊れた保存値の再現。
			'not a string'     => [ [ 'key' ] ],
		];
	}

	/**
	 * 真偽は `'yes'` だけを有効化とみなす（`(bool)` で読むと `'false'`・`'1'` などの壊れた値が有効化に化ける）。
	 *
	 * @dataProvider non_activated_states
	 */
	public function test_only_yes_means_activated( mixed $state ): void {
		update_option( LicenseStore::STATE_OPTION, $state );

		$this->assertFalse( $this->store->is_activated() );
		$this->assertNull( $this->store->activated_at() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function non_activated_states(): array {
		return [
			'no'           => [ [ 'activated' => 'no' ] ],
			'true'         => [ [ 'activated' => true ] ],
			'one'          => [ [ 'activated' => '1' ] ],
			'YES'          => [ [ 'activated' => 'YES' ] ],
			'not an array' => [ 'yes' ],
			'empty'        => [ [] ],
		];
	}

	public function test_creates_the_instance_once_and_keeps_it(): void {
		$instance = $this->store->instance();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}\z/', $instance );
		$this->assertSame( $instance, $this->store->instance() );
		$this->assertSame( $instance, ( new LicenseStore() )->instance() );

		$this->store->save_activation( self::KEY, 1000 );
		$this->store->clear_activation();

		$this->assertSame( $instance, $this->store->instance(), '無効化しても instance は残す（同じサイトでの有効化し直し）' );
	}

	/**
	 * パスワードの規則を足すプラグインは `random_password` で記号を混ぜる。instance をそれで作ると形が崩れ、呼ぶたびに作り直す（review-loop R1-3）。
	 */
	public function test_the_instance_does_not_depend_on_the_password_generator(): void {
		add_filter( 'random_password', static fn (): string => 'Short!@#' );

		$instance = $this->store->instance();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}\z/', $instance );
		$this->assertSame( $instance, $this->store->instance() );
	}

	/**
	 * 同時に届いた 2 つの有効化が別々の instance を作ったら、先に保存された方を両方が使う（PR #123 G1-1）。本当の並行は PHPUnit で作れないので、
	 * 作成の SQL の直前に相手の保存を差し込む（CLAUDE.md の競合のテストの方針）。
	 */
	public function test_a_concurrent_first_use_returns_the_stored_instance(): void {
		$competitor = str_repeat( 'c', 32 );
		$injected   = false;
		add_filter(
			'query',
			static function ( string $query ) use ( &$injected, $competitor ): string {
				global $wpdb;

				if ( ! $injected && str_contains( $query, 'INSERT IGNORE' ) && str_contains( $query, LicenseStore::INSTANCE_OPTION ) ) {
					$injected = true;
					$wpdb->insert(
						$wpdb->options,
						[
							'option_name'  => LicenseStore::INSTANCE_OPTION,
							'option_value' => $competitor,
							'autoload'     => 'off',
						]
					);
				}

				return $query;
			}
		);

		$this->assertSame( $competitor, $this->store->instance() );
		$this->assertTrue( $injected, '作成の SQL を通った' );
		$this->assertSame( $competitor, ( new LicenseStore() )->instance() );
	}

	/**
	 * 作成が DB に届かず読み直せないときは、保存していない instance を返さない（PR #123 G2-B1）。
	 */
	public function test_returns_no_instance_when_it_cannot_be_saved(): void {
		self::fail_instance_insert();

		$this->assertNull( $this->store->instance() );
		$this->assertNull( get_option( LicenseStore::INSTANCE_OPTION, null ) );
	}

	/**
	 * instance の作成の SQL を、何も書かない SQL に差し替える（DB の失敗の代わり）。
	 */
	public static function fail_instance_insert(): void {
		add_filter(
			'query',
			static function ( string $query ): string {
				return str_contains( $query, 'INSERT IGNORE' ) && str_contains( $query, LicenseStore::INSTANCE_OPTION ) ? 'SELECT 1' : $query;
			}
		);
	}

	/**
	 * 保存は DB から読み直して確かめる。キーを書けなければ状態を「有効化済み」にしない（キーの無い有効化を作らない。PR #123 G3-B1）。
	 */
	public function test_an_activation_is_not_saved_when_the_key_write_fails(): void {
		self::fail_option_writes( LicenseStore::KEY_OPTION );

		$this->assertFalse( $this->store->save_activation( self::KEY, 1000 ) );

		$this->assertFalse( $this->store->is_activated() );
		$this->assertNull( $this->store->key() );
	}

	public function test_an_activation_reports_a_failed_state_write(): void {
		self::fail_option_writes( LicenseStore::STATE_OPTION );

		$this->assertFalse( $this->store->save_activation( self::KEY, 1000 ) );

		$this->assertFalse( $this->store->is_activated() );
	}

	/**
	 * 無効化は状態を先に「未有効化」にして確かめ、書けなければキーを消さない（PR #123 G3-B1）。
	 */
	public function test_clearing_keeps_the_key_when_the_state_write_fails(): void {
		$this->store->save_activation( self::KEY, 1000 );
		self::fail_option_writes( LicenseStore::STATE_OPTION );

		$this->assertFalse( $this->store->clear_activation() );

		$this->assertTrue( $this->store->is_activated() );
		$this->assertSame( self::KEY, $this->store->key() );
	}

	public function test_saving_a_key_reports_a_failed_write(): void {
		self::fail_option_writes( LicenseStore::KEY_OPTION );

		$this->assertFalse( $this->store->save_key( self::KEY ) );
	}

	/**
	 * 有効化済みなのにキーが無い（保存の途中の失敗など）ときも、キーを読めないとして入れ直しを出す（PR #123 G3-B1）。
	 */
	public function test_an_activation_without_a_key_needs_the_key_again(): void {
		$this->store->save_activation( self::KEY, 1000 );
		delete_option( LicenseStore::KEY_OPTION );

		$this->assertTrue( $this->store->key_unreadable() );
	}

	/**
	 * その option への書込み（INSERT・UPDATE・DELETE）を、何も書かない SQL に差し替える（DB の失敗の代わり）。外すときのためにコールバックを返す。
	 */
	public static function fail_option_writes( string $name ): callable {
		$callback = static function ( string $query ) use ( $name ): string {
			return 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE)\b/i', $query ) && str_contains( $query, "'" . $name . "'" ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $callback );

		return $callback;
	}

	public function test_replaces_a_broken_instance(): void {
		update_option( LicenseStore::INSTANCE_OPTION, 'bad!' );

		$instance = $this->store->instance();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}\z/', $instance );
		$this->assertSame( $instance, get_option( LicenseStore::INSTANCE_OPTION ) );
	}

	public function test_clearing_removes_the_key_state_and_last_check(): void {
		$this->store->save_activation( self::KEY, 1000 );
		$this->store->record_check( true, 'January 9, 2027' );

		$this->store->clear_activation();

		$this->assertFalse( $this->store->is_activated() );
		$this->assertFalse( get_option( LicenseStore::KEY_OPTION ) );
		$this->assertFalse( $this->store->key_unreadable() );
		$this->assertNull( $this->store->last_check() );
	}

	public function test_records_the_last_check(): void {
		$this->store->record_check( false, 'Expired' );

		$check = $this->store->last_check();

		$this->assertIsArray( $check );
		$this->assertFalse( $check['activated'] );
		$this->assertSame( 'Expired', $check['expires'] );
		$this->assertIsInt( $check['checked_at'] );

		$this->store->record_check( true, null );
		$this->assertTrue( $this->store->last_check()['activated'] );
		$this->assertNull( $this->store->last_check()['expires'] );
	}

	public function test_activation_clears_the_previous_check(): void {
		$this->store->record_check( false, 'Expired' );

		$this->store->save_activation( self::KEY, 1000 );

		$this->assertNull( $this->store->last_check() );
	}

	/**
	 * @dataProvider broken_checks
	 */
	public function test_ignores_a_broken_last_check( mixed $check ): void {
		update_option( LicenseStore::LAST_CHECK_OPTION, $check );

		$this->assertNull( $this->store->last_check() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function broken_checks(): array {
		return [
			'not an array'      => [ 'yes' ],
			'no time'           => [ [ 'activated' => 'yes' ] ],
			'activated as bool' => [
				[
					'checked_at' => 1,
					'activated'  => true,
				],
			],
		];
	}
}
