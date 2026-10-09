<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities;

/**
 * 警告の店舗向けの説明（dry-run の CSV の `severity`・`message`・`action` 列）。`EntityType::describe_warning()` が返す。
 * `severity` は `Woo\WarningCatalog::SEVERITY_*`。`detail_message` は警告の detail を 1 つ差し込む書式（`%s`。`%` は `%%` と書く）で、
 * detail がある警告ではこちらを使う（空なら `message`）。
 */
final readonly class WarningText {

	public function __construct(
		public string $severity,
		public string $message,
		public string $action = '',
		public string $detail_message = ''
	) {}

	/**
	 * @return array{severity:string,message:string,action:string,detail_message:string}
	 */
	public function to_array(): array {
		return [
			'severity'       => $this->severity,
			'message'        => $this->message,
			'action'         => $this->action,
			'detail_message' => $this->detail_message,
		];
	}
}
