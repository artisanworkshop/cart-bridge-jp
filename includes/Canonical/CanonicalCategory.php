<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化されたカテゴリモデル。
 */
final class CanonicalCategory implements CanonicalModel {

	use ChecksumTrait;

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly ?string $parent_id,
		public readonly ?string $slug
	) {}

	public function to_array(): array {
		return [
			'id'        => $this->id,
			'name'      => $this->name,
			'parent_id' => $this->parent_id,
			'slug'      => $this->slug,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			isset( $data['parent_id'] ) ? (string) $data['parent_id'] : null,
			isset( $data['slug'] ) ? (string) $data['slug'] : null
		);
	}
}
