<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

/**
 * キーを有効化していない間は、顧客・受注・クーポンを dry-run だけにする（D30・R3-8b。`docs/03` §10.7）。
 *
 * 判定は Pro だけが持ち、無料版には汎用の口（`Entities\EntityType::dry_run_only_reason()`）から理由の文だけを渡す（§10.7「守ること」1）。
 * 有効化の状態はローカルの記録（`LicenseStore::is_activated()`。R3-8a）だけで決め、期限後・サーバーの状態確認の結果では変えない（D14）。
 * **呼ぶたびに読み、結果を保持しない**: 無料版のレジストリは種類のインスタンスを静的に持つので、保持すると有効化・無効化の後も古い判定が残る。
 */
final class LicenseGate {

	private function __construct() {}

	/**
	 * 有効化済みなら null（制限なし）。そうでなければ、画面とエラーに出す理由の文（呼ばれるたびに翻訳する）。
	 */
	public static function dry_run_only_reason(): ?string {
		if ( ( new LicenseStore() )->is_activated() ) {
			return null;
		}

		return __( 'To import and export this data, activate your Cart Bridge JP Pro license key under WooCommerce > Cart Bridge JP Pro.', 'cart-bridge-jp-pro' );
	}
}
