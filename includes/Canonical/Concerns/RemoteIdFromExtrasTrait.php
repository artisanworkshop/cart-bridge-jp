<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical\Concerns;

/**
 * リモートIDの専用フィールドを持たないモデル（Product/Customer/Coupon/Review）向けの
 * `CanonicalModel::remote_id()` 実装。アダプタは `extras['remote_id']` に必ず格納すること。
 */
trait RemoteIdFromExtrasTrait {

	public function remote_id(): ?string {
		$remote_id = $this->extras['remote_id'] ?? null;

		if ( null === $remote_id || '' === $remote_id ) {
			return null;
		}

		return (string) $remote_id;
	}
}
