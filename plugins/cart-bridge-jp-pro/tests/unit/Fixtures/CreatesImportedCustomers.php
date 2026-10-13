<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Fixtures;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Pro\Woo\Writer\CustomerWriter;

/**
 * パスワード設定メール（R3-10）のテストの顧客: 取込みで作った顧客（今の `CustomerWriter` で作る）・0.1.0 で作った顧客（作成の印が無い）・
 * メールの一致で採用した既存のアカウント、と 0.1.0 の顧客の取込みジョブ（`cbjp_jobs` の行）。
 */
trait CreatesImportedCustomers {

	/**
	 * 今の取込みで作った顧客（作成の印・作成時の控えあり）。
	 */
	private function imported_customer( string $remote_id, ?string $email = null ): int {
		$result = ( new CustomerWriter( 'colorme' ) )->write( CommerceFactory::customer( $remote_id, $email ?? "customer{$remote_id}@example.com" ), null );

		return $result->local_id;
	}

	/**
	 * 0.1.0 の取込みで作った顧客（紐づけの印だけ。作成の印・控えが無い）。登録日時は `$registered`（UTC の `Y-m-d H:i:s`）。
	 */
	private function legacy_customer( string $remote_id, string $registered, string $platform = 'colorme' ): int {
		$user_id = self::factory()->user->create(
			[
				'role'            => 'customer',
				'user_email'      => "legacy{$remote_id}@example.com",
				'user_registered' => $registered,
			]
		);
		update_user_meta( $user_id, '_cbjp_platform', $platform );
		update_user_meta( $user_id, '_cbjp_remote_id', $remote_id );

		return $user_id;
	}

	/**
	 * 0.1.0 の顧客の取込みジョブ（totals に `remote_amount` が無い）。
	 *
	 * @param array<string,int> $totals
	 */
	private function legacy_import_job( string $created_at, string $updated_at, array $totals = [ 'created' => 10 ], string $status = 'completed', ?string $error_code = null, string $platform = 'colorme' ): int {
		global $wpdb;

		Activator::activate();

		$wpdb->insert(
			$wpdb->prefix . 'cbjp_jobs',
			[
				'run_id'      => wp_generate_uuid4(),
				'type'        => 'import',
				'platform'    => $platform,
				'entity'      => 'customer',
				'status'      => $status,
				'cursor_json' => null,
				'totals_json' => (string) wp_json_encode( $totals + [ 'total' => 0 ] ),
				'error_json'  => null === $error_code ? null : (string) wp_json_encode(
					[
						'code'    => $error_code,
						'message' => 'x',
					]
				),
				'created_at'  => $created_at,
				'updated_at'  => $updated_at,
			]
		);

		return (int) $wpdb->insert_id;
	}
}
