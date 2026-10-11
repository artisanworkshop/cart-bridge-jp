<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Fixtures;

use CartBridgeJP\Pro\License\LicenseStore;

/**
 * テストでキーを有効化した状態を作る（D30・R3-8b: キーを有効化していない Pro は顧客・受注・クーポンを dry-run だけにする）。
 * 有効化の判定はローカルの記録（`LicenseStore::STATE_OPTION`）だけで決まるので、記録だけを書く（R3-8a）。option はテストごとの ROLLBACK で戻る。
 */
trait ActivatesLicense {

	private function activate_license(): void {
		update_option(
			LicenseStore::STATE_OPTION,
			[
				'activated'    => 'yes',
				'activated_at' => time(),
				'product_id'   => 1,
			],
			false
		);
	}

	private function deactivate_license(): void {
		delete_option( LicenseStore::STATE_OPTION );
	}
}
