<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化された顧客モデル。パスワードは両ASPともハッシュ取得不可のため移行対象外。
 */
final class CanonicalCustomer implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param array<string,mixed> $address 郵便番号・都道府県・市区町村・番地等。
	 * @param array<string,mixed> $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public readonly string $email,
		public readonly string $name,
		public readonly ?string $kana,
		public readonly ?string $company,
		public readonly ?string $department,
		public readonly array $address,
		public readonly ?string $phone,
		public readonly ?string $birthday,
		public readonly ?bool $mailmag_opt_in,
		public readonly ?string $note,
		public readonly array $extras = []
	) {}

	public function to_array(): array {
		return [
			'email'          => $this->email,
			'name'           => $this->name,
			'kana'           => $this->kana,
			'company'        => $this->company,
			'department'     => $this->department,
			'address'        => $this->address,
			'phone'          => $this->phone,
			'birthday'       => $this->birthday,
			'mailmag_opt_in' => $this->mailmag_opt_in,
			'note'           => $this->note,
			'extras'         => $this->extras,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['email'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			isset( $data['kana'] ) ? (string) $data['kana'] : null,
			isset( $data['company'] ) ? (string) $data['company'] : null,
			isset( $data['department'] ) ? (string) $data['department'] : null,
			(array) ( $data['address'] ?? [] ),
			isset( $data['phone'] ) ? (string) $data['phone'] : null,
			isset( $data['birthday'] ) ? (string) $data['birthday'] : null,
			isset( $data['mailmag_opt_in'] ) ? (bool) $data['mailmag_opt_in'] : null,
			isset( $data['note'] ) ? (string) $data['note'] : null,
			(array) ( $data['extras'] ?? [] )
		);
	}
}
