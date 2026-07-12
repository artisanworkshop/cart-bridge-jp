<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;
use CartBridgeJP\Canonical\Concerns\RemoteIdFromExtrasTrait;

/**
 * 正規化されたレビューモデル（MakeShopのみ対応）。
 */
final readonly class CanonicalReview implements CanonicalModel {

	use ChecksumTrait;
	use RemoteIdFromExtrasTrait;

	/**
	 * @param array<string,mixed> $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public string $product_ref,
		public ?string $author_name,
		public ?int $rating,
		public ?string $title,
		public ?string $content,
		public ?string $created_at,
		public array $extras = []
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
