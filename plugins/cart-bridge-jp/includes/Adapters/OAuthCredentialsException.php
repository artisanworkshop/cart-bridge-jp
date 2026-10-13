<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

use RuntimeException;

/**
 * OAuth の認可・トークン交換に使う資格情報（client_id・client_secret）が無い、または交換の途中で変わった（R3-5b）。
 * OAuth のコールバックは例外の文を画面へ渡さず、この種類で案内を選ぶ（`Admin\RestController::oauth_error_code()`）。
 */
final class OAuthCredentialsException extends RuntimeException {}
