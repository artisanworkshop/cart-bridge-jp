<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Entities\EntityTypeRegistry;

/**
 * D25「実体は作られた向きにだけ更新する」の判定（`docs/03-design-decisions.md` §10.2「往復の扱い（D25）」）。
 *
 * `cbjp_mappings`には向きの列が無い（スキーマは変えない）ため、取込みの全 writer が作成・更新・メールでの採用のたびに書く
 * `_cbjp_platform`を「このプラットフォームとの紐づけは取込みが作った」印として使う。エクスポートは Woo に何も書かないので、
 * 取込みがエクスポートで結ばれた実体に書かなくなった（このクラスの判定で止める）以後は、mapping のある実体は「取込みで結ばれた」
 * 「エクスポートで結ばれた」のどちらか一方になる。判定は「誰が作ったか」ではなく「誰が紐づけたか」: メールで採用した既存の
 * Woo 顧客は、紐づけたのが取込みなので取込み側（取込みは毎回その顧客を更新する）。
 *
 * エクスポートの Reader（送らない判定）とインポート側のガード（上書きしない判定）は同じ関数を使い、同じ事実を 1 か所で判定する。
 * 顧客（ユーザー）・受注の判定は `CommerceOrigin`（R3-6c1 でこのクラスから分けた）。
 */
final class EntityOrigin {

	/**
	 * 取込みが書く紐づけのメタ。WooCommerce の商品の複製（`WC_Admin_Duplicate_Product::product_duplicate()`）は既定でメタを
	 * すべて写すため、`exclude_link_meta_on_duplicate()`で複製から外す。
	 *
	 * @var array<int,string>
	 */
	public const LINK_META_KEYS = [ '_cbjp_platform', '_cbjp_remote_id' ];

	private function __construct() {}

	/**
	 * `woocommerce_duplicate_product_exclude_meta`のコールバック（`Core\Plugin::boot()`で登録）。店舗が取り込んだ商品を複製して作った
	 * 商品は Woo で作った新しい実体で、ASP とは結ばれていない。紐づけのメタを写すと、複製が「取り込んだ実体」と判定されて黙って
	 * エクスポートされず（D25）、リンク再構築（`Woo\Tools\MappingRebuilder`）が同じ remote_id で複製側に mapping を付け替えうる。
	 * WooCommerce は同じ一覧をバリエーションの複製にも使う（WC 11 の実ソースで確認）。
	 *
	 * @param mixed $exclude_meta 先に登録された他のコールバックの戻り値（信頼境界。配列でなければ空として扱う）。
	 * @return array<int|string,mixed>
	 */
	public static function exclude_link_meta_on_duplicate( $exclude_meta ): array {
		if ( ! is_array( $exclude_meta ) ) {
			$exclude_meta = [];
		}

		return array_merge( $exclude_meta, self::LINK_META_KEYS );
	}

	/**
	 * 商品・バリエーション・クーポン（投稿）が`$platform`からの取込みで結ばれているか。
	 */
	public static function post_linked_by_import( int $post_id, string $platform ): bool {
		return '' !== $platform && PlatformOwnership::owns_post( $post_id, $platform );
	}

	/**
	 * インポート側のガード（`Woo\WooRepository`/`Woo\DryRunRepository`が writer を呼ぶ前）: この書込みを止めて紐づけだけを保つか。
	 * mapping（`$existing_local_id`）があり、その実体がエクスポートで結ばれているときだけ真（対象のエンティティは`is_linked_by_export()`）。
	 */
	public static function blocks_import( string $platform, string $entity, ?int $existing_local_id ): bool {
		return null !== $existing_local_id && self::is_linked_by_export( $platform, $entity, $existing_local_id );
	}

	/**
	 * mapping が指す Woo の実体がエクスポートで結ばれた（実体があり、取込みで結ばれていない）か。真なら取込みで上書きしない。
	 *
	 * 判定は実体の種類（`Entities\EntityType::is_linked_by_export()`。R3-6b1）が持つ。判定するのはエクスポートの Reader がある種類のうち、
	 * mapping の local_id が実体そのものを指す商品・顧客・受注・クーポンだけ（それ以外・登録の無い種類は偽）。在庫は対象を商品・バリエーションの mapping で解決する（在庫の mapping が無くても届く）ため`Woo\Writer\StockWriter`が
	 * 解決した対象で判定する。カテゴリ・タグ・レビューはエクスポートしない。
	 *
	 * 「実体がある」は、各 writer が既存 ID を信用せず作り直す（stale-ID のフォールバック）判定と同じ条件にする。ずれると、
	 * 削除済みの実体を指す mapping が作り直されないまま残る。実体が無い・保護ロールの顧客（取込みが印を書かない。
	 * `CustomerWriter::write()`）は偽を返し、従来どおり各 writer に任せる。
	 */
	public static function is_linked_by_export( string $platform, string $entity, int $local_id ): bool {
		if ( '' === $platform ) {
			return false;
		}

		$type = EntityTypeRegistry::get( $entity );

		return null !== $type && $type->is_linked_by_export( $platform, $local_id );
	}

	/**
	 * `ProductWriter::prepare()`の`wc_get_product_object()`が例外を投げない（＝既存の商品として読める）条件と同じ:
	 * 投稿があり、投稿タイプが`product`か`product_variation`。
	 */
	public static function is_product_post( int $post_id ): bool {
		$post = get_post( $post_id );

		return null !== $post && in_array( $post->post_type, [ 'product', 'product_variation' ], true );
	}
}
