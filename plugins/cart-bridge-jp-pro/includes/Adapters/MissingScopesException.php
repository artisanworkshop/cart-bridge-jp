<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Adapters;

use RuntimeException;

/**
 * 同梱の組み立て（`CommerceAdapters::bundled()`）が、接続済みのトークンに顧客・受注・クーポンのスコープが無いために組み立てなかったことを
 * `CommerceAdapters::build()` へ伝える（R3-6c2 PR #118 G2-B1）。外部の登録が組み立てを外した・失敗した「無い」と区別し、こちらだけを
 * 「接続し直せば扱える」（`get_required()` の `not_connected`）にする。`CommerceAdapters` の外へは出さない。
 */
final class MissingScopesException extends RuntimeException {}
