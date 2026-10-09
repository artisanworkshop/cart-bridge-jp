<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use Throwable;

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
		$type        = EntityTypeRegistry::get( $entity_type );

		try {
			// ID 指定取得は実体の種類が持つ（`Entities\EntityType::fetch_by_remote_id()`。R3-6b1）。取得できない種類（クーポンは
			// ColorMe 側が読取専用で元々非対応。`docs/03` §10.2「エクスポートの重複作成防止」参照）は既定の
			// `UnsupportedOperationException` で LINK_UNSUPPORTED になる。登録の無い種類は今までどおり「リモートに無い」扱い。
			$remote_entity = null === $type ? null : $type->fetch_by_remote_id( $adapter, $remote_id );
		} catch ( UnsupportedOperationException ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::LINK_UNSUPPORTED );
		} catch ( RateLimitExhaustedException ) {
			// クライアント側スロットル。待てば再開できる（`classify_api_failure()`のASP側429と同じ区分）。
			throw new PushIntentResolutionException( PushIntentResolutionException::RATE_LIMITED );
		} catch ( ApiException $exception ) {
			throw new PushIntentResolutionException( $this->classify_api_failure( $exception ) );
		} catch ( Throwable $exception ) {
			// 200応答でも想定した形でない等、アダプタの契約違反。個人情報を含みうるメッセージは
			// 記録せず、REST層へは理由コードのみ渡す。
			throw new PushIntentResolutionException( PushIntentResolutionException::REMOTE_UNAVAILABLE );
		}

		// 外部アダプタの戻り値は信用しない（アーキテクチャ原則8）。契約違反のアダプタが要求と異なる実体を返すと、要求した`remote_id`が
		// あたかも実在するかのように見え、無関係な別のローカル実体へ紐付いてしまう
		// （レビュー指摘: Copilot/Codex共通）。返ってきたモデル自身の`remote_id()`が要求した
		// `$remote_id`と一致することまで確認する。
		if ( null === $remote_entity || $remote_entity->remote_id() !== $remote_id ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::REMOTE_NOT_FOUND );
		}

		$existing_local_id = $this->mappings->find_local_id( $platform, $entity_type, $remote_id );

		if ( null !== $existing_local_id && $existing_local_id !== $intent['local_id'] ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::REMOTE_ID_IN_USE );
		}

		// レビュー指摘（Copilot, G3）: この実体（intentのlocal_id）に対応する古いmapping行が
		// 別のremote_idで既に存在する場合（例: crashでintentが消えないまま残った後、店舗が
		// 別のremote_idで`link`した）、`upsert()`のユニークキー（platform, entity_type,
		// remote_id）は新remote_idで別行をINSERTするだけで旧remote_idの行が孤児として残る。
		// `find_remote_id()`はid昇順の最初の行（＝古い方）を採用するため、以後のexportは
		// 今回linkした新remote_idではなく古い方へ再送し続けてしまう（`Sync\Exporter::
		// process_items()`が作成/バリエーション双方で同じ理由で先に行っている処理と同じ）。
		$stale_remote_id = $this->mappings->find_remote_id( $platform, $entity_type, $intent['local_id'] );

		if ( null !== $stale_remote_id && $stale_remote_id !== $remote_id ) {
			$this->mappings->delete_one( $platform, $entity_type, $stale_remote_id );
		}

		$this->mappings->upsert( $platform, $entity_type, $remote_id, $intent['local_id'], null );

		// レビュー指摘（Codex P1）: `MappingRepository::upsert()`は`$wpdb->query()`の戻り値を
		// 捨てて`void`を返すため、一過性のDB障害があっても例外にならない。upsert後にmappingが
		// 本当に書けたことを読み直して確認してからintentを消す（先に消すと、書けていないのに
		// 「解決済み」を返し、次回exportが未送信の実体をもう一度作成しうる）。
		if ( $intent['local_id'] !== $this->mappings->find_local_id( $platform, $entity_type, $remote_id ) ) {
			throw new PushIntentResolutionException( PushIntentResolutionException::REMOTE_UNAVAILABLE );
		}

		$this->intents->delete_by_id( $id );
	}

	/**
	 * APIエラーの区分（再接続が必要 / レート制限 / その他のAPIエラー）。status 0 だけでは「未接続」「通信断」「JSON破損」を区別できないため、
	 * `context['not_connected'] === true`（アダプタが明示。`ColorMeAdapter::client()`）または
	 * 401/403のときだけ再接続案内にする（`.claude/rules/adapters-colorme.md`）。
	 */
	private function classify_api_failure( ApiException $exception ): string {
		if ( in_array( $exception->status_code(), [ 401, 403 ], true ) || true === ( $exception->context()['not_connected'] ?? false ) ) {
			return PushIntentResolutionException::NOT_CONNECTED;
		}

		if ( $exception->is_rate_limited() || 429 === $exception->status_code() ) {
			return PushIntentResolutionException::RATE_LIMITED;
		}

		return PushIntentResolutionException::REMOTE_UNAVAILABLE;
	}
}
