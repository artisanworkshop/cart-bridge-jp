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
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\PlatformOwnership;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WP_Error;
use WP_Term;

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
		$plan = $this->plan( $item, $existing_local_id );

		[ $term_id, $operation, $term_warnings ] = null !== $existing_local_id
			? $this->update_existing( $existing_local_id, $plan->name, $plan->args )
			: $this->create_or_reuse( $plan->name, $plan->args, $plan->preresolved_conflict_term_id );

		$warnings = array_merge( $plan->warnings, $term_warnings );

		if ( null === $term_id ) {
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		$warnings = array_merge( $warnings, $this->apply_extras( $term_id, $plan->item ) );

		return new WriteResult( $term_id, $operation, $warnings, ! WarningCode::indicates_unresolved_reference( $warnings ) );
	}

	public function validate( CanonicalModel $item, ?int $existing_local_id ): ValidationResult {
		$plan = $this->plan( $item, $existing_local_id );

		if ( null !== $existing_local_id && $plan->existing_term_exists ) {
			// 更新パスの`duplicate_term_slug`等のバリデーション失敗は、実際に`wp_update_term()`を
			// 呼ばないと判定できないため dry-run では出ない（`Woo\WarningCode`のdocblock参照）。
			return new ValidationResult( WriteResult::OPERATION_UPDATED, $plan->warnings );
		}

		// 新規作成、または対象タームが手動削除等で既に存在しない（`existing_term_exists`が
		// false）場合は、`write()`の`update_existing()`内`invalid_term`フォールバックと同じく
		// 新規作成パスとして扱う（TermWriterクラスdocblock・他writerのstale-ID対応と同じ方針）。
		if ( null === $plan->preresolved_conflict_term_id ) {
			return new ValidationResult( WriteResult::OPERATION_CREATED, $plan->warnings );
		}

		if ( ! PlatformOwnership::owns_term( $plan->preresolved_conflict_term_id, $this->platform ) ) {
			return new ValidationResult(
				WriteResult::OPERATION_SKIPPED,
				array_merge( $plan->warnings, [ WarningCode::with_detail( WarningCode::TERM_NAME_CONFLICT, (string) $plan->preresolved_conflict_term_id ) ] )
			);
		}

		return new ValidationResult(
			WriteResult::OPERATION_UPDATED,
			array_merge( $plan->warnings, [ WarningCode::with_detail( WarningCode::TERM_REUSED_EXISTING, (string) $plan->preresolved_conflict_term_id ) ] )
		);
	}

	/**
	 * 親タームの解決（DB読取のみ）と、新規作成パスでの名前衝突の事前判定
	 * （`term_exists()`。`wp_insert_term()`が内部で使うのと同じコアの重複判定関数で、
	 * 実際に挿入を試みずに調べられる）を`write()`/`validate()`の両方で共有する。
	 */
	private function plan( CanonicalModel $item, ?int $existing_local_id ): TermPlan {
		if ( ! $item instanceof CanonicalCategory && ! $item instanceof CanonicalTag ) {
			throw new RuntimeException( 'TermWriter received an unsupported Canonical model.' );
		}

		$warnings  = [];
		$name      = $item->name;
		$parent_id = 0;

		if ( $item instanceof CanonicalCategory && null !== $item->parent_id ) {
			$resolved = $this->mappings->find_local_id( $this->platform, 'category', $item->parent_id );

			// mappingsが指す親タームが手動削除等で既に存在しない場合も未解決として扱う
			// （`ProductWriter::resolve_refs()`の同種のstale-mapping対応と同じ方針。
			// 存在しないterm IDをそのまま`wp_insert_term()`/`wp_update_term()`の'parent'に
			// 渡すと、宙に浮いた親参照を持つカテゴリを無警告で作ってしまう）。
			if ( null === $resolved || ! get_term( $resolved, 'product_cat' ) instanceof WP_Term ) {
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

		// `get_term()`はDB読取のみ（`wp_update_term()`が内部の`get_term()`呼び出しで同じ判定を
		// 行い、対象が無ければ`invalid_term`エラーを返す）。`validate()`は実際に`wp_update_term()`を
		// 呼べないため、ここで同じ存在確認を行い、更新パスのstale-IDフォールバック
		// （TermWriterの他writerと同種の対応）を`write()`とdriftなく共有する。
		$existing_term_exists = null !== $existing_local_id && get_term( $existing_local_id, $this->taxonomy ) instanceof WP_Term;

		$preresolved_conflict_term_id = ( null === $existing_local_id || ! $existing_term_exists )
			? $this->find_conflicting_term_id( $name, $parent_id )
			: null;

		return new TermPlan( $item, $name, $args, $warnings, $preresolved_conflict_term_id, $existing_term_exists );
	}

	/**
	 * `term_exists()`はコアの重複判定関数（`wp_insert_term()`が同名・同親タームの拒否に
	 * 内部で使うのと同じ判定）で、実際に挿入せずに衝突の有無を調べられる。
	 *
	 * @return int|null 衝突する既存term_id（無ければnull）。
	 */
	private function find_conflicting_term_id( string $name, int $parent_id ): ?int {
		$result = term_exists( $name, $this->taxonomy, $parent_id );

		if ( is_array( $result ) && isset( $result['term_id'] ) ) {
			return (int) $result['term_id'];
		}

		if ( is_numeric( $result ) && 0 !== (int) $result ) {
			return (int) $result;
		}

		return null;
	}

	/**
	 * @param array{description:string,parent:int} $args
	 * @return array{0:?int,1:string,2:array<int,string>}
	 */
	private function update_existing( int $term_id, string $name, array $args ): array {
		$result = wp_update_term( $term_id, $this->taxonomy, array_merge( $args, [ 'name' => $name ] ) );

		if ( $result instanceof WP_Error ) {
			if ( 'invalid_term' === $result->get_error_code() ) {
				// 対象タームが既に存在しない（手動削除等）。`wp_update_term()`はこの場合
				// `invalid_term_id`ではなく`invalid_term`を返す（`wp-includes/taxonomy.php`の
				// `get_term()`が空を返した分岐）。新規作成へフォールバックする。
				return $this->create_or_reuse( $name, $args, $this->find_conflicting_term_id( $name, $args['parent'] ) );
			}

			// `duplicate_term_slug`等、対象タームは実在するがバリデーションで弾かれた場合
			// （例: リネーム後の名前が無関係な兄弟タームと衝突）を「削除済み」と誤認して
			// create_or_reuse()に回すと、無関係な衝突先タームを誤って再利用しかねない。
			// 保存は見送り、元のターム・名前はそのまま残して警告のみ積む。
			//
			// term_idをそのまま返さずnullにする: `Importer`はlocal_id!==0であればoperationに
			// 関わらずchecksumをmappingsへupsertするため、ここで既存term_idを返すと
			// リネームが実際には適用されなかったにも関わらず新しいitemのchecksumが
			// キャッシュされ、次回以降はchecksum一致でこの検証自体がスキップされてしまい、
			// 衝突が解消された後も永久にリネームが再試行されなくなる。nullを返せば
			// upsert自体が発生せず、既存の有効なmappingを変更せずに残せる。
			return [ null, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::TERM_UPDATE_FAILED, $result->get_error_code() ) ] ];
		}

		return [ $term_id, WriteResult::OPERATION_UPDATED, [] ];
	}

	/**
	 * @param array{description:string,parent:int} $args
	 * @return array{0:?int,1:string,2:array<int,string>}
	 */
	private function create_or_reuse( string $name, array $args, ?int $preresolved_conflict_term_id ): array {
		if ( null === $preresolved_conflict_term_id ) {
			$inserted = wp_insert_term( $name, $this->taxonomy, $args );

			if ( ! $inserted instanceof WP_Error ) {
				return [ (int) $inserted['term_id'], WriteResult::OPERATION_CREATED, [] ];
			}

			$race_conflict_id = $inserted->get_error_data( 'term_exists' );

			if ( ! is_numeric( $race_conflict_id ) ) {
				// term_exists以外の理由（empty_term_name・DBエラー等）での失敗。無警告で
				// 握りつぶすと結果レポートから欠落理由が分からなくなるため警告を積む。
				return [ null, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::TERM_CREATE_FAILED, $inserted->get_error_code() ) ] ];
			}

			// `find_conflicting_term_id()`の事前チェック後、別プロセスが同名タームを作成した
			// 場合のレース対策バックストップ（通常はここに到達せず、事前チェックで検出される）。
			$preresolved_conflict_term_id = (int) $race_conflict_id;
		}

		// WPの技術的制約（同名・同親のタームを重複作成できない）による再利用は、
		// `_cbjp_platform`が自分自身と一致する場合のみ許す（CouponWriter/VariationWriterの
		// 同種の他プラットフォーム保護と同じ理由）。一致しない場合（店舗独自カテゴリ・
		// 別プラットフォーム由来のカテゴリと名前が衝突）は上書きせず、保存自体を見送る。
		if ( ! PlatformOwnership::owns_term( $preresolved_conflict_term_id, $this->platform ) ) {
			return [ null, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::TERM_NAME_CONFLICT, (string) $preresolved_conflict_term_id ) ] ];
		}

		$warnings      = [ WarningCode::with_detail( WarningCode::TERM_REUSED_EXISTING, (string) $preresolved_conflict_term_id ) ];
		$update_result = wp_update_term( $preresolved_conflict_term_id, $this->taxonomy, $args );

		if ( $update_result instanceof WP_Error ) {
			// 再利用自体（term_idの解決）は成功しても、description/parent等の反映が失敗した
			// 場合を可視化する。戻り値を無視すると「再利用できた」という警告だけが残り、
			// 実際には内容が古いまま（親カテゴリ未反映等）になっていることに気付けない。
			$warnings[] = WarningCode::with_detail( WarningCode::TERM_UPDATE_FAILED, $update_result->get_error_code() );
		}

		return [ $preresolved_conflict_term_id, WriteResult::OPERATION_UPDATED, $warnings ];
	}

	/**
	 * @return array<int,string>
	 */
	private function apply_extras( int $term_id, CanonicalCategory|CanonicalTag $item ): array {
		$warnings = [];
		$extras   = $item->extras;
		$sort     = Value::int( $extras['sort'] ?? null );

		if ( null !== $sort ) {
			update_term_meta( $term_id, 'order', $sort );
		}
		// `order`はWoo標準のterm並び順で、`_cbjp_*`と異なりこのプラグインが書いたものか
		// 店舗がwp-adminで手動設定したものかを判別するタグを持たない（ProductWriterの
		// `weight`/`low_stock_amount`等の`_cbjp_`名前空間外のclear-on-nullとは異なり、
		// このプラグイン以外の書き込みと衝突しうる）。値が無いことをもって「削除された」と
		// 断定できないため、sortが欠損した場合は既存値を上書きしない
		// （クラスdocblockの「既存Wooデータの無関係な上書きを避けるユーザー方針」を優先する）。

		$meta_tag = Value::array_or_null( $extras['meta_tag'] ?? null );

		if ( null !== $meta_tag ) {
			update_term_meta( $term_id, '_cbjp_meta_tag', wp_json_encode( $meta_tag ) );
		} else {
			delete_term_meta( $term_id, '_cbjp_meta_tag' );
		}

		$image_url = Value::string( $extras['image_url'] ?? null );

		if ( null !== $image_url ) {
			$attachment_id = $this->media->import( $image_url, 0 );

			if ( null !== $attachment_id ) {
				update_term_meta( $term_id, 'thumbnail_id', $attachment_id );
			} else {
				// `MediaImporter::import()`のnull返却契約: 呼び出し側が警告を積む
				// （`ProductWriter::apply_images()`の同種の失敗処理と同じ方針）。
				$warnings[] = WarningCode::with_detail( WarningCode::IMAGE_DOWNLOAD_FAILED, $image_url );
			}
		} else {
			$this->clear_owned_thumbnail( $term_id );
		}

		// 上で個別処理した既知キー（sort/meta_tag/image_url）・descriptionは`write()`で既に
		// term自体の`description`列へ反映済み・remote_idは`_cbjp_remote_id`として別途書く。
		// 残りはProductWriter/OrderWriter/CouponWriter/CustomerWriterと同じ汎用機構
		// `ExtrasMeta::apply_via()`へ委ねる（アーキテクチャ原則1: 将来別ASPが異なるextras構成を
		// 持ってもここでキーを個別ホワイトリスト管理せず、データ欠損を防ぐ）。
		unset( $extras['sort'], $extras['meta_tag'], $extras['image_url'], $extras['description'], $extras['remote_id'] );

		ExtrasMeta::apply_via(
			static fn ( string $meta_key, mixed $value ) => update_term_meta( $term_id, $meta_key, $value ),
			static fn ( string $meta_key ) => delete_term_meta( $term_id, $meta_key ),
			$extras
		);

		update_term_meta( $term_id, '_cbjp_platform', $this->platform );
		update_term_meta( $term_id, '_cbjp_remote_id', $item->remote_id() );

		return $warnings;
	}

	/**
	 * `thumbnail_id`はWoo標準のカテゴリ画像で、店舗がwp-adminから手動設定していることもある。
	 * ASP側でimage_urlが削除/欠損したからといって無条件に削除すると、店舗が手動設定した画像を
	 * 消してしまう（`ProductWriter::apply_images()`がユーザー追加画像を`_cbjp_source_url`の
	 * 有無で保護しているのと同じ理由）。現在設定されている添付が過去にこのプラグインで
	 * 取り込んだもの（`_cbjp_source_url`を持つ）と確認できる場合のみ削除する。
	 */
	private function clear_owned_thumbnail( int $term_id ): void {
		$current_attachment_id = (int) get_term_meta( $term_id, 'thumbnail_id', true );

		if ( 0 === $current_attachment_id ) {
			return;
		}

		// `_cbjp_source_url`だけでなく`_cbjp_platform`が自分自身と一致する場合のみ削除する
		// （`ProductWriter::apply_images()`と同じ理由: D16のリンク再構築ツール等で複数
		// プラットフォームが同一タームを共有しうるため、`_cbjp_source_url`の有無だけで判定すると
		// 他プラットフォームが取り込んだサムネイルを誤って削除してしまう）。
		if ( '' !== get_post_meta( $current_attachment_id, '_cbjp_source_url', true )
			&& PlatformOwnership::owns_post( $current_attachment_id, $this->platform ) ) {
			delete_term_meta( $term_id, 'thumbnail_id' );
		}
	}
}
