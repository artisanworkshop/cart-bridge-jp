<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalTag;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\PlatformOwnership;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WP_Error;

/**
 * `CanonicalCategory`（taxonomy: `product_cat`）/ `CanonicalTag`（taxonomy: `product_tag`）共用。
 * mappingsに無ければ常に新規作成する（既存Wooデータの無関係な上書きを避けるユーザー方針。
 * mappings欠損時の突合はF1-7のリンク再構築ツール=D16が担う）。ただしWPは階層タクソノミーで
 * 同名・同親のタームを重複作成できず `WP_Error('term_exists')` を返すため、その場合のみ
 * 技術的な制約として既存タームを再利用する。
 */
final class TermWriter implements EntityWriter {

	public function __construct(
		private readonly string $taxonomy,
		private readonly string $platform,
		private readonly MappingRepository $mappings,
		private readonly MediaImporter $media
	) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalCategory && ! $item instanceof CanonicalTag ) {
			throw new RuntimeException( 'TermWriter received an unsupported Canonical model.' );
		}

		$warnings  = [];
		$name      = $item->name;
		$parent_id = 0;

		if ( $item instanceof CanonicalCategory && null !== $item->parent_id ) {
			$resolved = $this->mappings->find_local_id( $this->platform, 'category', $item->parent_id );

			if ( null === $resolved ) {
				$warnings[] = WarningCode::with_detail( WarningCode::CATEGORY_PARENT_UNRESOLVED, $item->parent_id );
			} else {
				$parent_id = $resolved;
			}
		}

		$description = Value::string( $item->extras['description'] ?? null ) ?? '';
		$args        = [
			'description' => $description,
			'parent'      => $parent_id,
		];

		[ $term_id, $operation, $term_warnings ] = null !== $existing_local_id
			? $this->update_existing( $existing_local_id, $name, $args )
			: $this->create_or_reuse( $name, $args );

		$warnings = array_merge( $warnings, $term_warnings );

		if ( null === $term_id ) {
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		$this->apply_extras( $term_id, $item );

		return new WriteResult( $term_id, $operation, $warnings );
	}

	/**
	 * @param array{description:string,parent:int} $args
	 * @return array{0:?int,1:string,2:array<int,string>}
	 */
	private function update_existing( int $term_id, string $name, array $args ): array {
		$result = wp_update_term( $term_id, $this->taxonomy, array_merge( $args, [ 'name' => $name ] ) );

		if ( $result instanceof WP_Error ) {
			if ( 'invalid_term_id' === $result->get_error_code() ) {
				// 対象タームが既に存在しない（手動削除等）。新規作成へフォールバックする。
				return $this->create_or_reuse( $name, $args );
			}

			// `duplicate_term_slug`等、対象タームは実在するがバリデーションで弾かれた場合
			// （例: リネーム後の名前が無関係な兄弟タームと衝突）を「削除済み」と誤認して
			// create_or_reuse()に回すと、無関係な衝突先タームを誤って再利用しかねない。
			// 保存は見送り、元のターム・名前はそのまま残して警告のみ積む。
			return [ $term_id, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::TERM_UPDATE_FAILED, $result->get_error_code() ) ] ];
		}

		return [ $term_id, WriteResult::OPERATION_UPDATED, [] ];
	}

	/**
	 * @param array{description:string,parent:int} $args
	 * @return array{0:?int,1:string,2:array<int,string>}
	 */
	private function create_or_reuse( string $name, array $args ): array {
		$inserted = wp_insert_term( $name, $this->taxonomy, $args );

		if ( ! $inserted instanceof WP_Error ) {
			return [ (int) $inserted['term_id'], WriteResult::OPERATION_CREATED, [] ];
		}

		$existing_term_id = $inserted->get_error_data( 'term_exists' );

		if ( ! is_numeric( $existing_term_id ) ) {
			return [ null, WriteResult::OPERATION_SKIPPED, [] ];
		}

		$term_id = (int) $existing_term_id;

		// WPの技術的制約（同名・同親のタームを重複作成できない）による再利用は、
		// `_cbjp_platform`が自分自身と一致する場合のみ許す（CouponWriter/VariationWriterの
		// 同種の他プラットフォーム保護と同じ理由）。一致しない場合（店舗独自カテゴリ・
		// 別プラットフォーム由来のカテゴリと名前が衝突）は上書きせず、保存自体を見送る。
		if ( ! PlatformOwnership::owns_term( $term_id, $this->platform ) ) {
			return [ null, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::TERM_NAME_CONFLICT, (string) $term_id ) ] ];
		}

		$warnings      = [ WarningCode::with_detail( WarningCode::TERM_REUSED_EXISTING, (string) $term_id ) ];
		$update_result = wp_update_term( $term_id, $this->taxonomy, $args );

		if ( $update_result instanceof WP_Error ) {
			// 再利用自体（term_idの解決）は成功しても、description/parent等の反映が失敗した
			// 場合を可視化する。戻り値を無視すると「再利用できた」という警告だけが残り、
			// 実際には内容が古いまま（親カテゴリ未反映等）になっていることに気付けない。
			$warnings[] = WarningCode::with_detail( WarningCode::TERM_UPDATE_FAILED, $update_result->get_error_code() );
		}

		return [ $term_id, WriteResult::OPERATION_UPDATED, $warnings ];
	}

	private function apply_extras( int $term_id, CanonicalCategory|CanonicalTag $item ): void {
		$sort = Value::int( $item->extras['sort'] ?? null );

		if ( null !== $sort ) {
			update_term_meta( $term_id, 'order', $sort );
		}

		$meta_tag = Value::array_or_null( $item->extras['meta_tag'] ?? null );

		if ( null !== $meta_tag ) {
			update_term_meta( $term_id, '_cbjp_meta_tag', wp_json_encode( $meta_tag ) );
		}

		$image_url = Value::string( $item->extras['image_url'] ?? null );

		if ( null !== $image_url ) {
			$attachment_id = $this->media->import( $image_url, 0 );

			if ( null !== $attachment_id ) {
				update_term_meta( $term_id, 'thumbnail_id', $attachment_id );
			}
		}

		update_term_meta( $term_id, '_cbjp_platform', $this->platform );
		update_term_meta( $term_id, '_cbjp_remote_id', $item->remote_id() );
	}
}
