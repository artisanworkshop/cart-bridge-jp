<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化された受注モデル。金額はASP側の値をそのまま保持し、Wooに再計算させない（D10）。
 */
final readonly class CanonicalOrder implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param array<int,array<string,mixed>> $line_items 明細（sku, remote_product_id, name, price, quantity 等）。
	 * @param array<string,mixed>            $shipping 配送方法・送料・配送先住所。
	 * @param array<string,mixed>            $payment 決済方法・手数料。
	 * @param array<string,mixed>            $totals subtotal/shipping_fee/discount/tax/total。
	 * @param array<string,mixed>            $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public string $number,
		public string $status,
		public ?string $customer_ref,
		public array $line_items,
		public array $shipping,
		public array $payment,
		public array $totals,
		public string $placed_at,
		public ?string $note,
		public array $extras = []
	) {}

	public function remote_id(): string {
		return $this->number;
	}

	public function to_array(): array {
		return [
			'number'       => $this->number,
			'status'       => $this->status,
			'customer_ref' => $this->customer_ref,
			'line_items'   => $this->line_items,
			'shipping'     => $this->shipping,
			'payment'      => $this->payment,
			'totals'       => $this->totals,
			'placed_at'    => $this->placed_at,
			'note'         => $this->note,
			'extras'       => $this->extras,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['number'] ?? '' ),
			(string) ( $data['status'] ?? '' ),
			isset( $data['customer_ref'] ) ? (string) $data['customer_ref'] : null,
			(array) ( $data['line_items'] ?? [] ),
			(array) ( $data['shipping'] ?? [] ),
			(array) ( $data['payment'] ?? [] ),
			(array) ( $data['totals'] ?? [] ),
			(string) ( $data['placed_at'] ?? '' ),
			isset( $data['note'] ) ? (string) $data['note'] : null,
			(array) ( $data['extras'] ?? [] )
		);
	}
}
