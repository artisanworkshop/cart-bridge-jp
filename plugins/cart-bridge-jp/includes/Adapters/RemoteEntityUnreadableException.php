<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

use Exception;
use Throwable;

/**
 * ID 指定取得（`fetch_*_by_remote_id()`）で、接続先は項目を返したが、それを Canonical のモデルにできなかったことを表す
 * （変換の失敗・取り込まない種類の行。R3-11）。
 *
 * ID 指定取得が null を返すのは、接続先が「その ID の項目は無い」と答えたとき（404）だけにする。読めない項目まで null にすると、
 * push intent の「リンクして解除」（`Woo\Tools\PushIntentResolver::resolve_link()`）が「接続先に存在しない」と答え、
 * それを信じて「未作成として解除」した店舗の次のエクスポートが、実在する実体をもう一度作る（D21-B が防ぐ重複）。
 *
 * `RuntimeException` ではなく `Exception` を継承する: アダプタが投げる `RuntimeException` は応答の形の崩れ（envelope の欠落など）を
 * 表し、push intent の解除では「接続先のエラー」になる。この例外は `UnsupportedOperationException` と同じく、呼び出し側が扱う想定内の結果。
 */
final class RemoteEntityUnreadableException extends Exception {

	/**
	 * @param string     $platform プラットフォーム ID。
	 * @param string     $entity   実体の種類のキー（`product` など）。
	 * @param ?Throwable $previous 変換を止めた例外（取り込まない種類の行で例外が無いときは null）。
	 */
	public function __construct( string $platform, string $entity, ?Throwable $previous = null ) {
		// 例外の文に ID・応答の本文を含めない（個人情報を含みうる。`PartialPushException` と同じ）。
		parent::__construct( sprintf( 'Platform "%s" returned the "%s" item for the given ID, but it could not be read.', $platform, $entity ), 0, $previous );
	}
}
