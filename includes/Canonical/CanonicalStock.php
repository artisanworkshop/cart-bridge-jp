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
final readonly class CanonicalStock implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param array<string,mixed> $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public string $product_ref,
		public ?string $variant_ref,
		public ?string $sku,
		public ?int $quantity,
		public bool $in_stock,
		public array $extras = []
	) {}

	public function remote_id(): string {
		return $this->variant_ref ?? $this->product_ref;
	}

	/**
	 * 在庫管理外（`null`）または在庫管理中で数量が正の場合に在庫ありとする共通ルール。
	 * アダプタ・`Sync\Importer`の複数箇所で同一の導出が必要なため、判断基準をここに集約する。
	 */
	public static function is_in_stock( ?int $quantity ): bool {
		return null === $quantity || $quantity > 0;
	}

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
