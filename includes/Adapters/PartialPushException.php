<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

use RuntimeException;
use Throwable;

/**
 * 「リモートへの作成は確定したが、後続の処理が途中で止まった」ことを表す（D21-A。
 * `docs/03-design-decisions.md` §10.2「エクスポートの重複作成防止（D21）」）。
 *
 * `push_*()` は作成（`$remote_id === null`）が確定した後、後続のリクエスト（追加項目の反映・
 * バリエーション・画像等）で例外が起きても、その例外を素のまま外へ出さずこの例外に包んで
 * 投げること。`Sync\Exporter` は `remote_id()` を checksum=null で `cbjp_mappings` へ書き、
 * 次回のexportを新規作成（POST）ではなく既存実体への更新（PUT）にする。素のまま出すと
 * mappingが残らず、次回のexportが同じWoo実体をもう一度作成してリモート側に重複を作る
 * （原則4によりプラグインはリモートを削除しないため、重複は店舗が手作業で消すしかない）。
 *
 * 本来の原因は `getPrevious()`。`RateLimitExhaustedException` なら `Sync\Exporter` は mapping を
 * 書いた後にそれを再スローし、ジョブは従来どおり `paused` になる。
 */
final class PartialPushException extends RuntimeException {

	/**
	 * @param string    $remote_id 作成が確定したリモート側の実体ID。
	 * @param Throwable $previous  後続の処理を止めた本来の例外。
	 */
	public function __construct( private readonly string $remote_id, Throwable $previous ) {
		// 例外メッセージへ ID・顧客情報・リモートの応答本文を含めない（`Support\Logger` の
		// 個人情報禁止ルールと同じ理由で、固定文言のみ）。
		parent::__construct( 'Push was interrupted after the remote entity had been created.', 0, $previous );
	}

	public function remote_id(): string {
		return $this->remote_id;
	}
}
