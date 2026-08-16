<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;
use CartBridgeJP\Canonical\Concerns\RemoteIdFromExtrasTrait;

/**
 * 正規化された商品モデル。価格は浮動小数点誤差を避けるため文字列で保持する。
 */
final readonly class CanonicalProduct implements CanonicalModel {

	use ChecksumTrait;
	use RemoteIdFromExtrasTrait;

	/**
	 * @param array<int,array<string,mixed>> $images
	 * @param array<int,array<string,mixed>> $variants
	 * @param array<int,array<string,mixed>> $options
	 * @param array<int,string>              $category_refs 連携先カテゴリのremote_id一覧。
	 * @param array<string,mixed>            $extras ASP固有フィールドの退避先。往復移行でのデータ欠損を防ぐ。
	 */
	public function __construct(
		public string $name,
		public ?string $sku,
		public string $price,
		public ?string $sale_price,
		public ?string $description,
		public array $images,
		public array $variants,
		public array $options,
		public array $category_refs,
		public ?int $stock,
		public string $status,
		public bool $requires_shipping = true,
		public array $extras = []
	) {}

	public function to_array(): array {
		return [
			'name'              => $this->name,
			'sku'               => $this->sku,
			'price'             => $this->price,
			'sale_price'        => $this->sale_price,
			'description'       => $this->description,
			'images'            => $this->images,
			'variants'          => $this->variants,
			'options'           => $this->options,
			'category_refs'     => $this->category_refs,
			'stock'             => $this->stock,
			'status'            => $this->status,
			'requires_shipping' => $this->requires_shipping,
			'extras'            => $this->extras,
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
			(bool) ( $data['requires_shipping'] ?? true ),
			(array) ( $data['extras'] ?? [] )
		);
	}
}
