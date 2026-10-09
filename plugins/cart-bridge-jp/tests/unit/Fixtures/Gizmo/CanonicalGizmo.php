<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures\Gizmo;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * テスト用の外部の実体（R3-6b1。`GizmoType` が登録する）。名前に `pending` を含むと Writer が未解決の警告を付ける。
 */
final readonly class CanonicalGizmo implements CanonicalModel {

	use ChecksumTrait;

	public function __construct(
		public string $id,
		public string $name,
		public int $amount
	) {}

	public function remote_id(): string {
		return $this->id;
	}

	public function to_array(): array {
		return [
			'id'     => $this->id,
			'name'   => $this->name,
			'amount' => $this->amount,
		];
	}

	public static function from_array( array $data ): self {
		return new self( (string) ( $data['id'] ?? '' ), (string) ( $data['name'] ?? '' ), (int) ( $data['amount'] ?? 0 ) );
	}
}
