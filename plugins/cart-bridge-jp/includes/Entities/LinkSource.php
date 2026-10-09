<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities;

/**
 * リンク再構築（`Woo\Tools\MappingRebuilder`。D16）が走査する Woo 側の実体の 1 種類。`EntityType::link_sources()` が返す
 * （商品の種類は product と variant の 2 つ）。取込みが Woo の実体に書く `_cbjp_platform`・`_cbjp_remote_id` などのメタから
 * mapping を復元する。
 *
 * **互換方針**: Pro・外部コードはこのクラスを継承する（interface にしない。メソッドを足すと実装側が fatal になるため。D20）。
 */
abstract class LinkSource {

	/**
	 * `cbjp_mappings.entity_type`（復元する mapping の種類）。リンク再構築のカーソル・件数のキーにもなる。
	 */
	abstract public function key(): string;

	/**
	 * 画面に出す名前（呼ばれるたびに翻訳する）。
	 */
	abstract public function label(): string;

	/**
	 * 走査順（小さいほど先）。無料版は category 10・tag 20・product 30・variant 40、顧客・受注・クーポンは coupon 50・customer 60・order 70。
	 */
	abstract public function position(): int;

	/**
	 * `$offset` から最大 `$limit` 件を走査する。`scanned` はクエリが返した件数（カーソル用。所有権で除いた分を含む）、
	 * `rows` は所有権を確かめた local_id => remote_id（不明は ''）。
	 *
	 * @return array{scanned:int,rows:array<int,string>}
	 */
	abstract public function scan( string $platform, int $offset, int $limit ): array;

	/**
	 * 所有メタ（`_cbjp_platform`）で絞り込む `meta_query`。
	 *
	 * @return array<int,array<string,string>>
	 */
	protected static function ownership_meta_query( string $platform ): array {
		return [
			[
				'key'     => '_cbjp_platform',
				'value'   => $platform,
				'compare' => '=',
			],
		];
	}

	/**
	 * メタの値を remote_id の文字列にする（スカラーでなければ ''）。
	 */
	protected static function meta_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
