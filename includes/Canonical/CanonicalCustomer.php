<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;
use CartBridgeJP\Canonical\Concerns\RemoteIdFromExtrasTrait;

/**
 * 正規化された顧客モデル。パスワードは両ASPともハッシュ取得不可のため移行対象外。
 */
final readonly class CanonicalCustomer implements CanonicalModel {

	use ChecksumTrait;
	use RemoteIdFromExtrasTrait;

	/**
	 * @param array<string,mixed> $address 郵便番号・都道府県・市区町村・番地等。
	 * @param array<string,mixed> $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public string $email,
		public string $name,
		public ?string $kana,
		public ?string $company,
		public ?string $department,
		public array $address,
		public ?string $phone,
		public ?string $birthday,
		public ?bool $mailmag_opt_in,
		public ?string $note,
		public array $extras = []
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
