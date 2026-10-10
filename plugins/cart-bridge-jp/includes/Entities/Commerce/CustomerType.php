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
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Reader\CustomerReader;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Support\CommerceOrigin;
use CartBridgeJP\Woo\Tools\CommerceLookup;
use CartBridgeJP\Woo\Writer\CustomerWriter;
use CartBridgeJP\Woo\Writer\EntityWriter;
use RuntimeException;
use WP_User;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * 顧客（WP ユーザー）。取込み・エクスポートの両方。
 *
 * **R3-6c で Pro アドオンへ移す**（D27。`Entities/Commerce/` ごと）。それまでは無料版が `cbjp/entity_types/register` から登録する
 * （`Core\Plugin::boot()`）。dry-run の CSV のラベルは個人情報（氏名・メール）を含みうるため空（既定）。
 */
final class CustomerType extends EntityType {

	public function key(): string {
		return 'customer';
	}

	public function label(): string {
		return __( 'Customers', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 40;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return CommerceAdapters::get( $adapter )?->capabilities()->can_fetch_customers ?? false;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return CommerceAdapters::get_required( $adapter, 'fetch_customers' )->fetch_customers( $cursor );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new CustomerWriter( $platform );
	}

	public function supports_export( PlatformAdapter $adapter ): bool {
		return CommerceAdapters::get( $adapter )?->capabilities()->can_update_customer ?? false;
	}

	public function reader( string $platform, WooServices $services ): EntityReader {
		return new CustomerReader( $platform );
	}

	public function push( PlatformAdapter $adapter, CanonicalModel $item, ?string $remote_id ): PushResult {
		if ( ! $item instanceof CanonicalCustomer ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "customer".' );
		}

		return CommerceAdapters::get_required( $adapter, 'push_customer' )->push_customer( $item, $remote_id );
	}

	public function records_push_intent(): bool {
		return true;
	}

	public function fetch_by_remote_id( PlatformAdapter $adapter, string $remote_id ): ?CanonicalModel {
		return CommerceAdapters::get_required( $adapter, 'fetch_customer_by_remote_id' )->fetch_customer_by_remote_id( $remote_id );
	}

	public function describe_local( int $local_id ): array {
		$user = get_userdata( $local_id );

		if ( ! $user instanceof WP_User ) {
			return parent::describe_local( $local_id );
		}

		return [
			'exists'   => true,
			'edit_url' => get_edit_user_link( $local_id ),
			'summary'  => $user->user_email,
			'details'  => [
				'email' => $user->user_email,
			],
		];
	}

	/**
	 * 実体が無い・保護ロールの顧客（取込みが印を書かない。`CustomerWriter::write()`）は偽を返し、従来どおり writer に任せる。
	 */
	public function is_linked_by_export( string $platform, int $local_id ): bool {
		return false !== get_userdata( $local_id )
			&& ! CustomerWriter::has_protected_role( $local_id )
			&& ! CommerceOrigin::user_linked_by_import( $local_id, $platform );
	}

	public function link_sources(): array {
		return [ new CustomerLinkSource() ];
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new CommerceLookup() )->existing_users( $local_ids );
	}

	public function warning_flags(): array {
		return CustomerWarnings::flags();
	}

	public function describe_warning( string $code, bool $import, string $row_entity ): ?WarningText {
		return CustomerWarnings::describe( $code, $import, $row_entity );
	}
}
