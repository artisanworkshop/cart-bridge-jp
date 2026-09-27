<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use RuntimeException;

/**
 * `PushIntentResolver::resolve_link()`/`resolve_not_created()` が投げる、理由コードを運ぶ例外
 * （`Woo\Tools\RepairInterruptedException`と同じ「1クラス+理由定数」スタイル）。
 * `Admin\RestController` が理由ごとにHTTPステータス・エラーコードへ変換する。
 */
final class PushIntentResolutionException extends RuntimeException {

	/**
	 * 指定された platform + id の push intent が見つからない（他platformのid、既に解除済み等）。
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * `link`: 指定された remote_id がASP側に存在しない（`fetch_*_by_remote_id()`がnullを返した）。
	 */
	public const REMOTE_NOT_FOUND = 'remote_not_found';

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

	public function __construct( private readonly string $reason ) {
		parent::__construct( "Push intent resolution failed: {$reason}" );
	}

	public function reason(): string {
		return $this->reason;
	}
}
