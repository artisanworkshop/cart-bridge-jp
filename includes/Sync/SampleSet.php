<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * 無料版サンプル選定の結果（D15/§10.2）。
 */
final class SampleSet {

	/**
	 * @param array<int,string> $order_remote_ids
	 * @param array<int,string> $product_remote_ids
	 * @param array<int,string> $customer_refs
	 */
	public function __construct(
		public readonly array $order_remote_ids,
		public readonly array $product_remote_ids,
		public readonly array $customer_refs,
		public readonly bool $used_fallback
	) {}

	/**
	 * @return array{order_remote_ids:array<int,string>,product_remote_ids:array<int,string>,customer_refs:array<int,string>,used_fallback:bool}
	 */
	public function to_array(): array {
		return [
			'order_remote_ids'   => $this->order_remote_ids,
			'product_remote_ids' => $this->product_remote_ids,
			'customer_refs'      => $this->customer_refs,
			'used_fallback'      => $this->used_fallback,
		];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			array_values( array_map( 'strval', (array) ( $data['order_remote_ids'] ?? [] ) ) ),
			array_values( array_map( 'strval', (array) ( $data['product_remote_ids'] ?? [] ) ) ),
			array_values( array_map( 'strval', (array) ( $data['customer_refs'] ?? [] ) ) ),
			(bool) ( $data['used_fallback'] ?? false )
		);
	}
}
