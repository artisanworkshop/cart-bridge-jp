<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Support\Money;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Reader\OrderReader;
use CartBridgeJP\Woo\Support\EntityOrigin;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\OrderItemBuilder;
use CartBridgeJP\Woo\Writer\OrderWriter;
use RuntimeException;
use WC_Order;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * 受注。取込み・エクスポート（D24 のベータ）の両方。移行後検証レポートの金額の突合と、決済・配送・注文ステータスのマッピングを持つ。
 *
 * **R3-6c で Pro アドオンへ移す**（D27。`Entities/Commerce/` ごと）。それまでは無料版が `cbjp/entity_types/register` から登録する。
 */
final class OrderType extends EntityType {

	public function key(): string {
		return 'order';
	}

	public function label(): string {
		return __( 'Orders', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 50;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return $adapter->fetch_orders( $cursor );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new OrderWriter( $platform, $services->mappings(), new OrderItemBuilder( $services->product_resolver() ), $services->method_map() );
	}

	public function supports_export( PlatformAdapter $adapter ): bool {
		return $adapter->capabilities()->can_create_order;
	}

	/**
	 * D24: 受注のエクスポートは接続先に受注（売上）を作るため、アダプタがベータと宣言していれば既定で選ばない。
	 */
	public function is_export_beta( PlatformAdapter $adapter ): bool {
		return in_array( Capabilities::BETA_ORDER_EXPORT, (array) $adapter->capabilities()->to_array()['beta_features'], true );
	}

	public function reader( string $platform, WooServices $services ): EntityReader {
		return new OrderReader( $platform, $services->mappings() );
	}

	public function push( PlatformAdapter $adapter, CanonicalModel $item, ?string $remote_id ): PushResult {
		if ( ! $item instanceof CanonicalOrder ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "order".' );
		}

		return $adapter->push_order( $item, $remote_id );
	}

	public function records_push_intent(): bool {
		return true;
	}

	public function fetch_by_remote_id( PlatformAdapter $adapter, string $remote_id ): ?CanonicalModel {
		return $adapter->fetch_order_by_remote_id( $remote_id );
	}

	public function describe_local( int $local_id ): array {
		$order = wc_get_order( $local_id );

		if ( ! $order instanceof WC_Order || 'trash' === $order->get_status() ) {
			return parent::describe_local( $local_id );
		}

		$date_created = $order->get_date_created();

		return [
			'exists'   => true,
			'edit_url' => $order->get_edit_order_url(),
			'details'  => [
				'number'       => $order->get_order_number(),
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
				'date_created' => null !== $date_created ? $date_created->date( DATE_ATOM ) : null,
			],
		];
	}

	public function is_linked_by_export( string $platform, int $local_id ): bool {
		return EntityOrigin::order_linked_by_export( $local_id, $platform );
	}

	public function link_sources(): array {
		return [ new OrderLinkSource() ];
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_orders( $local_ids );
	}

	/**
	 * 移行後検証レポート（D17）用: この run で ASP から取得した受注の合計金額を、書込の成否・スキップに関わらず全 processed 分で累積する
	 * （Woo 側の「リンク済み受注の合計」と並べ、警告・例外で取り込めなかった分も金額で可視化するため）。解析できない金額は 0。
	 */
	public function remote_amount( CanonicalModel $item ): ?int {
		if ( ! $item instanceof CanonicalOrder ) {
			return null;
		}

		return Money::to_minor_units( $item->totals['total'] ?? null ) ?? 0;
	}

	public function local_amount_summary( array $local_ids ): array {
		return ( new LocalEntityLookup() )->summarize_orders( $local_ids );
	}

	public function dry_run_label( CanonicalModel $item ): string {
		return $item instanceof CanonicalOrder ? $item->number : '';
	}

	public function mapping_kinds(): array {
		return [
			new PaymentMappingKind(),
			new ShippingMappingKind(),
			new OrderStatusMappingKind(),
		];
	}

	public function warning_flags(): array {
		return OrderWarnings::flags();
	}

	public function describe_warning( string $code, bool $import, string $row_entity ): ?WarningText {
		return OrderWarnings::describe( $code, $import, $row_entity );
	}
}
