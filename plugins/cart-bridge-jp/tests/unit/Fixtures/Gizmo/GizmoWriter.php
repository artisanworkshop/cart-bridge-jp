<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures\Gizmo;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\ValidationResult;
use RuntimeException;

/**
 * gizmo を投稿（`post`）として書く。取込みの印（`_cbjp_platform`・`_cbjp_remote_id`）を書くので、D25 とリンク再構築の対象になる。
 */
final class GizmoWriter implements EntityWriter {

	public function __construct( private readonly string $platform ) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		$gizmo    = self::gizmo( $item );
		$warnings = self::warnings( $gizmo );
		$exists   = null !== $existing_local_id && 'post' === get_post_type( $existing_local_id );
		$post_id  = $exists ? (int) $existing_local_id : (int) wp_insert_post(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => $gizmo->name,
			]
		);

		if ( $exists ) {
			wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => $gizmo->name,
				]
			);
		}

		update_post_meta( $post_id, '_gizmo', '1' );
		update_post_meta( $post_id, '_gizmo_amount', (string) $gizmo->amount );
		update_post_meta( $post_id, '_cbjp_platform', $this->platform );
		update_post_meta( $post_id, '_cbjp_remote_id', $gizmo->id );

		return new WriteResult( $post_id, $exists ? WriteResult::OPERATION_UPDATED : WriteResult::OPERATION_CREATED, $warnings, [] === $warnings );
	}

	public function validate( CanonicalModel $item, ?int $existing_local_id ): ValidationResult {
		$gizmo = self::gizmo( $item );

		return new ValidationResult( null !== $existing_local_id ? 'updated' : 'created', self::warnings( $gizmo ) );
	}

	/**
	 * @return array<int,string>
	 */
	private static function warnings( CanonicalGizmo $gizmo ): array {
		return str_contains( $gizmo->name, 'pending' ) ? [ 'gizmo_pending:' . $gizmo->id ] : [];
	}

	private static function gizmo( CanonicalModel $item ): CanonicalGizmo {
		if ( ! $item instanceof CanonicalGizmo ) {
			throw new RuntimeException( 'Not a gizmo.' );
		}

		return $item;
	}
}
