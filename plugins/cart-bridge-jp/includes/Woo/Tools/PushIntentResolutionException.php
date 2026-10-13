<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use RuntimeException;

/**
 * `PushIntentResolver::resolve_link()`/`resolve_not_created()` が投げる、理由コードを運ぶ例外
 * （「1クラス+理由定数」スタイル）。
 * `Admin\RestController` が理由ごとにHTTPステータス・エラーコードへ変換する。
 */
final class PushIntentResolutionException extends RuntimeException {

	/**
	 * 指定された platform + id の push intent が見つからない（他platformのid、既に解除済み等）。
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * `link`: 指定された remote_id がASP側に存在しない（`fetch_*_by_remote_id()`がnullを返した。null は 404 だけの意味。R3-11）。
	 */
	public const REMOTE_NOT_FOUND = 'remote_not_found';

	/**
	 * `link`: ASP は指定された remote_id の項目を返したが、読めない（`Adapters\RemoteEntityUnreadableException`）、
	 * または返った実体の remote_id が要求と一致しない（契約違反）。実在しないとは言えないので REMOTE_NOT_FOUND にしない（R3-11）。
	 */
	public const REMOTE_UNREADABLE = 'remote_unreadable';

	/**
	 * `link`・`not_created`: intent の種類を扱う実体の種類が登録されていない（拡張を停止した後に残った intent など）。
	 * 実在を確かめられず、未作成として解除すると拡張を戻した後のエクスポートが重複を作りうるので、どちらの解除も断る（R3-11）。
	 */
	public const TYPE_UNAVAILABLE = 'type_unavailable';

	/**
	 * `link`: 指定された remote_id が既に別のローカル実体のmappingに使われている
	 * （`upsert()`のON DUPLICATE KEY UPDATEが黙って付け替えてしまうため事前に拒否する）。
	 */
	public const REMOTE_ID_IN_USE = 'remote_id_in_use';

	/**
	 * `link`: このentity_typeにはID指定取得が無い（クーポン）、またはアダプタが
	 * `UnsupportedOperationException`を投げた。実在を確認できないため`link`は許可しない
	 * （`not_created`のみ可）。
	 */
	public const LINK_UNSUPPORTED = 'link_unsupported';

	/**
	 * `link`: プラットフォームへの接続が切れている（401/403、または`ApiException::context()`の
	 * `not_connected`が明示された場合。`PushIntentResolver::classify_api_failure()`）。
	 */
	public const NOT_CONNECTED = 'not_connected';

	/**
	 * `link`: クライアント側スロットル（`RateLimitExhaustedException`）、またはASP側が429で
	 * リトライ上限に達した。待てば再開できる。
	 */
	public const RATE_LIMITED = 'rate_limited';

	/**
	 * `link`: 上記以外のAPIエラー・契約違反（5xx・通信断・想定外のレスポンス形）。
	 */
	public const REMOTE_UNAVAILABLE = 'remote_unavailable';

	/**
	 * REMOTE_UNREADABLE の原因（`cause()`。REST がログの context に書く。個人情報を含まない固定の値。R3-11 review-loop R1-L2）:
	 * 接続先が返した行は取り込まない種類（例外なし。Pro の顧客の非会員・メールの無い会員など）。
	 */
	public const CAUSE_UNREADABLE_ROW = 'unreadable_row';

	/**
	 * 接続先が返した行を変換できなかった（変換の例外。アダプタが別に「Failed to transform…」をログに残す）。
	 */
	public const CAUSE_TRANSFORM_FAILED = 'transform_failed';

	/**
	 * 返った実体の remote_id が要求と一致しない（ID の打ち間違い・契約違反）。
	 */
	public const CAUSE_ID_MISMATCH = 'id_mismatch';

	public function __construct( private readonly string $reason, private readonly string $cause = '' ) {
		parent::__construct( "Push intent resolution failed: {$reason}" );
	}

	public function reason(): string {
		return $this->reason;
	}

	/**
	 * 理由の内訳（今は REMOTE_UNREADABLE だけ。ほかの理由は ''）。
	 */
	public function cause(): string {
		return $this->cause;
	}
}
