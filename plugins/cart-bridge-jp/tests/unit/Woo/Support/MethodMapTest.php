<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\MethodMap;
use WP_UnitTestCase;

final class MethodMapTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'cbjp_settings_colorme' );

		parent::tearDown();
	}

	/**
	 * `payment_map`（ASP側ID=>Woo側ID）の値がWoo側IDにちょうど1件対応する場合のみ、
	 * その ASP側IDを逆引きできる（D19）。
	 */
	public function test_asp_payment_id_resolves_a_unique_match(): void {
		update_option(
			'cbjp_settings_colorme',
			[
				'payment_map' => [
					'751'  => 'bacs',
					'1032' => 'cod',
				],
			]
		);

		$method_map = new MethodMap( 'colorme' );

		$this->assertSame( '751', $method_map->asp_payment_id( 'bacs' ) );
		$this->assertSame( '1032', $method_map->asp_payment_id( 'cod' ) );
	}

	/**
	 * Woo側IDにマッピングされたASP側IDが1件も無い場合はnull（未マッピング）。
	 */
	public function test_asp_payment_id_returns_null_when_unmapped(): void {
		update_option( 'cbjp_settings_colorme', [ 'payment_map' => [ '751' => 'bacs' ] ] );

		$this->assertNull( ( new MethodMap( 'colorme' ) )->asp_payment_id( 'stripe' ) );
	}

	/**
	 * `payment_map`はASP側ID=>Woo側IDで単射とは限らない（複数のASP決済方法が同じWooゲートウェイへ
	 * 寄せられうる）。Woo側IDに対応するASP側IDが2件以上あると、どちらが実際にこの受注で使われた
	 * 決済方法か機械的に判定できないため、未マッピングと同じくnullへフェイルクローズする
	 * （D19の申し送りが提案する「複数一致時はフェイルクローズする」を採用）。
	 */
	public function test_asp_payment_id_returns_null_when_ambiguous(): void {
		update_option(
			'cbjp_settings_colorme',
			[
				'payment_map' => [
					'751' => 'stripe',
					'900' => 'stripe',
				],
			]
		);

		$this->assertNull( ( new MethodMap( 'colorme' ) )->asp_payment_id( 'stripe' ) );
	}

	public function test_asp_payment_id_returns_null_when_no_settings_saved(): void {
		$this->assertNull( ( new MethodMap( 'colorme' ) )->asp_payment_id( 'bacs' ) );
	}

	/**
	 * `shipping_map`の逆引きも`payment_map`と同じ規則（一意一致のみ解決、0件・複数一致はnull）。
	 */
	public function test_asp_delivery_id_resolves_a_unique_match(): void {
		update_option( 'cbjp_settings_colorme', [ 'shipping_map' => [ '640580' => 'flat_rate:6' ] ] );

		$this->assertSame( '640580', ( new MethodMap( 'colorme' ) )->asp_delivery_id( 'flat_rate:6' ) );
	}

	public function test_asp_delivery_id_returns_null_when_ambiguous(): void {
		update_option(
			'cbjp_settings_colorme',
			[
				'shipping_map' => [
					'640580' => 'flat_rate:6',
					'640581' => 'flat_rate:6',
				],
			]
		);

		$this->assertNull( ( new MethodMap( 'colorme' ) )->asp_delivery_id( 'flat_rate:6' ) );
	}

	public function test_asp_delivery_id_returns_null_when_unmapped(): void {
		update_option( 'cbjp_settings_colorme', [ 'shipping_map' => [ '640580' => 'flat_rate:6' ] ] );

		$this->assertNull( ( new MethodMap( 'colorme' ) )->asp_delivery_id( 'flat_rate:7' ) );
	}
}
