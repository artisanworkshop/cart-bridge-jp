<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * 無料版サンプル選定の結果（エクスポート方向。D15/§10.2 #8）。`SampleSet`（ASP remote_id前提）の
 * Woo向け対称形。フィールドはすべてWooローカルID（int）。
 */
final readonly class ExportSampleSet {

	/**
	 * @param array<int,int> $order_ids
	 * @param array<int,int> $product_ids
	 * @param array<int,int> $customer_ids
	 */
	public function __construct(
		public array $order_ids,
		public array $product_ids,
		public array $customer_ids,
		public bool $used_fallback
	) {}

	/**
	 * @return array{order_ids:array<int,int>,product_ids:array<int,int>,customer_ids:array<int,int>,used_fallback:bool}
	 */
	public function to_array(): array {
		return [
			'order_ids'     => $this->order_ids,
			'product_ids'   => $this->product_ids,
			'customer_ids'  => $this->customer_ids,
			'used_fallback' => $this->used_fallback,
		];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			array_values( array_map( 'intval', (array) ( $data['order_ids'] ?? [] ) ) ),
			array_values( array_map( 'intval', (array) ( $data['product_ids'] ?? [] ) ) ),
			array_values( array_map( 'intval', (array) ( $data['customer_ids'] ?? [] ) ) ),
			(bool) ( $data['used_fallback'] ?? false )
		);
	}
}
