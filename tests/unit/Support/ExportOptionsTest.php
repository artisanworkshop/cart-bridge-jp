<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Support;

use CartBridgeJP\Support\ExportOptions;
use stdClass;
use WP_UnitTestCase;

final class ExportOptionsTest extends WP_UnitTestCase {

	private function platform(): string {
		return 'test-' . wp_generate_uuid4();
	}

	public function test_push_images_is_off_by_default(): void {
		$this->assertFalse( ExportOptions::push_images_enabled( $this->platform() ) );
	}

	public function test_save_and_read_round_trip_per_platform(): void {
		$a = $this->platform();
		$b = $this->platform();

		ExportOptions::save_push_images( $a, true );

		$this->assertTrue( ExportOptions::push_images_enabled( $a ) );
		$this->assertFalse( ExportOptions::push_images_enabled( $b ), '別プラットフォームの設定は独立している' );

		ExportOptions::save_push_images( $a, false );

		$this->assertFalse( ExportOptions::push_images_enabled( $a ) );
	}

	public function test_saved_option_is_not_autoloaded(): void {
		$platform = $this->platform();
		ExportOptions::save_push_images( $platform, true );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", ExportOptions::option_name( $platform ) ) );

		$this->assertContains( $autoload, [ 'off', 'no' ], 'アップデート後も全リクエストで読み込まれるオプションにしない' );
	}

	/**
	 * 外部への書込み（画像アップロード）をオンにする方向の判定なので、真偽値の `true` 以外は全てオフ。
	 * `(bool)` キャストだと `'false'`・`1`・非空配列が true になり、明示的に選んでいない店舗で画像が送られる。
	 *
	 * @dataProvider provider_values_that_are_not_the_boolean_true
	 *
	 * @param mixed $stored オプション全体として保存する値。
	 */
	public function test_anything_but_the_boolean_true_reads_as_off( mixed $stored ): void {
		$platform = $this->platform();
		update_option( ExportOptions::option_name( $platform ), $stored, false );

		$this->assertFalse( ExportOptions::push_images_enabled( $platform ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function provider_values_that_are_not_the_boolean_true(): array {
		return [
			'string "true"'       => [ [ 'push_images' => 'true' ] ],
			'string "1"'          => [ [ 'push_images' => '1' ] ],
			'int 1'               => [ [ 'push_images' => 1 ] ],
			'non-empty array'     => [ [ 'push_images' => [ 'x' ] ] ],
			'null'                => [ [ 'push_images' => null ] ],
			'missing key'         => [ [ 'other' => true ] ],
			'option is a string'  => [ 'true' ],
			'option is true'      => [ true ],
			'option is an object' => [ new stdClass() ],
			'option is a list'    => [ [ true ] ],
		];
	}

	public function test_save_preserves_other_keys(): void {
		$platform = $this->platform();
		update_option( ExportOptions::option_name( $platform ), [ 'future_option' => 'keep-me' ], false );

		ExportOptions::save_push_images( $platform, true );

		$stored = get_option( ExportOptions::option_name( $platform ) );

		$this->assertSame( 'keep-me', $stored['future_option'] );
		$this->assertTrue( $stored['push_images'] );
	}

	public function test_save_recovers_from_a_corrupt_stored_value(): void {
		$platform = $this->platform();
		update_option( ExportOptions::option_name( $platform ), new stdClass(), false );

		ExportOptions::save_push_images( $platform, true );

		$this->assertTrue( ExportOptions::push_images_enabled( $platform ) );
	}
}
