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
final readonly class CanonicalCategory implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param array<string,mixed> $extras ASP固有フィールド（説明文・画像URL等）の退避先。
	 */
	public function __construct(
		public string $id,
		public string $name,
		public ?string $parent_id,
		public ?string $slug,
		public array $extras = []
	) {}

	public function remote_id(): string {
		return $this->id;
	}

	public function to_array(): array {
		return [
			'id'        => $this->id,
			'name'      => $this->name,
			'parent_id' => $this->parent_id,
			'slug'      => $this->slug,
			'extras'    => $this->extras,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			isset( $data['parent_id'] ) ? (string) $data['parent_id'] : null,
			isset( $data['slug'] ) ? (string) $data['slug'] : null,
			(array) ( $data['extras'] ?? [] )
		);
	}
}
