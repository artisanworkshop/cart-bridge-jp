<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化された在庫モデル。バリエーション在庫の場合は variant_ref を指定する。
 */
final class CanonicalStock implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param array<string,mixed> $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public readonly string $product_ref,
		public readonly ?string $variant_ref,
		public readonly ?string $sku,
		public readonly ?int $quantity,
		public readonly bool $in_stock,
		public readonly array $extras = []
	) {}

	public function to_array(): array {
		return [
			'product_ref' => $this->product_ref,
			'variant_ref' => $this->variant_ref,
			'sku'         => $this->sku,
			'quantity'    => $this->quantity,
			'in_stock'    => $this->in_stock,
			'extras'      => $this->extras,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['product_ref'] ?? '' ),
			isset( $data['variant_ref'] ) ? (string) $data['variant_ref'] : null,
			isset( $data['sku'] ) ? (string) $data['sku'] : null,
			isset( $data['quantity'] ) ? (int) $data['quantity'] : null,
			(bool) ( $data['in_stock'] ?? true ),
			(array) ( $data['extras'] ?? [] )
		);
	}
}
