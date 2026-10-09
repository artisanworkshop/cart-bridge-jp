<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Entities\EntityTypeRegistry;
use Throwable;

/**
 * `GET /push-intents/{platform}` が表示する、未解決push intentのローカル実体の説明を組み立てる
 * （D21-B。`docs/03-design-decisions.md` §10.2「解除UIとREST」）。店舗がColorMe管理画面で
 * 実体を探す手がかり（受注番号・日時・合計／商品名・SKU／顧客メール）を返す。PII（メール・
 * 受注番号等）を含むが、これは画面とREST応答専用。`Support\Logger`へは絶対に渡さないこと
 * （個人情報禁止ルール）。
 *
 * 説明は実体の種類が組み立てる（`Entities\EntityType::describe_local()`。R3-6b1）。登録の無い種類・例外を投げた種類は
 * 「実体が無い」として返す（一覧全体を落とさない）。
 */
final class PushIntentPresenter {

	/**
	 * @return array{exists:bool, edit_url:?string, details:array<string,mixed>}
	 */
	public function describe( string $entity_type, int $local_id ): array {
		$missing = [
			'exists'   => false,
			'edit_url' => null,
			'details'  => [],
		];
		$type    = EntityTypeRegistry::get( $entity_type );

		if ( null === $type ) {
			return $missing;
		}

		try {
			$description = $type->describe_local( $local_id );
		} catch ( Throwable ) {
			return $missing;
		}

		// 外部の種類の戻り値は信用しない（原則 8）。
		if ( ! is_bool( $description['exists'] ?? null ) || ! is_array( $description['details'] ?? null ) ) {
			return $missing;
		}

		$edit_url = $description['edit_url'] ?? null;

		return [
			'exists'   => $description['exists'],
			'edit_url' => is_string( $edit_url ) ? $edit_url : null,
			'details'  => $description['details'],
		];
	}
}
