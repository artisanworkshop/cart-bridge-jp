<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化されたレビューモデル（MakeShopのみ対応）。
 */
final class CanonicalReview implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param array<string,mixed> $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public readonly string $product_ref,
		public readonly ?string $author_name,
		public readonly ?int $rating,
		public readonly ?string $title,
		public readonly ?string $content,
		public readonly ?string $created_at,
		public readonly array $extras = []
	) {}

	public function to_array(): array {
		return [
			'product_ref' => $this->product_ref,
			'author_name' => $this->author_name,
			'rating'      => $this->rating,
			'title'       => $this->title,
			'content'     => $this->content,
			'created_at'  => $this->created_at,
			'extras'      => $this->extras,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['product_ref'] ?? '' ),
			isset( $data['author_name'] ) ? (string) $data['author_name'] : null,
			isset( $data['rating'] ) ? (int) $data['rating'] : null,
			isset( $data['title'] ) ? (string) $data['title'] : null,
			isset( $data['content'] ) ? (string) $data['content'] : null,
			isset( $data['created_at'] ) ? (string) $data['created_at'] : null,
			(array) ( $data['extras'] ?? [] )
		);
	}
}
