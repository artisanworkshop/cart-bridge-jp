<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化されたタグモデル（カラーミーの「グループ」に相当）。
 */
final readonly class CanonicalTag implements CanonicalModel {

	use ChecksumTrait;

	public function __construct(
		public string $id,
		public string $name
	) {}

	public function remote_id(): ?string {
		return $this->id;
	}

	public function to_array(): array {
		return [
			'id'   => $this->id,
			'name' => $this->name,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['name'] ?? '' )
		);
	}
}
