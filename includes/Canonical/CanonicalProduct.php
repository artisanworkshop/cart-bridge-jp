<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化された商品モデル。価格は浮動小数点誤差を避けるため文字列で保持する。
 */
final class CanonicalProduct implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param array<int,array<string,mixed>> $images
	 * @param array<int,array<string,mixed>> $variants
	 * @param array<int,array<string,mixed>> $options
	 * @param array<int,string>              $category_refs 連携先カテゴリのremote_id一覧。
	 * @param array<string,mixed>            $extras ASP固有フィールドの退避先。往復移行でのデータ欠損を防ぐ。
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $sku,
		public readonly string $price,
		public readonly ?string $sale_price,
		public readonly ?string $description,
		public readonly array $images,
		public readonly array $variants,
		public readonly array $options,
		public readonly array $category_refs,
		public readonly ?int $stock,
		public readonly string $status,
		public readonly array $extras = []
	) {}

	public function to_array(): array {
		return [
			'name'          => $this->name,
			'sku'           => $this->sku,
			'price'         => $this->price,
			'sale_price'    => $this->sale_price,
			'description'   => $this->description,
			'images'        => $this->images,
			'variants'      => $this->variants,
			'options'       => $this->options,
			'category_refs' => $this->category_refs,
			'stock'         => $this->stock,
			'status'        => $this->status,
			'extras'        => $this->extras,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['name'] ?? '' ),
			isset( $data['sku'] ) ? (string) $data['sku'] : null,
			(string) ( $data['price'] ?? '0' ),
			isset( $data['sale_price'] ) ? (string) $data['sale_price'] : null,
			isset( $data['description'] ) ? (string) $data['description'] : null,
			(array) ( $data['images'] ?? [] ),
			(array) ( $data['variants'] ?? [] ),
			(array) ( $data['options'] ?? [] ),
			(array) ( $data['category_refs'] ?? [] ),
			isset( $data['stock'] ) ? (int) $data['stock'] : null,
			(string) ( $data['status'] ?? 'draft' ),
			(array) ( $data['extras'] ?? [] )
		);
	}
}
