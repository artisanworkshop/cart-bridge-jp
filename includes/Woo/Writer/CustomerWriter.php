<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\AddressMapper;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WP_Error;
use WP_User;

/**
 * `CanonicalCustomer` をWPユーザー（`customer`ロール）として書き込む。email突合で既存ユーザーが
 * 見つかった場合は再利用する（ユーザー方針: 顧客のみ積極的な既存データ突合を行う）。
 */
final class CustomerWriter implements EntityWriter {

	public function __construct( private readonly string $platform ) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalCustomer ) {
			throw new RuntimeException( 'CustomerWriter received an unsupported Canonical model.' );
		}

		$warnings = [];
		$billing  = AddressMapper::to_woo( $item->address, $item->name, $item->email, $item->phone, $item->company );

		[ $user_id, $operation, $reuse_warning ] = null !== $existing_local_id
			? [ $existing_local_id, WriteResult::OPERATION_UPDATED, null ]
			: $this->find_or_create( $item, $billing );

		if ( null !== $reuse_warning ) {
			$warnings[] = $reuse_warning;
		}

		if ( null === $user_id ) {
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		if ( WriteResult::OPERATION_UPDATED === $operation ) {
			// 既存ユーザーのロールは変更しない（管理者と同じメールアドレスの場合に権限を壊さないため）。
			wp_update_user(
				[
					'ID'           => $user_id,
					'first_name'   => $billing['first_name'],
					'last_name'    => $billing['last_name'],
					'display_name' => $item->name,
				]
			);
		}

		$this->apply_addresses( $user_id, $billing );
		$this->apply_extras_meta( $user_id, $item );

		if ( AddressMapper::is_overseas( $item->address ) ) {
			$warnings[] = WarningCode::ADDRESS_OVERSEAS;
		}

		update_user_meta( $user_id, '_cbjp_platform', $this->platform );
		update_user_meta( $user_id, '_cbjp_remote_id', $item->remote_id() ?? '' );

		return new WriteResult( $user_id, $operation, $warnings );
	}

	/**
	 * @param array<string,string> $billing
	 * @return array{0:?int,1:string,2:?string}
	 */
	private function find_or_create( CanonicalCustomer $item, array $billing ): array {
		$existing_user = get_user_by( 'email', $item->email );

		if ( $existing_user instanceof WP_User ) {
			return [ $existing_user->ID, WriteResult::OPERATION_UPDATED, WarningCode::with_detail( WarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_user->ID ) ];
		}

		$created = wc_create_new_customer(
			$item->email,
			'',
			wp_generate_password( 24, true, true ),
			[
				'first_name'   => $billing['first_name'],
				'last_name'    => $billing['last_name'],
				'display_name' => $item->name,
			]
		);

		if ( $created instanceof WP_Error ) {
			return [ null, WriteResult::OPERATION_SKIPPED, null ];
		}

		return [ $created, WriteResult::OPERATION_CREATED, null ];
	}

	/**
	 * @param array<string,string> $billing
	 */
	private function apply_addresses( int $user_id, array $billing ): void {
		foreach ( $billing as $key => $value ) {
			update_user_meta( $user_id, "billing_{$key}", $value );
		}

		unset( $billing['email'], $billing['phone'] );

		foreach ( $billing as $key => $value ) {
			update_user_meta( $user_id, "shipping_{$key}", $value );
		}
	}

	private function apply_extras_meta( int $user_id, CanonicalCustomer $item ): void {
		$extras = $item->extras;
		unset( $extras['remote_id'] );

		$extras = array_merge(
			$extras,
			[
				'kana'           => $item->kana,
				'department'     => $item->department,
				'birthday'       => $item->birthday,
				'mailmag_opt_in' => null === $item->mailmag_opt_in ? null : ( $item->mailmag_opt_in ? '1' : '0' ),
				'note'           => $item->note,
				'full_name'      => $item->name,
			]
		);

		foreach ( $extras as $key => $value ) {
			$meta_key = "_cbjp_{$key}";

			if ( null === $value ) {
				delete_user_meta( $user_id, $meta_key );
				continue;
			}

			update_user_meta( $user_id, $meta_key, is_array( $value ) ? wp_json_encode( $value ) : $value );
		}
	}
}
