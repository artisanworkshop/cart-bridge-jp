<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Adapters\CommerceAdapters;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Reader\CouponReader;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Support\EntityOrigin;
use CartBridgeJP\Woo\Tools\Link\PostLinkSource;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Writer\CouponWriter;
use CartBridgeJP\Woo\Writer\EntityWriter;
use RuntimeException;
use WC_Coupon;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * クーポン（`shop_coupon`）。取込み・エクスポートの両方（ColorMe はクーポンを作れないのでエクスポートは対象外）。
 * リモートの ID 指定取得が無いので、push intent の「リンクして解除」はできない（既定の `fetch_by_remote_id()`）。
 *
 * **R3-6c で Pro アドオンへ移す**（D27。`Entities/Commerce/` ごと）。それまでは無料版が `cbjp/entity_types/register` から登録する。
 */
final class CouponType extends EntityType {

	public function key(): string {
		return 'coupon';
	}

	public function label(): string {
		return __( 'Coupons', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 70;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return CommerceAdapters::get( $adapter )?->capabilities()->has_coupons ?? false;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return CommerceAdapters::get_required( $adapter, 'fetch_coupons' )->fetch_coupons( $cursor );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new CouponWriter( $platform );
	}

	public function supports_export( PlatformAdapter $adapter ): bool {
		$capabilities = CommerceAdapters::get( $adapter )?->capabilities();

		return null !== $capabilities && $capabilities->has_coupons && $capabilities->can_create_coupon;
	}

	public function reader( string $platform, WooServices $services ): EntityReader {
		return new CouponReader( $platform );
	}

	public function push( PlatformAdapter $adapter, CanonicalModel $item, ?string $remote_id ): PushResult {
		if ( ! $item instanceof CanonicalCoupon ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "coupon".' );
		}

		return CommerceAdapters::get_required( $adapter, 'push_coupon' )->push_coupon( $item, $remote_id );
	}

	public function records_push_intent(): bool {
		return true;
	}

	public function describe_local( int $local_id ): array {
		if ( 'shop_coupon' !== get_post_type( $local_id ) ) {
			return parent::describe_local( $local_id );
		}

		$coupon = new WC_Coupon( $local_id );

		return [
			'exists'   => true,
			'edit_url' => get_edit_post_link( $local_id, 'raw' ),
			'summary'  => $coupon->get_code(),
			'details'  => [
				'code' => $coupon->get_code(),
			],
		];
	}

	public function is_linked_by_export( string $platform, int $local_id ): bool {
		return 'shop_coupon' === get_post_type( $local_id ) && ! EntityOrigin::post_linked_by_import( $local_id, $platform );
	}

	public function link_sources(): array {
		return [ new PostLinkSource( 'coupon', 50, $this->label(), 'shop_coupon' ) ];
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_posts( [ 'shop_coupon' ], $local_ids );
	}

	public function dry_run_label( CanonicalModel $item ): string {
		return $item instanceof CanonicalCoupon ? $item->code : '';
	}

	public function warning_flags(): array {
		return CouponWarnings::flags();
	}

	public function describe_warning( string $code, bool $import, string $row_entity ): ?WarningText {
		return CouponWarnings::describe( $code, $import, $row_entity );
	}
}
