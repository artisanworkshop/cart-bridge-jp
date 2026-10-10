<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Woo;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalReview;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\DryRunRepository;
use CartBridgeJP\Woo\Support\SideEffectGuard;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\WooRepository;
use CartBridgeJP\Woo\WooRepositoryFactory;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\ValidationResult;
use WC_Coupon;
use WC_Product_Simple;

/**
 * D25 のリポジトリのガードを顧客・受注・クーポンで（R3-6c1 で無料版の `WooRepositoryTest` から分けた）。判定は Pro の種類の
 * `is_linked_by_export()`。
 */
final class CommerceWooRepositoryTest extends WooTestCase {

	/**
	 * D25 用: 呼ばれたかどうかだけを記録する writer。
	 */
	private function spy_writer(): EntityWriter {
		return new class() implements EntityWriter {
			public int $write_calls    = 0;
			public int $validate_calls = 0;

			public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				++$this->write_calls;

				return new WriteResult( $existing_local_id ?? 1, WriteResult::OPERATION_UPDATED );
			}

			public function validate( CanonicalModel $item, ?int $existing_local_id ): ValidationResult {
				++$this->validate_calls;

				return new ValidationResult( WriteResult::OPERATION_UPDATED );
			}
		};
	}

	/**
	 * D25 用: エンティティごとの Woo の実体を作り、`$platform`があれば取込みの writer と同じく`_cbjp_platform`を書く。
	 */
	private function make_entity( string $entity, ?string $platform ): int {
		switch ( $entity ) {
			case 'product':
				$product = new WC_Product_Simple();
				$product->set_name( 'P' );
				$id = $product->save();

				if ( null !== $platform ) {
					update_post_meta( $id, '_cbjp_platform', $platform );
				}

				return $id;
			case 'coupon':
				$coupon = new WC_Coupon();
				$coupon->set_code( 'd25-coupon' );
				$id = $coupon->save();

				if ( null !== $platform ) {
					update_post_meta( $id, '_cbjp_platform', $platform );
				}

				return $id;
			case 'customer':
				$id = self::factory()->user->create( [ 'role' => 'customer' ] );

				if ( null !== $platform ) {
					update_user_meta( $id, '_cbjp_platform', $platform );
				}

				return $id;
			default:
				$order = wc_create_order();

				if ( null !== $platform ) {
					$order->update_meta_data( '_cbjp_platform', $platform );
				}

				return $order->save();
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function guarded_entity_provider(): array {
		return [
			'customer' => [ 'customer' ],
			'order'    => [ 'order' ],
			'coupon'   => [ 'coupon' ],
		];
	}

	/**
	 * D25（issue #98）: mapping が指す実体がエクスポートで結ばれている（取込みの印が無い）なら、実書込み・dry-run とも writer を
	 * 呼ばずにスキップし、local_id 0（`Importer`が mapping に触れない）で返す。
	 *
	 * @dataProvider guarded_entity_provider
	 */
	public function test_an_entity_linked_by_export_is_not_written_by_import( string $entity ): void {
		$local_id = $this->make_entity( $entity, null );
		$writer   = $this->spy_writer();
		$item     = new CanonicalCategory( '1', 'Cat', null, null );

		$result  = ( new WooRepository( new SideEffectGuard(), [ $entity => $writer ], 'colorme' ) )->write( $entity, $item, $local_id );
		$dry_run = ( new DryRunRepository( new SideEffectGuard(), [ $entity => $writer ], 'colorme' ) )->write( $entity, $item, $local_id );

		foreach ( [ $result, $dry_run ] as $outcome ) {
			$this->assertSame( 0, $outcome->local_id );
			$this->assertSame( WriteResult::OPERATION_SKIPPED, $outcome->operation );
			$this->assertSame( [ WarningCode::LINKED_BY_EXPORT_NOT_IMPORTED ], $outcome->warnings );
		}

		$this->assertSame( 0, $writer->write_calls );
		$this->assertSame( 0, $writer->validate_calls );
	}

	/**
	 * D25: 取込みで結ばれた実体（`_cbjp_platform`が一致）と、mapping の無い（新規の）書込みは従来どおり writer に渡す。
	 *
	 * @dataProvider guarded_entity_provider
	 */
	public function test_an_entity_linked_by_import_and_a_new_entity_are_still_written( string $entity ): void {
		$local_id = $this->make_entity( $entity, 'colorme' );
		$writer   = $this->spy_writer();
		$item     = new CanonicalCategory( '1', 'Cat', null, null );

		$result = ( new WooRepository( new SideEffectGuard(), [ $entity => $writer ], 'colorme' ) )->write( $entity, $item, $local_id );
		( new WooRepository( new SideEffectGuard(), [ $entity => $writer ], 'colorme' ) )->write( $entity, $item, null );
		( new DryRunRepository( new SideEffectGuard(), [ $entity => $writer ], 'colorme' ) )->write( $entity, $item, $local_id );

		$this->assertSame( $local_id, $result->local_id );
		$this->assertSame( 2, $writer->write_calls );
		$this->assertSame( 1, $writer->validate_calls );
	}

	/**
	 * D25: 実体が無い（stale）mapping は止めず、各 writer の作り直し（stale-ID のフォールバック）に任せる。商品は posts の行だけが
	 * 消えてメタが残る場合も含む（`wc_get_product_object()`が例外を投げる条件と揃える）。
	 *
	 * @dataProvider guarded_entity_provider
	 */
	public function test_a_stale_mapping_is_left_to_the_writer( string $entity ): void {
		global $wpdb;

		$local_id = $this->make_entity( $entity, null );

		if ( 'customer' === $entity ) {
			$wpdb->delete( $wpdb->users, [ 'ID' => $local_id ] );
			clean_user_cache( $local_id );
		} elseif ( 'order' === $entity ) {
			wc_get_order( $local_id )->delete( true );
		} else {
			$wpdb->delete( $wpdb->posts, [ 'ID' => $local_id ] );
			clean_post_cache( $local_id );
		}

		$writer = $this->spy_writer();
		( new WooRepository( new SideEffectGuard(), [ $entity => $writer ], 'colorme' ) )->write( $entity, new CanonicalCategory( '1', 'Cat', null, null ), $local_id );

		$this->assertSame( 1, $writer->write_calls );
	}

	/**
	 * D25: 保護ロールの顧客は取込みが印を書かない（`CustomerWriter`が触らない）ので、出自を判定せず writer に任せる
	 * （`CUSTOMER_ACCOUNT_PROTECTED`のまま）。
	 */
	public function test_a_protected_role_customer_is_left_to_the_writer(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$writer   = $this->spy_writer();

		( new WooRepository( new SideEffectGuard(), [ 'customer' => $writer ], 'colorme' ) )->write( 'customer', new CanonicalCategory( '1', 'Cat', null, null ), $admin_id );

		$this->assertSame( 1, $writer->write_calls );
	}

	/**
	 * D25: このプラットフォームの取込みで作った顧客（作成の印）は、別プラットフォームがメールで採用し直して`_cbjp_platform`が
	 * 書き換わっても、取込みで結ばれた顧客として更新を続ける。
	 */
	public function test_a_customer_created_by_this_platform_import_is_still_written_after_another_platform_adopted_it(): void {
		$customer_id = $this->make_entity( 'customer', 'makeshop' );
		update_user_meta( $customer_id, '_cbjp_created_by_import', 'colorme' );
		$writer = $this->spy_writer();

		( new WooRepository( new SideEffectGuard(), [ 'customer' => $writer ], 'colorme' ) )->write( 'customer', new CanonicalCategory( '1', 'Cat', null, null ), $customer_id );

		$this->assertSame( 1, $writer->write_calls );
	}
}
