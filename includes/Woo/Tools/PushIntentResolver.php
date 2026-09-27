<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;

/**
 * `POST /push-intents/{platform}/{id}/resolve` の解除ロジック（D21-B。
 * `docs/03-design-decisions.md` §10.2「解除UIとREST」）。`Admin\RestController` はHTTP関連の
 * 判断（権限・進行中run・HTTPステータスへの変換）のみを持ち、ここには持ち込まない。
 */
final class PushIntentResolver {

	public function __construct(
		private readonly PushIntentRepository $intents,
		private readonly MappingRepository $mappings
	) {}

	/**
	 * 「ColorMeに作成されていなかった」ことを店舗が確認した場合の解除。intentを消すだけで、
	 * mappingは書かない（次回exportが改めて作成を試みる）。
	 *
	 * @throws PushIntentResolutionException NOT_FOUND
	 */
	public function resolve_not_created( string $platform, int $id ): void {
		$intent = $this->intents->find( $id, $platform );

		if ( null === $intent ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::NOT_FOUND );
		}

		$this->intents->delete_by_id( $id );
	}

	/**
	 * 「ColorMeに作成済みだった」ことを店舗が確認し、remote_idを紐付ける場合の解除。
	 * 実在・種別・重複を確認してから checksum=null で mapping を書き、intentを消す
	 * （D21-Aと同じくchecksum=nullで次回exportに残りの詳細を再試行させる）。
	 *
	 * @throws PushIntentResolutionException NOT_FOUND / LINK_UNSUPPORTED / REMOTE_NOT_FOUND / REMOTE_ID_IN_USE
	 */
	public function resolve_link( string $platform, int $id, PlatformAdapter $adapter, string $remote_id ): void {
		$intent = $this->intents->find( $id, $platform );

		if ( null === $intent ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::NOT_FOUND );
		}

		$entity_type = $intent['entity_type'];

		if ( 'coupon' === $entity_type ) {
			// `PlatformAdapter`にクーポンのID指定取得メソッドが無い（クーポンはColorMe側が
			// 読取専用のため元々非対応。`docs/03` §10.2「エクスポートの重複作成防止」参照）ため、
			// 実在を確認できない。
			throw new PushIntentResolutionException( PushIntentResolutionException::LINK_UNSUPPORTED );
		}

		try {
			$remote_entity = match ( $entity_type ) {
				'product' => $adapter->fetch_product_by_remote_id( $remote_id ),
				'customer' => $adapter->fetch_customer_by_remote_id( $remote_id ),
				'order' => $adapter->fetch_order_by_remote_id( $remote_id ),
				default => null,
			};
		} catch ( UnsupportedOperationException ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::LINK_UNSUPPORTED );
		}

		if ( null === $remote_entity ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::REMOTE_NOT_FOUND );
		}

		$existing_local_id = $this->mappings->find_local_id( $platform, $entity_type, $remote_id );

		if ( null !== $existing_local_id && $existing_local_id !== $intent['local_id'] ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::REMOTE_ID_IN_USE );
		}

		$this->mappings->upsert( $platform, $entity_type, $remote_id, $intent['local_id'], null );
		$this->intents->delete_by_id( $id );
	}
}
